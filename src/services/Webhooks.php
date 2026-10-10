<?php

namespace justinholtweb\bird\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\bird\db\Table;
use justinholtweb\bird\exceptions\ApiException;
use justinholtweb\bird\helpers\Secret;
use justinholtweb\bird\models\Document;
use justinholtweb\bird\models\LogEntry;
use justinholtweb\bird\Plugin;

/**
 * Moneybird talking back.
 *
 * Bird pushes invoices; Moneybird is where they get *paid* — matched against a bank feed, marked
 * paid by hand, written off. Without the webhook the shop's own view of "paid" is whatever the
 * payment gateway said at checkout, which misses every bank transfer.
 *
 * @see https://developer.moneybird.com/webhooks/verifying-signatures
 */
class Webhooks extends Component
{
    /**
     * The header Moneybird signs with: `t=<unix>,v1=<hex hmac>`, possibly several `v1` values
     * while a secret is being rotated.
     */
    public const SIGNATURE_HEADER = 'Moneybird-Signature';

    /**
     * How far the signed timestamp may be from now. Moneybird's own guidance is five minutes.
     */
    public const TOLERANCE_SECONDS = 300;

    /**
     * The events Bird asks for. Deliberately narrow: every event Moneybird sends is a request
     * Craft has to serve, and Bird only acts on a document changing state.
     *
     * @var string[]
     */
    public const EVENTS = [
        'sales_invoice_state_changed_to_paid',
        'sales_invoice_state_changed_to_late',
        'sales_invoice_state_changed_to_uncollectible',
        'external_sales_invoice_state_changed_to_paid',
        'external_sales_invoice_state_changed_to_late',
    ];

    /**
     * The webhook Bird installed on this environment, with its secret decrypted, or null.
     *
     * `secret` is null when it can't be decrypted — Craft's security key changed — which means
     * removing the webhook and installing it again.
     *
     * @return array{webhookId: string, secret: ?string}|null
     */
    public function installed(): ?array
    {
        $row = (new Query())->select(['webhookId', 'secret'])->from(Table::WEBHOOK)->orderBy(['id' => SORT_DESC])->one();

        return $row ? ['webhookId' => (string)$row['webhookId'], 'secret' => Secret::decrypt($row['secret'] ?? null)] : null;
    }

    /**
     * The secret a delivery is verified with: the installed webhook's, else the `webhookSecret`
     * setting — an `$ENV` reference for a webhook made outside Bird, or a literal stored before 5.0.2.
     */
    public function signingSecret(): string
    {
        $installed = $this->installed();

        if ($installed !== null && ($installed['secret'] ?? '') !== '') {
            return (string)$installed['secret'];
        }

        return Plugin::getInstance()->getSettings()->getParsedWebhookSecret();
    }

    /**
     * Whether deliveries are accepted at all: a webhook Bird installed here, or the "Accept
     * webhooks" setting for one made by hand. Installing no longer flips that setting, because it
     * is project config and installing happens on the live site.
     */
    public function isAccepting(): bool
    {
        return $this->installed() !== null || Plugin::getInstance()->getSettings()->webhooksEnabled;
    }

    /**
     * The installed webhook's id, falling back to the pre-5.0.2 setting.
     */
    public function webhookId(): string
    {
        return $this->installed()['webhookId'] ?? trim(Plugin::getInstance()->getSettings()->webhookId);
    }

    /**
     * Register the webhook with Moneybird and remember its id and secret.
     *
     * @return array{success: bool, message: string, secret?: string}
     */
    public function install(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->isConfigured()) {
            return ['success' => false, 'message' => Craft::t('bird', 'Connect to Moneybird first.')];
        }

        $url = $settings->getWebhookUrl();

        if (!str_starts_with($url, 'https://')) {
            return [
                'success' => false,
                'message' => Craft::t('bird', 'Moneybird only posts to HTTPS URLs. This site resolves to {url}.', ['url' => $url]),
            ];
        }

        try {
            $response = $plugin->getApi()->createWebhook($url, self::EVENTS);
        } catch (ApiException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $webhookId = (string)($response['id'] ?? '');
        // Moneybird returns the signing secret exactly once, here. Losing it means deleting the
        // webhook and making a new one, so it is stored immediately — encrypted, in Bird's own
        // table. Before 5.0.2 it went into the plugin settings, and so into project config and git,
        // where anyone with the repository could sign a "paid" webhook.
        $secret = (string)($response['secret'] ?? '');
        $this->store($webhookId, $secret);

        return [
            'success' => true,
            'message' => Craft::t('bird', 'Moneybird will now tell this site when an invoice is paid.'),
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function remove(): array
    {
        $plugin = Plugin::getInstance();
        $webhookId = $this->webhookId();

        if ($webhookId === '') {
            return ['success' => false, 'message' => Craft::t('bird', 'No webhook is installed.')];
        }

        try {
            $plugin->getApi()->deleteWebhook($webhookId);
        } catch (ApiException $e) {
            // A webhook deleted in Moneybird's own UI is already gone; forgetting it locally is
            // the right outcome either way.
            if ($e->statusCode !== 404) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
        }

        Craft::$app->getDb()->createCommand()->delete(Table::WEBHOOK)->execute();

        return ['success' => true, 'message' => Craft::t('bird', 'Webhook removed.')];
    }

    /**
     * Whether a request really came from Moneybird.
     *
     * The signed payload is `"{timestamp}.{raw body}"` — the *raw* body, exactly as received. A
     * re-encoded array would produce different bytes and never verify.
     */
    public function verify(string $rawBody, string $signatureHeader, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$key, $value] = $pair;

            if ($key === 't') {
                $timestamp = trim($value);
            } elseif ($key === 'v1') {
                $signatures[] = trim($value);
            }
        }

        if ($timestamp === null || !ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        // The timestamp is inside the signed payload, so it cannot be moved without breaking the
        // signature — which is what makes checking it worth anything against a replay.
        $now = $now ?? time();

        if (abs($now - (int)$timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Act on a verified webhook payload.
     *
     * @param array<string, mixed> $payload
     * @return array{handled: bool, message: string}
     */
    public function handle(array $payload): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $administrationId = (string)($payload['administration_id'] ?? '');

        if ($administrationId !== '' && $administrationId !== $settings->getParsedAdministrationId()) {
            return ['handled' => false, 'message' => Craft::t('bird', 'Ignored an event for another administration.')];
        }

        $entityType = (string)($payload['entity_type'] ?? '');

        if (!in_array($entityType, ['SalesInvoice', 'ExternalSalesInvoice'], true)) {
            return ['handled' => false, 'message' => Craft::t('bird', 'Ignored a {type} event.', ['type' => $entityType ?: '?'])];
        }

        $entityId = (string)($payload['entity_id'] ?? '');
        $document = $entityId !== '' ? $plugin->getDocuments()->getDocumentByMoneybirdId($entityId) : null;

        if ($document === null) {
            // An invoice Bird did not create — a merchant invoicing by hand in the same
            // administration. Nothing to do, and nothing wrong.
            return ['handled' => false, 'message' => Craft::t('bird', 'No local document for Moneybird invoice {id}.', ['id' => $entityId])];
        }

        $entity = is_array($payload['entity'] ?? null) ? $payload['entity'] : [];
        $state = (string)($entity['state'] ?? $payload['state'] ?? '');

        if ($state !== '') {
            $document->state = $state;
        }

        if (isset($entity['total_paid'])) {
            $document->totalPaid = (float)$entity['total_paid'];
        }

        if (!empty($entity['paid_at'])) {
            $document->datePaid = DateTimeHelper::toDateTime($entity['paid_at']) ?: null;
        }

        if (!empty($entity['invoice_id'])) {
            $document->invoiceNumber = (string)$entity['invoice_id'];
        }

        $document->dateSynced = new DateTime();
        $plugin->getDocuments()->save($document);

        $message = Craft::t('bird', '{label} is now {state}.', [
            'label' => $document->getLabel(),
            'state' => $document->getStateLabel(),
        ]);

        if ($document->getIsPaid() && !$document->getIsCredit()) {
            $message .= ' ' . $this->applyPaidStatus($document);
        }

        $plugin->getLog()->write('webhook', [
            'level' => LogEntry::LEVEL_INFO,
            'orderId' => $document->orderId,
            'summary' => $message,
        ]);

        return ['handled' => true, 'message' => $message];
    }

    // Private
    // =========================================================================

    /**
     * Move the Commerce order to the status the merchant nominated for "paid in Moneybird".
     */
    private function applyPaidStatus(Document $document): string
    {
        $handle = trim(Plugin::getInstance()->getSettings()->paidOrderStatusHandle);

        if ($handle === '' || !Plugin::commerceIsReady()) {
            return '';
        }

        $order = Order::find()->id($document->orderId)->status(null)->one();

        if (!$order instanceof Order) {
            return '';
        }

        $status = Commerce::getInstance()->getOrderStatuses()->getOrderStatusByHandle($handle, $order->storeId);

        if ($status === null || $order->orderStatusId === $status->id) {
            return '';
        }

        $order->orderStatusId = $status->id;
        $order->message = Craft::t('bird', 'Moneybird reported invoice {label} paid.', ['label' => $document->getLabel()]);

        if (!Craft::$app->getElements()->saveElement($order)) {
            return Craft::t('bird', 'Could not move the order to {status}.', ['status' => $status->name]);
        }

        return Craft::t('bird', 'Moved the order to {status}.', ['status' => $status->name]);
    }

    private function store(string $webhookId, string $secret): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTime());

        $db->transaction(static function() use ($db, $webhookId, $secret, $now) {
            $db->createCommand()->delete(Table::WEBHOOK)->execute();
            $db->createCommand()->insert(Table::WEBHOOK, [
                'webhookId' => $webhookId,
                'secret' => Secret::encrypt($secret),
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        });
    }
}
