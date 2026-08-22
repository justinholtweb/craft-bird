<?php

namespace justinholtweb\bird\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use DateTime;
use DateTimeZone;
use justinholtweb\bird\exceptions\ApiException;
use justinholtweb\bird\helpers\Money;
use justinholtweb\bird\models\Document;
use justinholtweb\bird\models\LogEntry;
use justinholtweb\bird\models\Settings;
use justinholtweb\bird\Plugin;

/**
 * Payments against booked documents.
 *
 * A webshop invoice is paid before it exists: the customer's card cleared, then Bird booked the
 * invoice. Registering the payment in the same breath is what stops a merchant's Moneybird filling
 * up with hundreds of "open" invoices that were all settled weeks ago.
 */
class Payments extends Component
{
    /**
     * Book the order's payment against a document.
     *
     * @param float|null $amount Defaults to whatever the order has been paid, capped at the
     *                           document total: over-paying an invoice in Moneybird creates a
     *                           credit balance on the contact, which is somebody's afternoon.
     * @throws ApiException
     */
    public function registerFor(Document $document, Order $order, ?float $amount = null): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$document->getIsBooked()) {
            return false;
        }

        $amount = $amount ?? min(round($order->getTotalPaid(), 2), round($document->total, 2));
        $amount = round($amount, 2);

        if (abs($amount) < 0.01) {
            return false;
        }

        $payload = [
            'payment_date' => $this->paymentDateFor($order)->format('Y-m-d'),
            'price' => Money::amount($amount),
        ];

        if ($settings->financialAccountId !== '') {
            $payload['financial_account_id'] = $settings->financialAccountId;
        }

        // The gateway's own reference is what a bank feed will match on later, so it goes over
        // whenever Commerce has one.
        $reference = $this->gatewayReferenceFor($order);

        if ($reference !== null) {
            $payload['transaction_identifier'] = mb_substr($reference, 0, 255);
        }

        $api = Plugin::getInstance()->getApi();

        if ($document->documentType === Settings::DOCUMENT_EXTERNAL_SALES_INVOICE) {
            $api->createExternalSalesInvoicePayment($document->moneybirdId, $payload);
        } else {
            $api->createSalesInvoicePayment($document->moneybirdId, $payload);
        }

        $document->totalPaid = round($document->totalPaid + $amount, 2);

        if (Money::equal($document->totalPaid, $document->total) || $document->totalPaid > $document->total) {
            $document->state = 'paid';
            $document->datePaid = new DateTime();
        }

        Plugin::getInstance()->getDocuments()->save($document);

        Plugin::getInstance()->getLog()->write('payment', [
            'level' => LogEntry::LEVEL_INFO,
            'orderId' => $order->id,
            'summary' => Craft::t('bird', 'Registered a payment of {amount} on {document}', [
                'amount' => Money::amount($amount),
                'document' => $document->getLabel(),
            ]),
        ]);

        return true;
    }

    /**
     * The date the money arrived, in the site's own time zone.
     */
    public function paymentDateFor(Order $order): DateTime
    {
        $timezone = new DateTimeZone(Craft::$app->getTimeZone());
        $date = $order->datePaid ?? $order->dateOrdered;

        if ($date === null) {
            return new DateTime('now', $timezone);
        }

        return (clone $date)->setTimezone($timezone);
    }

    /**
     * The payment gateway's own reference for the order, if any transaction carries one.
     */
    public function gatewayReferenceFor(Order $order): ?string
    {
        foreach ($order->getTransactions() as $transaction) {
            if (!in_array($transaction->type, ['purchase', 'capture'], true)) {
                continue;
            }

            if ($transaction->status !== 'success') {
                continue;
            }

            $reference = trim((string)$transaction->reference);

            if ($reference !== '') {
                return $reference;
            }
        }

        return null;
    }
}
