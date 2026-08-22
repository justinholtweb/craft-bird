<?php

namespace justinholtweb\bird\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\models\LineItem;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use justinholtweb\bird\exceptions\MappingException;
use justinholtweb\bird\helpers\Money;
use justinholtweb\bird\models\Settings;
use justinholtweb\bird\models\TaxTreatment;
use justinholtweb\bird\Plugin;

/**
 * Orders as Moneybird documents.
 *
 * **`buildPayload()` is the only place an order becomes Moneybird JSON.** The push, the console
 * command and the CP's "Preview" button all go through it, so what a merchant is shown before
 * booking is byte-identical to what gets booked.
 *
 * The arithmetic is deliberately backwards from how a shop thinks about an order. Rather than
 * re-pricing anything, every line is reduced to *what was actually charged* — a net amount and the
 * VAT that sat on top of it — and the percentage is derived from those two numbers. That way a
 * discount, a sale price, a per-item shipping charge or a tax rule Bird has never heard of all
 * arrive at Moneybird as the money that changed hands.
 */
class Invoices extends Component
{
    /**
     * Moneybird stores a line's quantity and unit price and multiplies them back out. A quantity
     * of 3 at €9.99 has to survive that round trip to the cent, so unit prices carry four decimals.
     */
    public const PRICE_DECIMALS = 4;

    /**
     * The complete document payload for an order.
     *
     * @return array{
     *     type: string,
     *     attributes: array<string, mixed>,
     *     treatment: string,
     *     pricesIncludeTax: bool,
     *     expectedTotal: float,
     *     orderTotal: float,
     *     rounding: float,
     *     warnings: string[],
     * }
     * @throws MappingException
     */
    public function buildPayload(Order $order, ?string $contactId = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $vat = Plugin::getInstance()->getVat();

        $treatment = $vat->treatmentForOrder($order);
        $country = $vat->countryForOrder($order);
        $pricesIncludeTax = $order->getTotalTaxIncluded() > 0.001;

        // Each entry is a detail plus the percentage the rate it was matched to represents.
        // The percentage never goes on the wire — Moneybird owns the arithmetic — but Bird needs
        // it to predict the total the invoice will come to.
        $rows = [];
        $warnings = [];

        foreach ($order->getLineItems() as $item) {
            $rows[] = $this->buildLineItemRow($item, $treatment, $country, $pricesIncludeTax);
        }

        foreach ($this->buildOrderLevelRows($order, $treatment, $country, $pricesIncludeTax) as $row) {
            $rows[] = $row;
        }

        if ($rows === []) {
            throw new MappingException(Craft::t('bird', 'Order {reference} has nothing on it to invoice.', [
                'reference' => $this->referenceFor($order),
            ]));
        }

        // Moneybird totals the document itself. Bird models that arithmetic here so a mismatch is
        // caught before the invoice exists rather than found in a bank reconciliation later.
        $expectedTotal = $this->expectedTotal($rows, $pricesIncludeTax);
        $orderTotal = round($order->getTotalPrice(), 2);
        $rounding = round($orderTotal - $expectedTotal, 2);

        if (abs($rounding) >= 0.01) {
            if (!$settings->reconcileTotals) {
                throw new MappingException(Craft::t('bird', 'The invoice for order {reference} would total {invoice} but the order was {order}. Turn on “Reconcile totals” to book the difference as a rounding line.', [
                    'reference' => $this->referenceFor($order),
                    'invoice' => Money::amount($expectedTotal),
                    'order' => Money::amount($orderTotal),
                ]));
            }

            $rows[] = $this->buildRoundingRow($rounding, $treatment);
            $warnings[] = Craft::t('bird', 'Booked a rounding line of {amount}.', ['amount' => Money::amount($rounding)]);
        }

        $details = [];

        foreach ($rows as $index => $row) {
            $details[] = $row['detail'] + ['row_order' => $index];
        }

        $attributes = $this->documentAttributes($order, $contactId, $pricesIncludeTax, $details);

        return [
            'type' => $settings->documentType,
            'attributes' => $attributes,
            'treatment' => $treatment,
            'pricesIncludeTax' => $pricesIncludeTax,
            'expectedTotal' => $expectedTotal + $rounding,
            'orderTotal' => $orderTotal,
            'rounding' => $rounding,
            'warnings' => $warnings,
        ];
    }

    /**
     * The credit-note payload for a refund.
     *
     * A credit note is an ordinary sales invoice with negative amounts — that is Moneybird's own
     * model, and it is what keeps the VAT return correct: the tax comes back off the same rate it
     * went on at.
     *
     * @return array{type: string, attributes: array<string, mixed>, treatment: string}
     * @throws MappingException
     */
    public function buildCreditPayload(Order $order, float $amount, string $reference, ?string $contactId = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $vat = Plugin::getInstance()->getVat();

        $treatment = $vat->treatmentForOrder($order);
        $country = $vat->countryForOrder($order);
        $pricesIncludeTax = $order->getTotalTaxIncluded() > 0.001;

        // The refund is credited at the order's own blended VAT rate: refunding €121 of a 21%
        // order has to hand back €21 of VAT, not €0 and not 21% of €121.
        $orderTax = round($order->getTotalTax() + $order->getTotalTaxIncluded(), 2);
        $orderNet = round($order->getTotalPrice() - $orderTax, 2);
        $rate = $this->resolveRate($treatment, $orderTax, $orderNet, $country);
        $percentage = $rate['percentage'];

        $gross = round(abs($amount), 2);
        $net = $pricesIncludeTax ? $gross : round($gross / (1 + ($percentage / 100)), 2);

        $detail = [
            'description' => Craft::t('bird', 'Refund for order {reference}', ['reference' => $this->referenceFor($order)]),
            'price' => Money::amount(-$net, self::PRICE_DECIMALS),
            'amount' => '1',
            'tax_rate_id' => $rate['id'],
            'row_order' => 0,
        ];

        $ledgerAccountId = $settings->defaultLedgerAccountId;

        if ($ledgerAccountId !== '') {
            $detail['ledger_account_id'] = $ledgerAccountId;
        }

        $attributes = $this->documentAttributes($order, $contactId, $pricesIncludeTax, [$detail]);
        $attributes['reference'] = $reference;

        return [
            'type' => $settings->documentType,
            'attributes' => $attributes,
            'treatment' => $treatment,
        ];
    }

    /**
     * The reference Moneybird files the document under — the shop's order number, and the string
     * Bird searches on when it needs to find an invoice it lost track of.
     */
    public function referenceFor(Order $order): string
    {
        $settings = Plugin::getInstance()->getSettings();

        $value = match ($settings->referenceSource) {
            'number' => $order->number,
            'shortNumber' => $order->getShortNumber(),
            'id' => (string)$order->id,
            default => $order->reference ?: $order->getShortNumber(),
        };

        return (string)($value ?: $order->number ?: $order->id);
    }

    /**
     * The date the document carries.
     */
    public function invoiceDateFor(Order $order): DateTime
    {
        $settings = Plugin::getInstance()->getSettings();

        $date = match ($settings->invoiceDateSource) {
            'paid' => $order->datePaid ?? $order->dateOrdered,
            'today' => null,
            default => $order->dateOrdered ?? $order->datePaid,
        };

        // Dates go over as plain `Y-m-d` in the site's own time zone: an order placed at 00:30 in
        // Amsterdam must not book to the previous day because UTC says so.
        $timezone = new DateTimeZone(Craft::$app->getTimeZone());

        if ($date === null) {
            return new DateTime('now', $timezone);
        }

        return (clone $date)->setTimezone($timezone);
    }

    // Private
    // =========================================================================

    /**
     * @param array<int, array<string, mixed>> $details
     * @return array<string, mixed>
     */
    private function documentAttributes(Order $order, ?string $contactId, bool $pricesIncludeTax, array $details): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $invoiceDate = $this->invoiceDateFor($order);
        $reference = $this->referenceFor($order);

        $attributes = [
            'reference' => $reference,
            'currency' => strtoupper((string)($order->currency ?: 'EUR')),
            'prices_are_incl_tax' => $pricesIncludeTax,
            'details_attributes' => $details,
        ];

        if ($contactId !== null) {
            $attributes['contact_id'] = $contactId;
        }

        if ($settings->documentType === Settings::DOCUMENT_EXTERNAL_SALES_INVOICE) {
            $attributes['date'] = $invoiceDate->format('Y-m-d');
            $attributes['due_date'] = (clone $invoiceDate)
                ->modify('+' . max(0, $settings->firstDueInterval) . ' days')
                ->format('Y-m-d');
            $attributes['source'] = 'Craft Commerce';

            // The link back is only useful to somebody who can log into the control panel, but it
            // is exactly that person who ends up reconciling the books.
            if ($order->id) {
                $attributes['source_url'] = UrlHelper::cpUrl('commerce/orders/' . $order->id);
            }

            return $attributes;
        }

        $attributes['invoice_date'] = $invoiceDate->format('Y-m-d');
        $attributes['first_due_interval'] = max(0, $settings->firstDueInterval);

        if ($settings->workflowId !== '') {
            $attributes['workflow_id'] = $settings->workflowId;
        }

        if ($settings->documentStyleId !== '') {
            $attributes['document_style_id'] = $settings->documentStyleId;
        }

        return $attributes;
    }

    /**
     * @return array{detail: array<string, mixed>, percentage: float}
     * @throws MappingException
     */
    private function buildLineItemRow(LineItem $item, string $treatment, ?string $country, bool $pricesIncludeTax): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $qty = (float)$item->qty;
        $gross = round($item->getTotal(), 2);
        $tax = round($item->getTax() + $item->getTaxIncluded(), 2);
        $net = round($gross - $tax, 2);

        $rate = $this->resolveRate($treatment, $tax, $net, $country);

        $detail = [
            'description' => $this->describeLineItem($item),
            'price' => Money::unitPrice($pricesIncludeTax ? $gross : $net, $qty),
            'amount' => $this->formatQuantity($qty),
            'tax_rate_id' => $rate['id'],
        ];

        $ledgerAccountId = $settings->ledgerAccountIdForProductType($this->productTypeHandle($item))
            ?? ($settings->defaultLedgerAccountId ?: null);

        if ($ledgerAccountId !== null && $ledgerAccountId !== '') {
            $detail['ledger_account_id'] = $ledgerAccountId;
        }

        return ['detail' => $detail, 'percentage' => $rate['percentage']];
    }

    /**
     * Shipping, cart-level discounts and anything else a plugin hung on the order itself.
     *
     * Order-level tax cannot be attributed to any one of them, so it is spread across the lot at
     * one blended percentage. In practice the only order-level line that carries tax is shipping,
     * which makes the blend exact; when it is not, the rounding line catches the remainder.
     *
     * @return array<int, array{detail: array<string, mixed>, percentage: float}>
     * @throws MappingException
     */
    private function buildOrderLevelRows(Order $order, string $treatment, ?string $country, bool $pricesIncludeTax): array
    {
        /** @var array<string, float> $buckets */
        $buckets = [];
        $includedTax = 0.0;
        $addedTax = 0.0;

        foreach ($order->getAdjustments() ?? [] as $adjustment) {
            if (!$adjustment instanceof OrderAdjustment || $adjustment->getLineItem() !== null) {
                continue;
            }

            if ($adjustment->type === 'tax') {
                if ($adjustment->included) {
                    $includedTax += (float)$adjustment->amount;
                } else {
                    $addedTax += (float)$adjustment->amount;
                }

                continue;
            }

            // An included non-tax adjustment is already inside a line item's price; adding it
            // again would invoice the customer twice for the same shipping.
            if ($adjustment->included) {
                continue;
            }

            $label = $this->describeAdjustment($adjustment);
            $buckets[$label] = ($buckets[$label] ?? 0.0) + (float)$adjustment->amount;
        }

        $buckets = array_filter($buckets, static fn(float $amount) => abs($amount) >= 0.005);

        if ($buckets === []) {
            return [];
        }

        // Commerce records an *added* tax as its own adjustment and an *included* tax as a marker
        // sitting inside the amount it is included in. So a bucket is net when the tax was added
        // and gross when it was included, and only one of those needs unwinding.
        $includedTax = round($includedTax, 2);
        $addedTax = round($addedTax, 2);
        $bucketSum = round(array_sum($buckets), 2);
        $netBase = round($bucketSum - $includedTax, 2);
        $rate = $this->resolveRate($treatment, $includedTax + $addedTax, $netBase, $country);

        $ratio = $bucketSum != 0.0 ? $netBase / $bucketSum : 1.0;
        $rows = [];

        foreach ($buckets as $label => $gross) {
            $gross = round($gross, 2);
            $net = round($gross * $ratio, 2);

            $rows[] = [
                'detail' => array_filter([
                    'description' => $label,
                    'price' => Money::amount($pricesIncludeTax ? $gross : $net, self::PRICE_DECIMALS),
                    'amount' => '1',
                    'tax_rate_id' => $rate['id'],
                    'ledger_account_id' => $this->ledgerAccountForBucket($label, $gross) ?: null,
                ], static fn($value) => $value !== null),
                'percentage' => $rate['percentage'],
            ];
        }

        return $rows;
    }

    /**
     * @return array{detail: array<string, mixed>, percentage: float}
     * @throws MappingException
     */
    private function buildRoundingRow(float $amount, string $treatment): array
    {
        $settings = Plugin::getInstance()->getSettings();

        // The rounding line is booked at 0%. The grand total is what the customer's bank will
        // show and what has to reconcile; a cent of VAT-rounding drift belongs on a line that
        // does not pretend to be revenue.
        $zeroTreatment = $treatment === TaxTreatment::EU_REVERSE_CHARGE || $treatment === TaxTreatment::EXPORT
            ? $treatment
            : TaxTreatment::DOMESTIC;

        $taxRateId = $this->resolveRate($zeroTreatment, 0.0, 0.0, null)['id'];

        $detail = [
            'description' => Craft::t('bird', 'Rounding'),
            'price' => Money::amount($amount, self::PRICE_DECIMALS),
            'amount' => '1',
            'tax_rate_id' => $taxRateId,
        ];

        if ($settings->defaultLedgerAccountId !== '') {
            $detail['ledger_account_id'] = $settings->defaultLedgerAccountId;
        }

        return ['detail' => $detail, 'percentage' => 0.0];
    }

    /**
     * @return array{id: string, percentage: float}
     * @throws MappingException
     */
    private function resolveRate(string $treatment, float $tax, float $net, ?string $country): array
    {
        $vat = Plugin::getInstance()->getVat();
        $rate = $vat->matchRate($treatment, $tax, $net, $country);

        if ($rate === null) {
            throw new MappingException($vat->describeMissingRate($treatment, $vat->percentageFor($tax, $net), $country));
        }

        return $rate;
    }

    /**
     * What Moneybird would total this document at.
     *
     * Tax is worked out per tax rate rather than per line, because that is what Moneybird does —
     * its response carries one `tax_totals` entry per rate, not one per row.
     *
     * @param array<int, array{detail: array<string, mixed>, percentage: float}> $rows
     */
    private function expectedTotal(array $rows, bool $pricesIncludeTax): float
    {
        $net = 0.0;

        foreach ($rows as $row) {
            $net += (float)$row['detail']['price'] * (float)$row['detail']['amount'];
        }

        if ($pricesIncludeTax) {
            // The tax is already inside every price, so the sum *is* the total.
            return round($net, 2);
        }

        $byRate = [];

        foreach ($rows as $row) {
            $rateId = (string)($row['detail']['tax_rate_id'] ?? '');
            $line = (float)$row['detail']['price'] * (float)$row['detail']['amount'];

            $byRate[$rateId]['base'] = ($byRate[$rateId]['base'] ?? 0.0) + $line;
            $byRate[$rateId]['percentage'] = $row['percentage'];
        }

        $tax = 0.0;

        foreach ($byRate as $group) {
            $tax += round($group['base'] * ($group['percentage'] / 100), 2);
        }

        return round($net + $tax, 2);
    }

    private function describeLineItem(LineItem $item): string
    {
        $description = trim($item->getDescription());
        $sku = trim($item->getSku());

        if ($description === '') {
            $description = $sku !== '' ? $sku : Craft::t('bird', 'Item');
        }

        if ($sku !== '' && $sku !== $description) {
            $description .= " ($sku)";
        }

        if (trim($item->note) !== '') {
            $description .= ' — ' . trim($item->note);
        }

        // Moneybird's description column is generous but not infinite, and a runaway product
        // title should not be the reason an invoice 422s.
        return mb_substr($description, 0, 500);
    }

    private function describeAdjustment(OrderAdjustment $adjustment): string
    {
        $name = trim($adjustment->name ?: '');

        if ($name === '') {
            $name = match ($adjustment->type) {
                'shipping' => Craft::t('bird', 'Shipping'),
                'discount' => Craft::t('bird', 'Discount'),
                default => ucfirst($adjustment->type),
            };
        }

        $description = trim((string)$adjustment->description);

        if ($description !== '' && $description !== $name) {
            $name .= " ($description)";
        }

        return mb_substr($name, 0, 500);
    }

    private function ledgerAccountForBucket(string $label, float $amount): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        // Negative order-level money is a discount however it was labelled.
        if ($amount < 0 && $settings->discountLedgerAccountId !== '') {
            return $settings->discountLedgerAccountId;
        }

        if ($amount >= 0 && $settings->shippingLedgerAccountId !== '') {
            return $settings->shippingLedgerAccountId;
        }

        return $settings->defaultLedgerAccountId ?: null;
    }

    private function productTypeHandle(LineItem $item): ?string
    {
        try {
            $purchasable = $item->getPurchasable();

            if ($purchasable instanceof \craft\commerce\elements\Variant) {
                return $purchasable->getProduct()?->getType()?->handle;
            }
        } catch (\Throwable) {
            // A purchasable from another plugin, or one that has since been deleted. The default
            // ledger account covers it.
        }

        return null;
    }

    /**
     * Moneybird takes `1`, `5` or `0.5`; it does not take `5.0000`.
     */
    private function formatQuantity(float $qty): string
    {
        if (floor($qty) == $qty) {
            return (string)(int)$qty;
        }

        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }
}
