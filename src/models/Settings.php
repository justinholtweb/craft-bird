<?php

namespace justinholtweb\bird\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use justinholtweb\bird\Plugin;

/**
 * Bird settings.
 *
 * Nothing here is ever marked `required`. Craft validates plugin settings wholesale, so a single
 * required attribute means a fresh install cannot save the settings screen at all — including the
 * API token the merchant came to the screen to enter.
 */
class Settings extends Model
{
    public const DOCUMENT_SALES_INVOICE = 'sales_invoice';
    public const DOCUMENT_EXTERNAL_SALES_INVOICE = 'external_sales_invoice';

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_COMPLETE = 'complete';
    public const TRIGGER_PAID = 'paid';
    public const TRIGGER_STATUS = 'status';

    // Connection
    // -------------------------------------------------------------------------

    /**
     * Moneybird API token, sent as `Authorization: Bearer …`. Env-parseable.
     *
     * A personal token from moneybird.com/user/applications is the whole account's key, which is
     * why the settings screen pushes people towards `$MONEYBIRD_API_TOKEN` rather than a value
     * pasted into project config.
     */
    public string $apiToken = '';

    /**
     * The administration to book into — the number in Moneybird's own URLs. Env-parseable.
     */
    public string $administrationId = '';

    // Documents
    // -------------------------------------------------------------------------

    /**
     * `sales_invoice` lets Moneybird own the invoice number, produce the PDF and (optionally)
     * email it. `external_sales_invoice` books an invoice the shop already issued under its own
     * number — no PDF, no sending, just the revenue and the VAT.
     */
    public string $documentType = self::DOCUMENT_SALES_INVOICE;

    /**
     * When an order should be pushed: `manual`, `complete`, `paid` or `status`.
     */
    public string $trigger = self::TRIGGER_PAID;

    /**
     * Commerce order status handle that triggers a push, when `trigger` is `status`.
     */
    public string $triggerStatusHandle = '';

    /**
     * Push through the queue rather than during the request that completed the order.
     */
    public bool $queueSync = true;

    /**
     * How many times a failed push is retried before it stops on its own.
     */
    public int $maxAttempts = 5;

    /**
     * Which order identifier becomes the Moneybird reference: `reference`, `number`,
     * `shortNumber` or `id`.
     */
    public string $referenceSource = 'reference';

    /**
     * Which date the invoice carries: `ordered`, `paid` or `today`.
     */
    public string $invoiceDateSource = 'ordered';

    /**
     * Days after the invoice date the invoice is due (sales invoices only).
     */
    public int $firstDueInterval = 14;

    /**
     * Moneybird workflow and document style ids. Blank uses the administration's defaults.
     */
    public string $workflowId = '';
    public string $documentStyleId = '';

    /**
     * Send the invoice from Moneybird once it has been created (Pro, sales invoices only).
     */
    public bool $sendInvoice = false;

    /**
     * `Email`, `Simplerinvoicing`, `Post` or `Manual`.
     */
    public string $sendDeliveryMethod = 'Email';

    /**
     * Optional body for the sending email. Moneybird expands `{invoice_id}` itself.
     */
    public string $emailMessage = '';

    /**
     * Skip €0.00 orders — free samples and 100%-discounted test orders otherwise book a document
     * apiece for no revenue.
     */
    public bool $skipZeroTotalOrders = true;

    /**
     * Add the order's number to the invoice as a line of free text.
     */
    public bool $includeOrderReferenceLine = true;

    // Ledger accounts
    // -------------------------------------------------------------------------

    public string $defaultLedgerAccountId = '';
    public string $shippingLedgerAccountId = '';
    public string $discountLedgerAccountId = '';

    /**
     * Commerce product type handle => Moneybird ledger account id (Pro).
     *
     * @var array<string, string>
     */
    private array $_ledgerAccountMap = [];

    // Tax
    // -------------------------------------------------------------------------

    /**
     * The country the shop files its VAT in. Everything the VAT engine decides is relative to it.
     */
    public string $homeCountry = 'NL';

    /**
     * Effective VAT percentage => Moneybird tax rate id, e.g. `['21' => '4536…', '9' => '4536…']`.
     *
     * Percentages rather than Commerce tax rate ids on purpose: the percentage is what Commerce
     * actually charged, and it survives a merchant rebuilding their tax zones.
     *
     * @var array<string, string>
     */
    private array $_taxRateMap = [];

    /**
     * The Moneybird tax rate representing "VAT reverse-charged to the customer" — the 0% rate
     * that prints *btw verlegd* on the invoice, not a plain 0%.
     */
    public string $reverseChargeTaxRateId = '';

    /**
     * The Moneybird tax rate for exports outside the EU.
     */
    public string $exportTaxRateId = '';

    /**
     * Distance selling under the One Stop Shop: consumers elsewhere in the EU are charged their
     * own country's rate, and it has to be booked against that country's Moneybird tax rate (Pro).
     */
    public bool $ossEnabled = false;

    /**
     * `COUNTRY:PERCENTAGE` => Moneybird tax rate id, e.g. `['DE:19' => '4536…']`.
     *
     * @var array<string, string>
     */
    private array $_ossTaxRateMap = [];

    /**
     * Where a business customer's VAT number lives. `organizationTaxId` is Craft's own address
     * field; anything else is read as a custom field handle on the address.
     */
    public string $vatNumberSource = 'organizationTaxId';

    /**
     * Add a rounding line when the assembled invoice does not total exactly what the customer
     * paid. Off means Bird refuses to book such an order instead.
     */
    public bool $reconcileTotals = true;

    // Contacts
    // -------------------------------------------------------------------------

    public bool $syncContacts = true;

    /**
     * What Bird writes into Moneybird's `customer_id`, and therefore what it looks a returning
     * customer up by: `userId`, `email`, `orderNumber` or `none`.
     */
    public string $contactCustomerIdSource = 'userId';

    /**
     * Push address changes back onto an existing Moneybird contact.
     */
    public bool $updateExistingContacts = true;

    /**
     * Which Commerce address becomes the contact's address: `billing` or `shipping`.
     */
    public string $contactAddressSource = 'billing';

    /**
     * A Moneybird contact id to invoice to when an order has nobody to match — a guest checkout
     * with no address, or contact syncing turned off entirely.
     *
     * Booking B2C revenue to one "Webshop customers" contact rather than a contact per shopper is
     * a legitimate way to run a busy shop, and Moneybird requires *some* contact either way.
     */
    public string $fallbackContactId = '';

    // Payments
    // -------------------------------------------------------------------------

    /**
     * Register the order's payment against the invoice, so it lands in Moneybird already paid.
     */
    public bool $registerPayments = true;

    /**
     * The Moneybird financial account the payment is booked to. Blank leaves it unassigned, which
     * is what you want when the bank feed will match it up later.
     */
    public string $financialAccountId = '';

    /**
     * Turn a Commerce refund into a Moneybird credit invoice (Pro).
     */
    public bool $creditRefunds = true;

    // Webhooks
    // -------------------------------------------------------------------------

    /**
     * Accept webhooks from Moneybird (Pro).
     */
    public bool $webhooksEnabled = false;

    /**
     * For a webhook made outside Bird: an environment variable holding its signing secret
     * (`$BIRD_WEBHOOK_SECRET`). One Bird installs keeps its secret in its own table, encrypted —
     * {@see \justinholtweb\bird\services\Webhooks::signingSecret()}.
     *
     * Before 5.0.2 the installed secret was saved here, literally, and so committed with project
     * config. A literal is now refused unless it is that already-stored value, which the settings
     * screen never renders and asks to remove.
     */
    public string $webhookSecret = '';

    /**
     * Before 5.0.2, the id of the webhook Bird installed. Now kept in Bird's own table; read only as
     * a fallback for an install that hasn't run the 5.0.2 migration's copy.
     */
    public string $webhookId = '';

    /**
     * Commerce order status to move an order to when Moneybird reports its invoice paid.
     */
    public string $paidOrderStatusHandle = '';

    // Alerts
    // -------------------------------------------------------------------------
    // Nothing here is `required`: an empty recipient list and an empty webhook URL simply mean
    // nobody is told, and a fresh install must be able to save every other setting.

    /**
     * Comma- or newline-separated addresses, or an `$ENV` reference that resolves to them.
     */
    public string $alertRecipients = '';

    /**
     * A Slack or Teams incoming-webhook URL (or `$ENV`). Sent through the SSRF guard.
     */
    public string $alertWebhookUrl = '';

    /**
     * `slack`, `teams` or `json` — the shape of the webhook body.
     */
    public string $alertWebhookFormat = 'slack';

    /**
     * Optional. When set, the webhook carries an `X-Bird-Signature` HMAC of its body.
     */
    public string $alertWebhookSecret = '';

    /**
     * Alert when an invoice or credit note failed to book: Moneybird refused it, or a mapping is
     * missing (an unmapped VAT rate, no contact to invoice).
     */
    public bool $alertOnDocuments = true;

    /**
     * This many failed documents open the incident. One is right for most shops: a single order
     * missing from Moneybird is revenue missing from the VAT return.
     */
    public int $alertDocumentThreshold = 1;

    /**
     * A 401 or 403 from Moneybird: the token was revoked, expired or lacks a scope.
     */
    public bool $alertOnAuthFailure = true;

    /**
     * A completed order due a booking that still has none after this many hours is a stall (the
     * queue is not running, or a push died half way). 0 switches it off.
     */
    public int $alertStallHours = 6;

    /**
     * An incident that reopens this soon after its recovery message waits out the rest.
     */
    public int $alertCooldownMinutes = 60;

    /**
     * Config-file only: let the alert webhook reach private, loopback and link-local hosts (a
     * self-hosted Mattermost on the LAN). The scheme and no-redirect rules still hold.
     */
    public bool $allowPrivateAlertWebhookHosts = false;

    // Logging
    // -------------------------------------------------------------------------

    public bool $loggingEnabled = true;

    /**
     * Keep request and response bodies on log rows (Pro).
     */
    public bool $logPayloads = true;

    /**
     * Days of log history to keep. 0 keeps everything.
     */
    public int $logRetentionDays = 30;

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            // Filters first, and `in` after them as an assertion.
            //
            // Yii's `in` validator skips empty values, so a post that leaves a select out — an
            // older settings template, a scripted save, a browser that dropped a field — stores an
            // empty enum, passes validation, and quietly changes what Bird does. Normalising back
            // to the default is the only behaviour that cannot surprise anybody, and it must not
            // be `required`: Craft validates plugin settings wholesale, so a required attribute
            // would stop a fresh install saving the screen at all.
            [['documentType'], 'filter', 'filter' => fn($value) => self::oneOf($value, [self::DOCUMENT_SALES_INVOICE, self::DOCUMENT_EXTERNAL_SALES_INVOICE], self::DOCUMENT_SALES_INVOICE)],
            [['trigger'], 'filter', 'filter' => fn($value) => self::oneOf($value, [self::TRIGGER_MANUAL, self::TRIGGER_COMPLETE, self::TRIGGER_PAID, self::TRIGGER_STATUS], self::TRIGGER_PAID)],
            [['referenceSource'], 'filter', 'filter' => fn($value) => self::oneOf($value, ['reference', 'number', 'shortNumber', 'id'], 'reference')],
            [['invoiceDateSource'], 'filter', 'filter' => fn($value) => self::oneOf($value, ['ordered', 'paid', 'today'], 'ordered')],
            [['contactAddressSource'], 'filter', 'filter' => fn($value) => self::oneOf($value, ['billing', 'shipping'], 'billing')],
            [['contactCustomerIdSource'], 'filter', 'filter' => fn($value) => self::oneOf($value, ['userId', 'email', 'orderNumber', 'none'], 'userId')],
            [['sendDeliveryMethod'], 'filter', 'filter' => fn($value) => self::oneOf($value, ['Email', 'Simplerinvoicing', 'Post', 'Manual'], 'Email')],
            [['homeCountry'], 'filter', 'filter' => static function($value) {
                $value = strtoupper(trim((string)$value));

                return preg_match('/^[A-Z]{2}$/', $value) ? $value : 'NL';
            }],

            [['maxAttempts'], 'integer', 'min' => 1, 'max' => 20],
            [['firstDueInterval'], 'integer', 'min' => 0, 'max' => 365],
            [['logRetentionDays'], 'integer', 'min' => 0],
            [['documentType'], 'in', 'range' => [self::DOCUMENT_SALES_INVOICE, self::DOCUMENT_EXTERNAL_SALES_INVOICE]],
            [['trigger'], 'in', 'range' => [self::TRIGGER_MANUAL, self::TRIGGER_COMPLETE, self::TRIGGER_PAID, self::TRIGGER_STATUS]],
            [['referenceSource'], 'in', 'range' => ['reference', 'number', 'shortNumber', 'id']],
            [['invoiceDateSource'], 'in', 'range' => ['ordered', 'paid', 'today']],
            [['contactAddressSource'], 'in', 'range' => ['billing', 'shipping']],
            [['contactCustomerIdSource'], 'in', 'range' => ['userId', 'email', 'orderNumber', 'none']],
            [['sendDeliveryMethod'], 'in', 'range' => ['Email', 'Simplerinvoicing', 'Post', 'Manual']],
            [['apiToken', 'administrationId', 'webhookSecret'], 'string'],
            [['webhookSecret'], 'validateWebhookSecret'],
            [['taxRateMap', 'ossTaxRateMap', 'ledgerAccountMap'], 'safe'],
            [['alertOnDocuments', 'alertOnAuthFailure', 'allowPrivateAlertWebhookHosts'], 'boolean'],
            [['alertDocumentThreshold'], 'integer', 'min' => 1, 'max' => 10000],
            [['alertStallHours'], 'integer', 'min' => 0, 'max' => 720],
            [['alertCooldownMinutes'], 'integer', 'min' => 0, 'max' => 10080],
            [['alertWebhookFormat'], 'filter', 'filter' => fn($value) => self::oneOf($value, ['slack', 'teams', 'json'], 'slack')],
            [['alertWebhookFormat'], 'in', 'range' => ['slack', 'teams', 'json']],
            [['alertRecipients', 'alertWebhookUrl', 'alertWebhookSecret'], 'string', 'max' => 2000],
            [['alertRecipients'], 'validateRecipients'],
            [['alertWebhookUrl'], 'validateAlertWebhookUrl'],
        ];
    }

    /**
     * Every address must be one, when there are any. An `$ENV` reference that is not set yet is
     * allowed — a staging site legitimately has no recipients.
     */
    public function validateRecipients(string $attribute): void
    {
        foreach ($this->recipientList(false) as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $this->addError($attribute, Craft::t('bird', '“{address}” is not an email address.', ['address' => $address]));
            }
        }
    }

    /**
     * Only the shape is checked here. Where the host resolves is checked at send time, every time,
     * because DNS can change between a save and a send.
     */
    public function validateAlertWebhookUrl(string $attribute): void
    {
        $url = trim((string)App::parseEnv($this->alertWebhookUrl));

        if ($url === '' || str_starts_with($url, '$')) {
            return;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true) || !parse_url($url, PHP_URL_HOST)) {
            $this->addError($attribute, Craft::t('bird', 'Only http:// and https:// webhook URLs are allowed.'));
        }
    }

    /**
     * The alert recipients, with `$ENV` resolved.
     *
     * @return string[]
     */
    public function recipientList(bool $validOnly = true): array
    {
        $raw = trim((string)App::parseEnv($this->alertRecipients));

        if ($raw === '' || str_starts_with($raw, '$')) {
            return [];
        }

        $list = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: []))));

        return $validOnly
            ? array_values(array_filter($list, static fn(string $a) => filter_var($a, FILTER_VALIDATE_EMAIL) !== false))
            : $list;
    }

    /**
     * The settings screen never renders a stored literal secret, so it posts an empty field and
     * `webhookSecretKept`. Empty plus the flag means "unchanged" — unless "Remove it" was ticked.
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (is_array($values)) {
            if (!empty($values['webhookSecretKept']) && trim((string)($values['webhookSecret'] ?? '')) === '') {
                if (empty($values['webhookSecretRemove'])) {
                    unset($values['webhookSecret']);
                } else {
                    $values['webhookSecret'] = '';
                }
            }

            unset($values['webhookSecretKept'], $values['webhookSecretRemove']);
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * Whether the setting holds a secret itself rather than naming an environment variable — and
     * so sits in project config. Only an install from before 5.0.2 can be in this state.
     */
    public function storesLiteralWebhookSecret(): bool
    {
        $value = trim($this->webhookSecret);

        return $value !== '' && !str_starts_with($value, '$');
    }

    public function validateWebhookSecret(string $attribute): void
    {
        if (!$this->storesLiteralWebhookSecret()) {
            return;
        }

        $stored = Craft::$app->getProjectConfig()->get('plugins.bird.settings.webhookSecret');

        if (!is_string($stored) || trim($stored) !== trim($this->webhookSecret)) {
            $this->addError($attribute, Craft::t('bird', 'Enter an environment variable, such as `$BIRD_WEBHOOK_SECRET`, not the secret itself: settings are saved to project config, which is committed with your site. A webhook Bird installs needs nothing here.'));
        }
    }

    // Env-parsed accessors
    // =========================================================================

    public function getParsedApiToken(): string
    {
        return trim((string)App::parseEnv($this->apiToken));
    }

    public function getParsedAdministrationId(): string
    {
        return trim((string)App::parseEnv($this->administrationId));
    }

    public function getParsedWebhookSecret(): string
    {
        return trim((string)App::parseEnv($this->webhookSecret));
    }

    /**
     * Whether Bird has enough to talk to Moneybird at all.
     */
    public function isConfigured(): bool
    {
        return $this->getParsedApiToken() !== '' && $this->getParsedAdministrationId() !== '';
    }

    public function getHomeCountry(): string
    {
        $country = strtoupper(trim($this->homeCountry));

        return $country !== '' ? $country : 'NL';
    }

    // Normalised maps
    // =========================================================================

    /**
     * @return array<string, string>
     */
    public function getTaxRateMap(): array
    {
        return $this->_taxRateMap;
    }

    public function setTaxRateMap(mixed $value): void
    {
        $this->_taxRateMap = self::normalizeMap($value, 'percentage', 'taxRateId', static function(string $key): string {
            return self::normalizePercentage($key);
        });
    }

    /**
     * @return array<string, string>
     */
    public function getOssTaxRateMap(): array
    {
        return $this->_ossTaxRateMap;
    }

    public function setOssTaxRateMap(mixed $value): void
    {
        $this->_ossTaxRateMap = self::normalizeMap($value, 'key', 'taxRateId', static function(string $key): string {
            // `de:19,0` and `DE : 19` both mean the same thing to a human filling in a table.
            $parts = explode(':', $key, 2);

            if (count($parts) !== 2) {
                return strtoupper(trim($key));
            }

            return strtoupper(trim($parts[0])) . ':' . self::normalizePercentage($parts[1]);
        });
    }

    /**
     * @return array<string, string>
     */
    public function getLedgerAccountMap(): array
    {
        return $this->_ledgerAccountMap;
    }

    public function setLedgerAccountMap(mixed $value): void
    {
        $this->_ledgerAccountMap = self::normalizeMap($value, 'productType', 'ledgerAccountId', static fn(string $key): string => trim($key));
    }

    /**
     * The Moneybird tax rate id for a plain percentage, or null when the merchant has not mapped
     * that percentage yet.
     */
    public function taxRateIdForPercentage(float $percentage): ?string
    {
        $key = self::normalizePercentage((string)$percentage);

        return $this->_taxRateMap[$key] ?? null;
    }

    public function ossTaxRateIdFor(string $countryCode, float $percentage): ?string
    {
        $key = strtoupper($countryCode) . ':' . self::normalizePercentage((string)$percentage);

        return $this->_ossTaxRateMap[$key] ?? null;
    }

    public function ledgerAccountIdForProductType(?string $handle): ?string
    {
        if ($handle === null) {
            return null;
        }

        $mapped = $this->_ledgerAccountMap[$handle] ?? null;

        return $mapped !== null && $mapped !== '' ? $mapped : null;
    }

    // Derived
    // =========================================================================

    /**
     * The URL to hand Moneybird when installing the webhook.
     */
    public function getWebhookUrl(): string
    {
        return UrlHelper::actionUrl('bird/webhook/receive');
    }

    /**
     * The administration's home in Moneybird's web app, for "open in Moneybird" links.
     */
    public function getAdministrationUrl(): ?string
    {
        $id = $this->getParsedAdministrationId();

        return $id !== '' ? "https://moneybird.com/$id" : null;
    }

    /**
     * Lite keeps a short tail of log rows: it has no log screen to prune from, so the table must
     * not be able to grow without bound.
     */
    public function getEffectiveLogRetentionDays(): int
    {
        $plugin = Plugin::getInstance();

        if ($plugin !== null && !$plugin->isPro()) {
            return 7;
        }

        return $this->logRetentionDays;
    }

    /**
     * @inheritdoc
     *
     * The three maps are backed by private properties, so Yii does not see them as attributes —
     * and Craft persists plugin settings by walking `attributes()`. Without this they save as
     * nothing at all, silently.
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), ['taxRateMap', 'ossTaxRateMap', 'ledgerAccountMap']);
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'apiToken' => Craft::t('bird', 'API token'),
            'administrationId' => Craft::t('bird', 'Administration'),
            'documentType' => Craft::t('bird', 'Document type'),
            'trigger' => Craft::t('bird', 'Send to Moneybird when'),
            'homeCountry' => Craft::t('bird', 'Home country'),
            'firstDueInterval' => Craft::t('bird', 'Payment term'),
            'maxAttempts' => Craft::t('bird', 'Retry attempts'),
        ];
    }

    // Private
    // =========================================================================

    /**
     * The value if it is one of the allowed ones, otherwise the default.
     *
     * @param string[] $allowed
     */
    private static function oneOf(mixed $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? (string)$value : $default;
    }

    /**
     * Craft's editable tables post `[['col' => …], …]`, project config holds a flat map, and a
     * console command may pass either. Everything lands here and comes out as a flat map.
     *
     * @return array<string, string>
     */
    private static function normalizeMap(mixed $value, string $keyColumn, string $valueColumn, callable $normalizeKey): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $index => $row) {
            if (is_array($row)) {
                $key = (string)($row[$keyColumn] ?? $row[0] ?? '');
                $mapped = (string)($row[$valueColumn] ?? $row[1] ?? '');
            } else {
                // Already a flat map: the array key is the key.
                $key = (string)$index;
                $mapped = (string)$row;
            }

            $key = $normalizeKey(trim($key));
            $mapped = trim($mapped);

            if ($key === '' || $mapped === '') {
                continue;
            }

            $out[$key] = $mapped;
        }

        return $out;
    }

    /**
     * `21`, `21.0`, `21,00` and `21%` are all the same rate.
     */
    private static function normalizePercentage(string $value): string
    {
        $value = str_replace([',', '%', ' '], ['.', '', ''], trim($value));

        if ($value === '' || !is_numeric($value)) {
            return '';
        }

        // Two decimals, then the trailing zeroes stripped: 21.00 => 21, 8.50 => 8.5.
        $formatted = rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
