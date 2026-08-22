<?php

namespace justinholtweb\bird\services;

use craft\base\Component;
use craft\commerce\elements\Order;
use craft\elements\Address;
use justinholtweb\bird\helpers\Eu;
use justinholtweb\bird\models\TaxTreatment;
use justinholtweb\bird\Plugin;

/**
 * Which Moneybird tax rate a line belongs on, and why.
 *
 * The rule Bird follows is that **Commerce decides what to charge and Bird decides where to book
 * it**. Commerce's tax engine already knows the shop's zones, its rates, and its VAT-number
 * validators; re-deriving all of that here would give a shop two answers that drift apart, and the
 * one that ends up on the customer's card is Commerce's.
 *
 * What Moneybird needs on top of the number is the *reason*, because 0% is not one rate over
 * there: "reverse charged to the customer", "exported outside the EU" and "genuinely zero-rated"
 * are three different tax rates that print three different sentences on the invoice and land in
 * three different boxes on a VAT return.
 */
class Vat extends Component
{
    /**
     * The VAT treatment for an order.
     */
    public function treatmentForOrder(Order $order): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $country = $this->countryForOrder($order);

        if ($country === null) {
            return TaxTreatment::UNKNOWN;
        }

        if ($country === $settings->getHomeCountry()) {
            return TaxTreatment::DOMESTIC;
        }

        if (!Eu::isMemberState($country)) {
            return TaxTreatment::EXPORT;
        }

        $vatNumber = $this->vatNumberForOrder($order);

        // Reverse charge is a claim about what was charged, not about what could have been: a
        // VAT number on an order that still paid 21% is a merchant who never turned Commerce's
        // VAT-number validator on, and booking that as reverse-charged would understate the
        // return by the exact amount the customer actually paid.
        if ($vatNumber !== null
            && Eu::vatNumberMatchesCountry($vatNumber, $country)
            && !$this->orderChargedTax($order)
        ) {
            return TaxTreatment::EU_REVERSE_CHARGE;
        }

        return $settings->ossEnabled && Plugin::getInstance()->isPro()
            ? TaxTreatment::EU_OSS
            : TaxTreatment::EU_HOME_RATE;
    }

    /**
     * The country the sale is taxed against.
     */
    public function countryForOrder(Order $order): ?string
    {
        $address = $this->addressForOrder($order);
        $country = $address?->countryCode;

        return $country ? strtoupper($country) : null;
    }

    /**
     * The customer's VAT number, normalised, or null.
     *
     * Craft 5 gives every address an `organizationTaxId`, which is where Commerce's own VAT
     * validators read from — so that is the default. A shop collecting it somewhere else names
     * the field in the settings instead.
     */
    public function vatNumberForOrder(Order $order): ?string
    {
        $handle = trim(Plugin::getInstance()->getSettings()->vatNumberSource);

        if ($handle === '') {
            return null;
        }

        foreach ([$this->addressForOrder($order), $order->getBillingAddress(), $order->getShippingAddress()] as $address) {
            if ($address === null) {
                continue;
            }

            $value = $this->readField($address, $handle);

            if ($value !== null) {
                return Eu::normalizeVatNumber($value);
            }
        }

        // Some shops ask for the VAT number on the order itself rather than on an address.
        $value = $this->readField($order, $handle);

        return $value !== null ? Eu::normalizeVatNumber($value) : null;
    }

    /**
     * The Moneybird tax rate for one line, chosen from what was actually charged.
     *
     * Deliberately *not* a look-up on a derived percentage. Commerce rounds tax to the cent per
     * line, so €10.10 taxed at 21% records €2.12 — which divides back out as 20.99%, a rate no
     * shop has ever configured. Matching on the money instead of on the arithmetic means the
     * mapped 21% wins by a tenth of a cent, and the leftover lands on the rounding line where it
     * belongs.
     *
     * @return array{id: string, percentage: float}|null
     */
    public function matchRate(string $treatment, float $tax, float $net, ?string $country = null): ?array
    {
        $tax = round($tax, 2);

        if (abs($tax) < 0.005) {
            $id = $this->taxRateIdFor($treatment, 0.0, $country);

            return $id === null ? null : ['id' => $id, 'percentage' => 0.0];
        }

        // A cent, or half a percent of the tax on a bigger line. Anything further out is not
        // rounding, it is a rate the merchant has not mapped.
        $tolerance = max(0.01, abs($tax) * 0.005);

        $best = null;
        $bestDiff = null;

        foreach ($this->candidates($treatment, $country) as [$percentage, $id]) {
            if ($percentage <= 0) {
                continue;
            }

            $diff = abs(round($net * ($percentage / 100), 2) - $tax);

            if ($bestDiff === null || $diff < $bestDiff) {
                $best = ['id' => $id, 'percentage' => $percentage];
                $bestDiff = $diff;
            }
        }

        if ($best === null || $bestDiff > $tolerance) {
            return null;
        }

        return $best;
    }

    /**
     * The Moneybird tax rate id for a plain percentage, or null when the merchant has not mapped
     * it.
     *
     * Returning null rather than guessing is deliberate: an invoice booked against the wrong tax
     * rate is worse than an invoice that did not get booked, because nobody goes looking for it.
     */
    public function taxRateIdFor(string $treatment, float $percentage, ?string $countryCode = null): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($percentage > 0) {
            if ($treatment === TaxTreatment::EU_OSS && $countryCode !== null) {
                $ossRate = $settings->ossTaxRateIdFor($countryCode, $percentage);

                if ($ossRate !== null) {
                    return $ossRate;
                }
            }

            return $settings->taxRateIdForPercentage($percentage);
        }

        $special = match ($treatment) {
            TaxTreatment::EU_REVERSE_CHARGE => $settings->reverseChargeTaxRateId,
            TaxTreatment::EXPORT => $settings->exportTaxRateId,
            default => '',
        };

        if ($special !== '') {
            return $special;
        }

        return $settings->taxRateIdForPercentage(0);
    }

    /**
     * Every rate the merchant has mapped that could apply here, best candidate first.
     *
     * @return array<int, array{0: float, 1: string}>
     */
    private function candidates(string $treatment, ?string $country): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $out = [];

        // A country's own OSS rate is tried before the general map, so a German 19% books against
        // the German rate rather than whichever domestic rate happens to be 19% too.
        if ($treatment === TaxTreatment::EU_OSS && $country !== null) {
            foreach ($settings->getOssTaxRateMap() as $key => $id) {
                $parts = explode(':', $key, 2);

                if (count($parts) !== 2 || strtoupper($parts[0]) !== strtoupper($country)) {
                    continue;
                }

                $out[] = [(float)$parts[1], $id];
            }
        }

        foreach ($settings->getTaxRateMap() as $percentage => $id) {
            $out[] = [(float)$percentage, $id];
        }

        return $out;
    }

    /**
     * A human-readable explanation of what could not be mapped, for the error the merchant sees.
     */
    public function describeMissingRate(string $treatment, float $percentage, ?string $countryCode): string
    {
        if ($percentage > 0) {
            if ($treatment === TaxTreatment::EU_OSS && $countryCode !== null) {
                return \Craft::t('bird', 'No Moneybird tax rate is mapped for {percentage}% in {country}. Add it to the OSS table in Bird’s settings, or map {percentage}% in the main tax table.', [
                    'percentage' => $this->formatPercentage($percentage),
                    'country' => $countryCode,
                ]);
            }

            return \Craft::t('bird', 'No Moneybird tax rate is mapped for {percentage}%. Add it to the tax table in Bird’s settings.', [
                'percentage' => $this->formatPercentage($percentage),
            ]);
        }

        return match ($treatment) {
            TaxTreatment::EU_REVERSE_CHARGE => \Craft::t('bird', 'No Moneybird tax rate is set for reverse-charged VAT. Pick the 0% rate that prints “btw verlegd” in Bird’s settings.'),
            TaxTreatment::EXPORT => \Craft::t('bird', 'No Moneybird tax rate is set for exports outside the EU. Pick the 0% export rate in Bird’s settings.'),
            default => \Craft::t('bird', 'No Moneybird tax rate is mapped for 0%. Add it to the tax table in Bird’s settings.'),
        };
    }

    /**
     * The effective VAT percentage of a line, derived from what was charged.
     *
     * Rounded to two decimals so that 20.999999999 lands on 21 — Moneybird's rates are named
     * percentages, and a rate that misses by a billionth matches nothing.
     */
    public function percentageFor(float $tax, float $net): float
    {
        if ($net == 0.0 || $tax == 0.0) {
            return 0.0;
        }

        return round(($tax / $net) * 100, 2);
    }

    public function formatPercentage(float $percentage): string
    {
        return rtrim(rtrim(number_format($percentage, 2, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Everything the CP panel shows about an order's VAT position.
     *
     * @return array{treatment: string, label: string, country: string|null, vatNumber: string|null, reverseCharge: bool}
     */
    public function describeOrder(Order $order): array
    {
        $treatment = $this->treatmentForOrder($order);

        return [
            'treatment' => $treatment,
            'label' => TaxTreatment::label($treatment),
            'country' => $this->countryForOrder($order),
            'vatNumber' => $this->vatNumberForOrder($order),
            'reverseCharge' => $treatment === TaxTreatment::EU_REVERSE_CHARGE,
        ];
    }

    // Private
    // =========================================================================

    /**
     * The address VAT is decided against: whichever the merchant configured, falling back to the
     * other one. An order with only a shipping address must not be treated as address-less.
     */
    private function addressForOrder(Order $order): ?Address
    {
        $prefersBilling = Plugin::getInstance()->getSettings()->contactAddressSource !== 'shipping';

        if ($prefersBilling) {
            return $order->getBillingAddress() ?? $order->getShippingAddress();
        }

        return $order->getShippingAddress() ?? $order->getBillingAddress();
    }

    private function orderChargedTax(Order $order): bool
    {
        return ($order->getTotalTax() + $order->getTotalTaxIncluded()) > 0.001;
    }

    /**
     * Read a native attribute or a custom field, without caring which it is.
     */
    private function readField(mixed $element, string $handle): ?string
    {
        try {
            if (isset($element->$handle)) {
                $value = $element->$handle;

                if (is_scalar($value) && (string)$value !== '') {
                    return (string)$value;
                }
            }

            if (method_exists($element, 'getFieldValue')) {
                $value = $element->getFieldValue($handle);

                if (is_scalar($value) && (string)$value !== '') {
                    return (string)$value;
                }
            }
        } catch (\Throwable) {
            // An unknown handle is a configuration mistake, not a reason to stop invoicing.
        }

        return null;
    }
}
