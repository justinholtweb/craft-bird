<?php

namespace justinholtweb\bird\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\helpers\DateTimeHelper;
use craft\helpers\Queue;
use DateTime;
use justinholtweb\bird\exceptions\ApiException;
use justinholtweb\bird\exceptions\MappingException;
use justinholtweb\bird\jobs\PushOrderJob;
use justinholtweb\bird\models\Document;
use justinholtweb\bird\models\LogEntry;
use justinholtweb\bird\models\Settings;
use justinholtweb\bird\models\SyncResult;
use justinholtweb\bird\Plugin;

/**
 * Getting orders into Moneybird, and keeping the two ends agreeing about it.
 *
 * **`pushOrder()` is the only place a document is created.** The order-complete handler, the queue
 * job, the CP button, the console command and the backfill all arrive here, so the decisions that
 * must not be made twice — is this order already booked, should it be skipped, was the attempt
 * worth retrying — are made once and cannot disagree with each other.
 */
class Sync extends Component
{
    /**
     * Whether Bird could push anything at all right now.
     */
    public function isReady(): bool
    {
        return Plugin::commerceIsReady() && Plugin::getInstance()->getSettings()->isConfigured();
    }

    /**
     * React to a Commerce event, if the merchant asked for this one.
     *
     * Deliberately swallows everything: an accounting integration must never be the reason a
     * customer's checkout 500s, and an order that fails to book can be retried from the CP, the
     * queue or a console command. An order that fails to *complete* cannot be retried at all.
     */
    public function handleTrigger(Order $order, string $event): void
    {
        try {
            $settings = Plugin::getInstance()->getSettings();

            if ($settings->trigger !== $event) {
                return;
            }

            if ($event === Settings::TRIGGER_STATUS) {
                $handle = $order->getOrderStatus()?->handle;

                if ($handle === null || $handle !== $settings->triggerStatusHandle) {
                    return;
                }
            }

            $this->dispatch($order);
        } catch (\Throwable $e) {
            Craft::error('Bird could not handle the ' . $event . ' trigger: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Push now, or queue it, depending on the settings.
     */
    public function dispatch(Order $order): void
    {
        if (!$this->isReady() || !$order->id) {
            return;
        }

        if (!Plugin::getInstance()->getSettings()->queueSync) {
            $this->pushOrder($order);

            return;
        }

        Queue::push(new PushOrderJob(['orderId' => $order->id]));
    }

    /**
     * Book one order.
     */
    public function pushOrder(Order $order, bool $force = false): SyncResult
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $documents = $plugin->getDocuments();

        if (!$settings->isConfigured()) {
            return SyncResult::fail(Craft::t('bird', 'Bird has no Moneybird API token and administration yet.'));
        }

        if (!$order->id) {
            return SyncResult::fail(Craft::t('bird', 'The order has not been saved yet.'));
        }

        if (!$order->isCompleted) {
            return SyncResult::skip(Craft::t('bird', 'The order is still a cart.'));
        }

        $document = $documents->getDocumentForOrder($order->id);

        if ($document !== null && $document->getIsBooked() && !$force) {
            return SyncResult::skip(Craft::t('bird', 'Already booked as {label}.', ['label' => $document->getLabel()]), $document);
        }

        if ($settings->skipZeroTotalOrders && abs(round($order->getTotalPrice(), 2)) < 0.01) {
            return SyncResult::skip(Craft::t('bird', 'The order total is zero.'), $document);
        }

        $isRetry = $document !== null;

        $document ??= new Document([
            'orderId' => $order->id,
            'kind' => Document::KIND_INVOICE,
            'sourceKey' => '',
        ]);

        $document->documentType = $settings->documentType;
        $document->reference = $plugin->getInvoices()->referenceFor($order);
        $document->currency = strtoupper((string)($order->currency ?: 'EUR'));
        $document->administrationId = $settings->getParsedAdministrationId();
        $document->attempts++;

        try {
            $contactId = $plugin->getContacts()->contactIdForOrder($order) ?: ($settings->fallbackContactId ?: null);

            if ($contactId === null) {
                throw new MappingException(Craft::t('bird', 'Moneybird needs a contact to invoice. Turn contact syncing on, or set a fallback contact in Bird’s settings.'));
            }

            $payload = $plugin->getInvoices()->buildPayload($order, $contactId);
            $document->taxTreatment = $payload['treatment'];
            $document->total = $payload['expectedTotal'];

            // Recovery. A previous attempt may have created the invoice and then died before the
            // row was written — the network is allowed to fail *between* those two things. The
            // reference is the order number, so the invoice can be found and adopted rather than
            // booked a second time. Only on a retry: a first attempt has nothing to recover.
            if ($isRetry && !$force && $settings->documentType === Settings::DOCUMENT_SALES_INVOICE) {
                $existing = $plugin->getApi()->findSalesInvoiceByReference((string)$document->reference);

                if ($existing !== null && isset($existing['id'])) {
                    $this->applyResponse($document, $existing, $order);
                    $document->lastError = null;
                    $documents->save($document);

                    $this->log('invoice', LogEntry::LEVEL_WARNING, $order->id, Craft::t('bird', 'Adopted the invoice {label} that Moneybird already had for this order.', [
                        'label' => $document->getLabel(),
                    ]));

                    return SyncResult::ok(Craft::t('bird', 'Recovered the existing invoice {label}.', ['label' => $document->getLabel()]), $document);
                }
            }

            $response = $settings->documentType === Settings::DOCUMENT_EXTERNAL_SALES_INVOICE
                ? $plugin->getApi()->createExternalSalesInvoice($payload['attributes'])
                : $plugin->getApi()->createSalesInvoice($payload['attributes']);

            $this->applyResponse($document, $response, $order);
            $document->lastError = null;
            $documents->save($document);

            $this->maybeSend($document, $order);
            $this->maybeRegisterPayment($document, $order);

            $message = Craft::t('bird', 'Booked {label} in Moneybird.', ['label' => $document->getLabel()]);

            foreach ($payload['warnings'] as $warning) {
                $message .= ' ' . $warning;
            }

            $this->log('invoice', LogEntry::LEVEL_INFO, $order->id, $message);

            return SyncResult::ok($message, $document);
        } catch (MappingException $e) {
            return $this->recordFailure($document, $order, $e->getMessage(), false);
        } catch (ApiException $e) {
            return $this->recordFailure($document, $order, $e->getMessage(), $e->isRetryable());
        } catch (\Throwable $e) {
            Craft::error('Bird failed to push order ' . $order->id . ': ' . $e->getMessage(), __METHOD__);

            return $this->recordFailure($document, $order, $e->getMessage(), false);
        }
    }

    /**
     * Credit anything the shop has refunded since the invoice was booked (Pro).
     *
     * One credit note per Commerce refund transaction, keyed on the transaction hash, so a
     * second run credits nothing twice.
     *
     * @return SyncResult[]
     */
    public function pushRefunds(Order $order): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$plugin->isPro() || !$settings->creditRefunds || !$order->id) {
            return [];
        }

        $documents = $plugin->getDocuments();
        $invoice = $documents->getDocumentForOrder($order->id);

        if ($invoice === null || !$invoice->getIsBooked()) {
            return [];
        }

        $results = [];
        $index = 0;

        foreach ($order->getTransactions() as $transaction) {
            if ($transaction->type !== 'refund' || $transaction->status !== 'success') {
                continue;
            }

            $index++;
            $amount = round((float)$transaction->amount, 2);

            if (abs($amount) < 0.01) {
                continue;
            }

            $sourceKey = mb_substr((string)($transaction->hash ?: 'txn-' . $transaction->id), 0, 64);
            $credit = $documents->getDocumentForOrder($order->id, Document::KIND_CREDIT, $sourceKey);

            if ($credit !== null && $credit->getIsBooked()) {
                continue;
            }

            $credit ??= new Document([
                'orderId' => $order->id,
                'kind' => Document::KIND_CREDIT,
                'sourceKey' => $sourceKey,
            ]);

            $credit->documentType = $settings->documentType;
            $credit->currency = $invoice->currency;
            $credit->administrationId = $settings->getParsedAdministrationId();
            $credit->attempts++;

            $reference = $invoice->reference . '-R' . $index;
            $credit->reference = $reference;

            try {
                $contactId = $plugin->getContacts()->contactIdForOrder($order) ?: ($settings->fallbackContactId ?: null);
                $payload = $plugin->getInvoices()->buildCreditPayload($order, $amount, $reference, $contactId);
                $credit->taxTreatment = $payload['treatment'];

                $response = $settings->documentType === Settings::DOCUMENT_EXTERNAL_SALES_INVOICE
                    ? $plugin->getApi()->createExternalSalesInvoice($payload['attributes'])
                    : $plugin->getApi()->createSalesInvoice($payload['attributes']);

                $this->applyResponse($credit, $response, $order);
                $credit->total = -abs(round($amount, 2));
                $credit->lastError = null;
                $documents->save($credit);

                // A credit note is settled by a negative payment, which is how the money leaving
                // the bank account and the VAT coming off the return stay in step.
                if ($settings->registerPayments) {
                    $plugin->getPayments()->registerFor($credit, $order, -abs($amount));
                }

                $message = Craft::t('bird', 'Credited {amount} against {label}.', [
                    'amount' => $amount,
                    'label' => $invoice->getLabel(),
                ]);

                $this->log('credit', LogEntry::LEVEL_INFO, $order->id, $message);
                $results[] = SyncResult::ok($message, $credit);
            } catch (MappingException $e) {
                $results[] = $this->recordFailure($credit, $order, $e->getMessage(), false, 'credit');
            } catch (ApiException $e) {
                $results[] = $this->recordFailure($credit, $order, $e->getMessage(), $e->isRetryable(), 'credit');
            } catch (\Throwable $e) {
                $results[] = $this->recordFailure($credit, $order, $e->getMessage(), false, 'credit');
            }
        }

        return $results;
    }

    /**
     * Re-read a document from Moneybird, so a hand-edited invoice or a payment matched in the
     * bank feed shows up in the CP.
     */
    public function refreshDocument(Document $document): SyncResult
    {
        if (!$document->getIsBooked()) {
            return SyncResult::skip(Craft::t('bird', 'Nothing has been booked for this order yet.'), $document);
        }

        try {
            $response = $document->documentType === Settings::DOCUMENT_EXTERNAL_SALES_INVOICE
                ? Plugin::getInstance()->getApi()->getExternalSalesInvoice($document->moneybirdId)
                : Plugin::getInstance()->getApi()->getSalesInvoice($document->moneybirdId);

            $this->applyResponse($document, $response, null);
            Plugin::getInstance()->getDocuments()->save($document);

            return SyncResult::ok(Craft::t('bird', 'Refreshed {label}: {state}.', [
                'label' => $document->getLabel(),
                'state' => $document->getStateLabel(),
            ]), $document);
        } catch (ApiException $e) {
            return SyncResult::fail($e->getMessage(), $e->isRetryable(), $document);
        }
    }

    /**
     * Retry the documents that failed and have attempts left.
     *
     * @return SyncResult[]
     */
    public function retryFailed(int $limit = 50): array
    {
        $plugin = Plugin::getInstance();
        $maxAttempts = max(1, $plugin->getSettings()->maxAttempts);
        $results = [];

        foreach ($plugin->getDocuments()->getRetryable($maxAttempts, $limit) as $document) {
            if ($document->getIsCredit()) {
                continue;
            }

            $order = Order::find()->id($document->orderId)->status(null)->one();

            if (!$order instanceof Order) {
                continue;
            }

            $results[] = $this->pushOrder($order);
        }

        return $results;
    }

    /**
     * Orders that ought to be in Moneybird and are not.
     *
     * @return Order[]
     */
    public function findUnbookedOrders(?DateTime $since = null, int $limit = 100): array
    {
        if (!Plugin::commerceIsReady()) {
            return [];
        }

        $query = Order::find()
            ->isCompleted(true)
            ->status(null)
            ->orderBy(['dateOrdered' => SORT_ASC])
            ->limit(null);

        if (Plugin::getInstance()->getSettings()->trigger === Settings::TRIGGER_PAID) {
            $query->isPaid(true);
        }

        if ($since !== null) {
            $query->dateOrdered('>= ' . $since->format('Y-m-d H:i:s'));
        }

        $documents = Plugin::getInstance()->getDocuments();
        $out = [];

        foreach ($query->all() as $order) {
            /** @var Order $order */
            $document = $documents->getDocumentForOrder((int)$order->id);

            if ($document !== null && $document->getIsBooked()) {
                continue;
            }

            $out[] = $order;

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    // Private
    // =========================================================================

    /**
     * @param array<string, mixed> $response
     */
    private function applyResponse(Document $document, array $response, ?Order $order): void
    {
        $document->moneybirdId = isset($response['id']) ? (string)$response['id'] : $document->moneybirdId;

        if (array_key_exists('invoice_id', $response) && $response['invoice_id'] !== null) {
            $document->invoiceNumber = (string)$response['invoice_id'];
        }

        if (!empty($response['state'])) {
            $document->state = (string)$response['state'];
        } elseif ($document->state === Document::STATE_PENDING || $document->state === Document::STATE_FAILED) {
            // External sales invoices come back without a state on create.
            $document->state = 'new';
        }

        if (isset($response['currency'])) {
            $document->currency = (string)$response['currency'];
        }

        if (isset($response['total_price_incl_tax'])) {
            $document->total = (float)$response['total_price_incl_tax'];
        }

        if (isset($response['total_paid'])) {
            $document->totalPaid = (float)$response['total_paid'];
        }

        // `payment_url` is the page a customer can pay on; `url` is the same document without the
        // payment affordance. Either is safe to hand out, neither is the CP link.
        $publicUrl = $response['payment_url'] ?? $response['url'] ?? null;

        if (is_string($publicUrl) && $publicUrl !== '') {
            $document->publicUrl = $publicUrl;
        }

        $document->dateSent = $this->toDate($response['sent_at'] ?? null) ?? $document->dateSent;
        $document->datePaid = $this->toDate($response['paid_at'] ?? null) ?? $document->datePaid;
        $document->dateSynced = new DateTime();

        if ($order !== null && $document->reference === null) {
            $document->reference = Plugin::getInstance()->getInvoices()->referenceFor($order);
        }
    }

    private function maybeSend(Document $document, Order $order): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$plugin->isPro()
            || !$settings->sendInvoice
            || $settings->documentType !== Settings::DOCUMENT_SALES_INVOICE
            || !$document->getIsBooked()
        ) {
            return;
        }

        $sending = ['delivery_method' => $settings->sendDeliveryMethod];

        if ($settings->emailMessage !== '') {
            $sending['email_message'] = $settings->emailMessage;
        }

        $email = $order->getEmail();

        if ($email) {
            $sending['email_address'] = $email;
        }

        try {
            $response = $plugin->getApi()->sendSalesInvoice($document->moneybirdId, $sending);
            $this->applyResponse($document, $response, $order);
            $plugin->getDocuments()->save($document);
        } catch (ApiException $e) {
            // The invoice exists and is correct; only the sending failed. Booking it again would
            // be worse than leaving it for the merchant to send by hand.
            $this->log('invoice', LogEntry::LEVEL_WARNING, $order->id, Craft::t('bird', 'Booked {label}, but Moneybird would not send it: {error}', [
                'label' => $document->getLabel(),
                'error' => $e->getMessage(),
            ]));
        }
    }

    private function maybeRegisterPayment(Document $document, Order $order): void
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->registerPayments || !$order->getIsPaid()) {
            return;
        }

        try {
            $plugin->getPayments()->registerFor($document, $order);
        } catch (ApiException $e) {
            $this->log('payment', LogEntry::LEVEL_WARNING, $order->id, Craft::t('bird', 'Booked {label}, but the payment would not register: {error}', [
                'label' => $document->getLabel(),
                'error' => $e->getMessage(),
            ]));
        }
    }

    private function recordFailure(Document $document, Order $order, string $message, bool $retryable, string $action = 'invoice'): SyncResult
    {
        $document->state = Document::STATE_FAILED;
        $document->lastError = mb_substr($message, 0, 2000);

        try {
            Plugin::getInstance()->getDocuments()->save($document);
        } catch (\Throwable $e) {
            Craft::error('Bird could not record a failed push: ' . $e->getMessage(), __METHOD__);
        }

        $this->log($action, LogEntry::LEVEL_ERROR, $order->id, $message);

        return SyncResult::fail($message, $retryable, $document);
    }

    private function log(string $action, string $level, ?int $orderId, string $summary): void
    {
        Plugin::getInstance()->getLog()->write($action, [
            'level' => $level,
            'orderId' => $orderId,
            'summary' => $summary,
        ]);
    }

    private function toDate(mixed $value): ?DateTime
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        try {
            return DateTimeHelper::toDateTime($value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
