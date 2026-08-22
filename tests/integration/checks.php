<?php
/**
 * Bird integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-bird/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: fixture products, orders, document rows, contact mappings, log
 * rows, the plugin settings it overwrites and the edition it switches are all restored in a
 * `finally`, pass or fail.
 *
 * Nothing here talks to Moneybird. Everything that would — the API client, the push, the webhook
 * install — is exercised up to the point where the HTTP request would go out, which is where the
 * decisions worth testing are made anyway.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin as Commerce;
use justinholtweb\bird\db\Table;
use justinholtweb\bird\exceptions\ApiException;
use justinholtweb\bird\exceptions\MappingException;
use justinholtweb\bird\helpers\Eu;
use justinholtweb\bird\helpers\Money;
use justinholtweb\bird\models\Document;
use justinholtweb\bird\models\Settings;
use justinholtweb\bird\models\TaxTreatment;
use justinholtweb\bird\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;
$suffix = substr(md5((string)microtime(true)), 0, 6);

$createdProducts = [];
$createdOrders = [];
$originalSettings = $plugin->getSettings()->toArray();
$originalEdition = Craft::$app->getPlugins()->getPluginInfo(Plugin::HANDLE)['edition'] ?? Plugin::EDITION_LITE;

/**
 * `craft-penny` (a sibling plugin in this shared harness) registers an
 * Elements::EVENT_BEFORE_SAVE_ELEMENT handler typed `ModelEvent` while Craft passes an
 * `ElementEvent`, so saving *any* element fatals while it is enabled. Nothing to do with Bird;
 * detached in-process here (never persisted) so fixtures can be created.
 */
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

function switchEdition(string $edition): void
{
    Craft::$app->getPlugins()->switchEdition(Plugin::HANDLE, $edition);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

/**
 * Project config writes are buffered until the request ends, and a bare console script has no
 * request end — so it has to flush them itself.
 */
function applySettings(array $values): void
{
    global $plugin;

    Craft::$app->getPlugins()->savePluginSettings($plugin, array_merge($plugin->getSettings()->toArray(), $values));
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

function makeProduct(string $sku, float $price): Product
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Bird fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->isDefault = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product;
}

/**
 * An in-memory order. Deliberately not saved: the payload builder reads line items, adjustments
 * and addresses, none of which need a row in the database to be right.
 *
 * @param array{
 *     items?: array<int, array{product: Product, qty?: int, tax?: float, taxIncluded?: bool, discount?: float}>,
 *     country?: string,
 *     vatNumber?: string,
 *     organization?: string,
 *     shipping?: float,
 *     shippingTax?: float,
 *     cartDiscount?: float,
 *     email?: string,
 * } $opts
 */
function makeOrder(array $opts = []): Order
{
    global $storeId, $commerce;

    $order = new Order();
    $order->storeId = $storeId;
    $order->number = $commerce->getCarts()->generateCartNumber();
    $order->currency = 'EUR';
    $order->setEmail($opts['email'] ?? 'klant@example.nl');

    $country = $opts['country'] ?? 'NL';

    if ($country !== '') {
        $address = [
            'countryCode' => $country,
            'addressLine1' => 'Hoofdstraat 12',
            'postalCode' => '1234AB',
            'locality' => 'Amsterdam',
            'fullName' => 'Jan de Vries',
        ];

        if (!empty($opts['organization'])) {
            $address['organization'] = $opts['organization'];
        }

        if (!empty($opts['vatNumber'])) {
            $address['organizationTaxId'] = $opts['vatNumber'];
        }

        $order->setBillingAddress($address);
    }

    $lineItems = [];
    $adjustments = [];

    foreach ($opts['items'] ?? [] as $spec) {
        $variant = $spec['product']->getDefaultVariant();

        $lineItem = $commerce->getLineItems()->create($order, [
            'purchasableId' => $variant->id,
            'qty' => $spec['qty'] ?? 1,
        ]);

        $lineItems[] = $lineItem;

        if (!empty($spec['discount'])) {
            $adjustment = new OrderAdjustment();
            $adjustment->type = 'discount';
            $adjustment->name = 'Korting';
            $adjustment->amount = -abs((float)$spec['discount']);
            $adjustment->included = false;
            $adjustment->setLineItem($lineItem);
            $adjustments[] = $adjustment;
        }

        if (isset($spec['tax'])) {
            $adjustment = new OrderAdjustment();
            $adjustment->type = 'tax';
            $adjustment->name = 'BTW';
            $adjustment->amount = (float)$spec['tax'];
            $adjustment->included = (bool)($spec['taxIncluded'] ?? false);
            $adjustment->setLineItem($lineItem);
            $adjustments[] = $adjustment;
        }
    }

    $order->setLineItems($lineItems);

    if (!empty($opts['shipping'])) {
        $adjustment = new OrderAdjustment();
        $adjustment->type = 'shipping';
        $adjustment->name = 'Verzending';
        $adjustment->amount = (float)$opts['shipping'];
        $adjustment->included = false;
        $adjustments[] = $adjustment;
    }

    if (!empty($opts['cartDiscount'])) {
        $adjustment = new OrderAdjustment();
        $adjustment->type = 'discount';
        $adjustment->name = 'Kortingscode';
        $adjustment->amount = -abs((float)$opts['cartDiscount']);
        $adjustment->included = false;
        $adjustments[] = $adjustment;
    }

    if (!empty($opts['shippingTax'])) {
        $adjustment = new OrderAdjustment();
        $adjustment->type = 'tax';
        $adjustment->name = 'BTW verzending';
        $adjustment->amount = (float)$opts['shippingTax'];
        $adjustment->included = (bool)($opts['shippingTaxIncluded'] ?? false);
        $adjustments[] = $adjustment;
    }

    $order->setAdjustments($adjustments);

    return $order;
}

/**
 * The details of a built payload, by description.
 *
 * @return array<string, array<string, mixed>>
 */
function detailsByDescription(array $payload): array
{
    $out = [];

    foreach ($payload['attributes']['details_attributes'] as $detail) {
        $out[$detail['description']] = $detail;
    }

    return $out;
}

$TAX_21 = '111111111111111111';
$TAX_9 = '222222222222222222';
$TAX_0 = '333333333333333333';
$TAX_REVERSE = '444444444444444444';
$TAX_EXPORT = '555555555555555555';
$TAX_DE_19 = '666666666666666666';

try {
    switchEdition(Plugin::EDITION_PRO);

    applySettings([
        'apiToken' => 'test-token',
        'administrationId' => '123456789',
        'homeCountry' => 'NL',
        'documentType' => Settings::DOCUMENT_SALES_INVOICE,
        'trigger' => Settings::TRIGGER_PAID,
        'referenceSource' => 'reference',
        'invoiceDateSource' => 'ordered',
        'firstDueInterval' => 14,
        'reconcileTotals' => true,
        'syncContacts' => true,
        'contactCustomerIdSource' => 'userId',
        'contactAddressSource' => 'billing',
        'vatNumberSource' => 'organizationTaxId',
        'ossEnabled' => false,
        'skipZeroTotalOrders' => true,
        'registerPayments' => true,
        'defaultLedgerAccountId' => '',
        'shippingLedgerAccountId' => '',
        'discountLedgerAccountId' => '',
        'taxRateMap' => ['21' => $TAX_21, '9' => $TAX_9, '0' => $TAX_0],
        'ossTaxRateMap' => ['DE:19' => $TAX_DE_19],
        'reverseChargeTaxRateId' => $TAX_REVERSE,
        'exportTaxRateId' => $TAX_EXPORT,
        'loggingEnabled' => true,
        'logPayloads' => true,
        'logRetentionDays' => 30,
    ]);

    $settings = $plugin->getSettings();
    $product100 = makeProduct("bird-100-$suffix", 100.00);
    $product121 = makeProduct("bird-121-$suffix", 121.00);

    // ---------------------------------------------------------------------
    section('Plumbing');

    check('the plugin is installed and Pro', fn() => $plugin->isPro() === true);

    check('every service resolves', function() use ($plugin) {
        foreach (['api', 'contacts', 'documents', 'invoices', 'log', 'payments', 'sync', 'vat', 'webhooks'] as $service) {
            if ($plugin->get($service) === null) {
                return "missing $service";
            }
        }

        return true;
    });

    check('all three tables exist', function() {
        $schema = Craft::$app->getDb()->getSchema();

        foreach ([Table::DOCUMENTS, Table::CONTACTS, Table::LOG] as $table) {
            if ($schema->getTableSchema($schema->getRawTableName($table)) === null) {
                return "missing $table";
            }
        }

        return true;
    });

    check('Commerce is ready', fn() => Plugin::commerceIsReady() === true);

    // ---------------------------------------------------------------------
    section('EU helper');

    check('the Netherlands is a member state', fn() => Eu::isMemberState('NL') === true);
    check('the UK is not', fn() => Eu::isMemberState('GB') === false);
    check('Northern Ireland is, for goods', fn() => Eu::isMemberState('XI') === true);
    check('Monaco is, because it is France for VAT', fn() => Eu::isMemberState('MC') === true);
    check('a lower-case code still matches', fn() => Eu::isMemberState('de') === true);
    check('an empty country is not a member state', fn() => Eu::isMemberState('') === false);

    check('a VAT number is normalised', fn() => Eu::normalizeVatNumber('nl 8012.34.567.b01') === 'NL801234567B01');
    check('an empty VAT number normalises to null', fn() => Eu::normalizeVatNumber('   ') === null);
    check('a VAT number reveals its country', fn() => Eu::countryOfVatNumber('BE0123456789') === 'BE');
    check('EL means Greece', fn() => Eu::countryOfVatNumber('EL123456789') === 'GR');
    check('a non-EU prefix is not a VAT country', fn() => Eu::countryOfVatNumber('US123456789') === null);
    check('a matching VAT number and country agree', fn() => Eu::vatNumberMatchesCountry('DE123456789', 'DE') === true);
    check('a German number on a French address does not', fn() => Eu::vatNumberMatchesCountry('DE123456789', 'FR') === false);

    // ---------------------------------------------------------------------
    section('Money helper');

    check('amounts render with two decimals', fn() => Money::amount(19.989999999999998) === '19.99');
    check('a unit price keeps four decimals', fn() => Money::unitPrice(29.97, 3) === '9.9900');
    check('a zero quantity does not divide by zero', fn() => Money::unitPrice(10.0, 0) === '10.0000');
    check('a Dutch decimal comma is read', fn() => Money::toFloat('10,95') === 10.95);
    check('cent-equal amounts compare equal', fn() => Money::equal(10.001, 10.0) === true);
    check('a cent apart is not equal', fn() => Money::equal(10.02, 10.0) === false);

    // ---------------------------------------------------------------------
    section('Settings');

    check('the tax map normalises percentages', function() {
        $model = new Settings();
        $model->taxRateMap = [
            ['percentage' => '21,00', 'taxRateId' => 'a'],
            ['percentage' => '9%', 'taxRateId' => 'b'],
            ['percentage' => ' 0 ', 'taxRateId' => 'c'],
        ];

        return $model->getTaxRateMap() === ['21' => 'a', '9' => 'b', '0' => 'c'];
    });

    check('a flat map survives a round trip', function() {
        $model = new Settings();
        $model->taxRateMap = ['21' => 'a'];

        return $model->getTaxRateMap() === ['21' => 'a'];
    });

    check('a row with no id is dropped', function() {
        $model = new Settings();
        $model->taxRateMap = [['percentage' => '21', 'taxRateId' => '']];

        return $model->getTaxRateMap() === [];
    });

    check('the OSS map upper-cases the country', function() {
        $model = new Settings();
        $model->ossTaxRateMap = [['key' => 'de:19,0', 'taxRateId' => 'x']];

        return $model->getOssTaxRateMap() === ['DE:19' => 'x'];
    });

    check('the three private maps are real attributes', function() {
        $attributes = (new Settings())->attributes();

        return in_array('taxRateMap', $attributes, true)
            && in_array('ossTaxRateMap', $attributes, true)
            && in_array('ledgerAccountMap', $attributes, true);
    });

    check('the maps survive being saved to project config', function() use ($plugin, $TAX_21) {
        return $plugin->getSettings()->getTaxRateMap()['21'] === $TAX_21;
    });

    check('a percentage look-up tolerates float noise', fn() => $settings->taxRateIdForPercentage(21.0) === $TAX_21);
    check('an unmapped percentage returns null', fn() => $settings->taxRateIdForPercentage(6.0) === null);
    check('the OSS look-up keys on country and rate', fn() => $settings->ossTaxRateIdFor('DE', 19.0) === $TAX_DE_19);
    check('an env-less token parses to itself', fn() => $settings->getParsedApiToken() === 'test-token');
    check('the plugin counts as configured', fn() => $settings->isConfigured() === true);
    check('the webhook URL is an action URL', fn() => str_contains($settings->getWebhookUrl(), 'bird/webhook/receive'));
    check('Pro keeps the configured log retention', fn() => $settings->getEffectiveLogRetentionDays() === 30);

    check('an empty enum normalises back to its default rather than being stored blank', function() use ($plugin) {
        // Yii's `in` validator skips empty values, so without the filter rules a post that left a
        // select out would store an empty `documentType`, pass validation, and quietly change what
        // Bird does.
        applySettings(['documentType' => '', 'trigger' => '']);

        try {
            return $plugin->getSettings()->documentType === Settings::DOCUMENT_SALES_INVOICE
                && $plugin->getSettings()->trigger === Settings::TRIGGER_PAID;
        } finally {
            applySettings(['documentType' => Settings::DOCUMENT_SALES_INVOICE, 'trigger' => Settings::TRIGGER_PAID]);
        }
    });

    check('a value outside the range normalises too', function() use ($plugin) {
        applySettings(['referenceSource' => 'nonsense']);

        try {
            return $plugin->getSettings()->referenceSource === 'reference';
        } finally {
            applySettings(['referenceSource' => 'reference']);
        }
    });

    check('the home country is upper-cased, and garbage falls back to NL', function() use ($plugin) {
        applySettings(['homeCountry' => 'be']);
        $upper = $plugin->getSettings()->homeCountry;

        applySettings(['homeCountry' => 'nonsense']);
        $fallback = $plugin->getSettings()->homeCountry;

        applySettings(['homeCountry' => 'NL']);

        return $upper === 'BE' && $fallback === 'NL' ? true : "$upper / $fallback";
    });

    check('settings are never required', function() {
        $model = new Settings();

        foreach ($model->rules() as $rule) {
            if (in_array('required', (array)$rule, true)) {
                return 'a rule requires: ' . json_encode($rule);
            }
        }

        return true;
    });

    // ---------------------------------------------------------------------
    section('VAT treatment');

    $vat = $plugin->getVat();

    check('a Dutch customer is domestic', function() use ($vat, $product100) {
        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]], 'country' => 'NL']);

        return $vat->treatmentForOrder($order) === TaxTreatment::DOMESTIC;
    });

    check('a German business with a matching VAT number and no tax is reverse charge', function() use ($vat, $product100) {
        $order = makeOrder([
            'items' => [['product' => $product100]],
            'country' => 'DE',
            'organization' => 'Muster GmbH',
            'vatNumber' => 'DE123456789',
        ]);

        return $vat->treatmentForOrder($order) === TaxTreatment::EU_REVERSE_CHARGE;
    });

    check('a VAT number that still paid tax is not reverse charge', function() use ($vat, $product100) {
        $order = makeOrder([
            'items' => [['product' => $product100, 'tax' => 21.0]],
            'country' => 'DE',
            'vatNumber' => 'DE123456789',
        ]);

        return $vat->treatmentForOrder($order) === TaxTreatment::EU_HOME_RATE;
    });

    check('a VAT number from the wrong country is not reverse charge', function() use ($vat, $product100) {
        $order = makeOrder([
            'items' => [['product' => $product100]],
            'country' => 'FR',
            'vatNumber' => 'DE123456789',
        ]);

        return $vat->treatmentForOrder($order) === TaxTreatment::EU_HOME_RATE;
    });

    check('an EU consumer is charged the home rate while OSS is off', function() use ($vat, $product100) {
        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]], 'country' => 'BE']);

        return $vat->treatmentForOrder($order) === TaxTreatment::EU_HOME_RATE;
    });

    check('turning OSS on moves that consumer onto OSS', function() use ($vat, $product100) {
        applySettings(['ossEnabled' => true]);

        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 19.0]], 'country' => 'DE']);
        $treatment = $vat->treatmentForOrder($order);

        applySettings(['ossEnabled' => false]);

        return $treatment === TaxTreatment::EU_OSS;
    });

    check('an American customer is an export', function() use ($vat, $product100) {
        $order = makeOrder(['items' => [['product' => $product100]], 'country' => 'US']);

        return $vat->treatmentForOrder($order) === TaxTreatment::EXPORT;
    });

    check('an order with no address at all is unknown', function() use ($vat, $product100) {
        $order = makeOrder(['items' => [['product' => $product100]], 'country' => '']);

        return $vat->treatmentForOrder($order) === TaxTreatment::UNKNOWN;
    });

    check('the VAT number is read off the address', function() use ($vat, $product100) {
        $order = makeOrder(['items' => [['product' => $product100]], 'country' => 'DE', 'vatNumber' => 'de 123 456 789']);

        return $vat->vatNumberForOrder($order) === 'DE123456789';
    });

    check('21% resolves to the mapped rate', fn() => $vat->taxRateIdFor(TaxTreatment::DOMESTIC, 21.0) === $TAX_21);
    check('reverse charge resolves to the reverse-charge rate, not plain 0%', fn() => $vat->taxRateIdFor(TaxTreatment::EU_REVERSE_CHARGE, 0.0) === $TAX_REVERSE);
    check('an export resolves to the export rate', fn() => $vat->taxRateIdFor(TaxTreatment::EXPORT, 0.0) === $TAX_EXPORT);
    check('domestic 0% resolves to the plain zero rate', fn() => $vat->taxRateIdFor(TaxTreatment::DOMESTIC, 0.0) === $TAX_0);
    check('OSS prefers the country rate', fn() => $vat->taxRateIdFor(TaxTreatment::EU_OSS, 19.0, 'DE') === $TAX_DE_19);
    check('OSS falls back to the plain map for an unmapped country', fn() => $vat->taxRateIdFor(TaxTreatment::EU_OSS, 21.0, 'BE') === $TAX_21);
    check('an unmapped percentage resolves to null', fn() => $vat->taxRateIdFor(TaxTreatment::DOMESTIC, 6.0) === null);

    check('the effective percentage comes out of the amounts', fn() => $vat->percentageFor(21.0, 100.0) === 21.0);
    check('a zero base does not divide by zero', fn() => $vat->percentageFor(21.0, 0.0) === 0.0);
    check('float noise still rounds onto 21', fn() => $vat->percentageFor(20.999999, 99.999999) === 21.0);

    check('a cent of per-line rounding still matches the mapped rate', function() use ($vat, $TAX_21) {
        // €10.10 at 21% is €2.121, which Commerce records as €2.12 — 20.99% if you divide it back
        // out. Matching on the money finds 21% anyway.
        $rate = $vat->matchRate(TaxTreatment::DOMESTIC, 2.12, 10.10);

        return ($rate['id'] ?? null) === $TAX_21 && ($rate['percentage'] ?? null) === 21.0
            ? true
            : json_encode($rate);
    });

    check('a rate nobody mapped is not silently rounded onto one that was', function() use ($vat) {
        return $vat->matchRate(TaxTreatment::DOMESTIC, 6.0, 100.0) === null;
    });

    check('a zero-tax line matches the zero rate for its treatment', function() use ($vat, $TAX_EXPORT) {
        return ($vat->matchRate(TaxTreatment::EXPORT, 0.0, 100.0)['id'] ?? null) === $TAX_EXPORT;
    });

    check('a negative line (a discount) matches the same rate', function() use ($vat, $TAX_21) {
        return ($vat->matchRate(TaxTreatment::DOMESTIC, -2.10, -10.0)['id'] ?? null) === $TAX_21;
    });

    check('OSS matching prefers the country’s own rate', function() use ($vat, $TAX_DE_19) {
        return ($vat->matchRate(TaxTreatment::EU_OSS, 19.0, 100.0, 'DE')['id'] ?? null) === $TAX_DE_19;
    });

    check('the missing-rate message names the percentage', function() use ($vat) {
        return str_contains($vat->describeMissingRate(TaxTreatment::DOMESTIC, 6.0, null), '6%');
    });

    check('the missing reverse-charge message says so', function() use ($vat) {
        return str_contains($vat->describeMissingRate(TaxTreatment::EU_REVERSE_CHARGE, 0.0, null), 'verlegd');
    });

    // ---------------------------------------------------------------------
    section('Invoice payload');

    $invoices = $plugin->getInvoices();

    check('a plain domestic order becomes one line at 21%', function() use ($invoices, $product100, $TAX_21) {
        $order = makeOrder(['items' => [['product' => $product100, 'qty' => 2, 'tax' => 42.0]]]);
        $payload = $invoices->buildPayload($order, '999');
        $details = $payload['attributes']['details_attributes'];

        if (count($details) !== 1) {
            return 'expected one detail, got ' . count($details);
        }

        $detail = $details[0];

        return $detail['amount'] === '2'
            && $detail['price'] === '100.0000'
            && $detail['tax_rate_id'] === $TAX_21
            ? true
            : json_encode($detail);
    });

    check('prices go over excluding tax when Commerce added it', function() use ($invoices, $product100) {
        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);
        $payload = $invoices->buildPayload($order, '999');

        return $payload['attributes']['prices_are_incl_tax'] === false;
    });

    check('the invoice total matches the order total', function() use ($invoices, $product100) {
        $order = makeOrder(['items' => [['product' => $product100, 'qty' => 2, 'tax' => 42.0]]]);
        $payload = $invoices->buildPayload($order, '999');

        return abs($payload['expectedTotal'] - 242.0) < 0.005 && abs($payload['orderTotal'] - 242.0) < 0.005
            ? true
            : "invoice {$payload['expectedTotal']} vs order {$payload['orderTotal']}";
    });

    check('an included-tax order goes over inclusive', function() use ($invoices, $product121) {
        $order = makeOrder(['items' => [['product' => $product121, 'tax' => 21.0, 'taxIncluded' => true]]]);
        $payload = $invoices->buildPayload($order, '999');
        $detail = $payload['attributes']['details_attributes'][0];

        return $payload['attributes']['prices_are_incl_tax'] === true
            && $detail['price'] === '121.0000'
            && abs($payload['expectedTotal'] - 121.0) < 0.005
            ? true
            : json_encode([$payload['attributes']['prices_are_incl_tax'], $detail['price'], $payload['expectedTotal']]);
    });

    check('an included-tax line still resolves 21%', function() use ($invoices, $product121, $TAX_21) {
        $order = makeOrder(['items' => [['product' => $product121, 'tax' => 21.0, 'taxIncluded' => true]]]);
        $payload = $invoices->buildPayload($order, '999');

        return $payload['attributes']['details_attributes'][0]['tax_rate_id'] === $TAX_21;
    });

    check('a line discount comes off the unit price rather than becoming a line', function() use ($invoices, $product100) {
        $order = makeOrder(['items' => [['product' => $product100, 'qty' => 2, 'discount' => 20.0, 'tax' => 37.8]]]);
        $payload = $invoices->buildPayload($order, '999');
        $details = $payload['attributes']['details_attributes'];

        // 200 - 20 = 180 net over 2 units.
        return count($details) === 1 && $details[0]['price'] === '90.0000'
            ? true
            : json_encode($details);
    });

    check('shipping becomes its own line at its own rate', function() use ($invoices, $product100, $TAX_21) {
        $order = makeOrder([
            'items' => [['product' => $product100, 'tax' => 21.0]],
            'shipping' => 10.0,
            'shippingTax' => 2.10,
        ]);
        $payload = $invoices->buildPayload($order, '999');
        $details = detailsByDescription($payload);

        if (!isset($details['Verzending'])) {
            return 'no shipping line: ' . json_encode(array_keys($details));
        }

        return $details['Verzending']['price'] === '10.0000'
            && $details['Verzending']['tax_rate_id'] === $TAX_21
            ? true
            : json_encode($details['Verzending']);
    });

    check('an order with shipping still totals exactly', function() use ($invoices, $product100) {
        $order = makeOrder([
            'items' => [['product' => $product100, 'tax' => 21.0]],
            'shipping' => 10.0,
            'shippingTax' => 2.10,
        ]);
        $payload = $invoices->buildPayload($order, '999');

        return abs($payload['orderTotal'] - 133.10) < 0.005
            && abs($payload['expectedTotal'] - 133.10) < 0.005
            && abs($payload['rounding']) < 0.005
            ? true
            : "order {$payload['orderTotal']} invoice {$payload['expectedTotal']} rounding {$payload['rounding']}";
    });

    check('a cart-level discount becomes a negative line', function() use ($invoices, $product100) {
        $order = makeOrder([
            'items' => [['product' => $product100, 'tax' => 21.0]],
            'cartDiscount' => 10.0,
            'shippingTax' => -2.10,
        ]);
        $payload = $invoices->buildPayload($order, '999');
        $details = detailsByDescription($payload);

        return isset($details['Kortingscode']) && (float)$details['Kortingscode']['price'] < 0
            ? true
            : json_encode(array_keys($details));
    });

    check('a rounding line appears when the arithmetic cannot close', function() use ($invoices, $product100) {
        // 21.05 of tax on 100 is close enough to 21% to be that rate rounded, so the invoice
        // books at 21% and lands five cents short — which the rounding line has to carry.
        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.05]]]);
        $payload = $invoices->buildPayload($order, '999');
        $details = detailsByDescription($payload);

        return isset($details['Rounding'])
            && abs($payload['expectedTotal'] - $payload['orderTotal']) < 0.005
            ? true
            : 'details: ' . json_encode(array_keys($details));
    });

    check('the rounding line is booked at 0%', function() use ($invoices, $product100, $TAX_0) {
        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.05]]]);
        $payload = $invoices->buildPayload($order, '999');
        $details = detailsByDescription($payload);

        return ($details['Rounding']['tax_rate_id'] ?? null) === $TAX_0;
    });

    check('turning reconciliation off refuses the order instead', function() use ($invoices, $product100) {
        applySettings(['reconcileTotals' => false]);

        try {
            $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.05]]]);
            $invoices->buildPayload($order, '999');

            return 'no exception';
        } catch (MappingException $e) {
            return str_contains($e->getMessage(), 'Reconcile totals');
        } finally {
            applySettings(['reconcileTotals' => true]);
        }
    });

    check('an unmapped VAT percentage refuses the order by name', function() use ($invoices, $product100) {
        try {
            $order = makeOrder(['items' => [['product' => $product100, 'tax' => 6.0]]]);
            $invoices->buildPayload($order, '999');

            return 'no exception';
        } catch (MappingException $e) {
            return str_contains($e->getMessage(), '6%');
        }
    });

    check('a reverse-charge order books against the reverse-charge rate', function() use ($invoices, $product100, $TAX_REVERSE) {
        $order = makeOrder([
            'items' => [['product' => $product100]],
            'country' => 'DE',
            'vatNumber' => 'DE123456789',
        ]);
        $payload = $invoices->buildPayload($order, '999');

        return $payload['treatment'] === TaxTreatment::EU_REVERSE_CHARGE
            && $payload['attributes']['details_attributes'][0]['tax_rate_id'] === $TAX_REVERSE;
    });

    check('an export books against the export rate', function() use ($invoices, $product100, $TAX_EXPORT) {
        $order = makeOrder(['items' => [['product' => $product100]], 'country' => 'US']);
        $payload = $invoices->buildPayload($order, '999');

        return $payload['attributes']['details_attributes'][0]['tax_rate_id'] === $TAX_EXPORT;
    });

    check('an empty order is refused', function() use ($invoices) {
        try {
            $invoices->buildPayload(makeOrder(), '999');

            return 'no exception';
        } catch (MappingException) {
            return true;
        }
    });

    check('the description carries the SKU', function() use ($invoices, $product100, $suffix) {
        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);
        $payload = $invoices->buildPayload($order, '999');

        return str_contains($payload['attributes']['details_attributes'][0]['description'], "bird-100-$suffix");
    });

    check('every detail carries a row order', function() use ($invoices, $product100) {
        $order = makeOrder([
            'items' => [['product' => $product100, 'tax' => 21.0]],
            'shipping' => 10.0,
            'shippingTax' => 2.10,
        ]);
        $payload = $invoices->buildPayload($order, '999');

        foreach ($payload['attributes']['details_attributes'] as $index => $detail) {
            if (($detail['row_order'] ?? null) !== $index) {
                return 'detail ' . $index . ' has row_order ' . json_encode($detail['row_order'] ?? null);
            }
        }

        return true;
    });

    check('a sales invoice sends first_due_interval, not due_date', function() use ($invoices, $product100) {
        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);
        $attributes = $invoices->buildPayload($order, '999')['attributes'];

        return isset($attributes['invoice_date'], $attributes['first_due_interval'])
            && !isset($attributes['due_date'], $attributes['date']);
    });

    check('an external sales invoice sends date and due_date instead', function() use ($invoices, $product100) {
        applySettings(['documentType' => Settings::DOCUMENT_EXTERNAL_SALES_INVOICE]);

        try {
            $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);
            $attributes = $invoices->buildPayload($order, '999')['attributes'];

            return isset($attributes['date'], $attributes['due_date'], $attributes['source'])
                && !isset($attributes['invoice_date'], $attributes['first_due_interval'])
                ? true
                : json_encode(array_keys($attributes));
        } finally {
            applySettings(['documentType' => Settings::DOCUMENT_SALES_INVOICE]);
        }
    });

    check('no detail carries a key Moneybird would reject', function() use ($invoices, $product100) {
        // Both create endpoints declare `unevaluatedProperties: false`, and `amount_decimal` —
        // which the reference docs mention on the *response* — is not one of the keys they accept.
        $allowed = ['id', 'description', 'period', 'price', 'amount', 'tax_rate_id', 'ledger_account_id',
            'project_id', 'product_id', 'row_order', '_destroy'];

        $order = makeOrder([
            'items' => [['product' => $product100, 'tax' => 21.0]],
            'shipping' => 10.0,
            'shippingTax' => 2.10,
        ]);

        foreach ($invoices->buildPayload($order, '999')['attributes']['details_attributes'] as $detail) {
            foreach (array_keys($detail) as $key) {
                if (!in_array($key, $allowed, true)) {
                    return "unexpected detail key: $key";
                }
            }
        }

        return true;
    });

    check('the contact id lands on the document', function() use ($invoices, $product100) {
        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);

        return $invoices->buildPayload($order, '4815162342')['attributes']['contact_id'] === '4815162342';
    });

    check('a ledger account is attached when one is configured', function() use ($invoices, $product100) {
        applySettings(['defaultLedgerAccountId' => '777', 'shippingLedgerAccountId' => '888']);

        try {
            $order = makeOrder([
                'items' => [['product' => $product100, 'tax' => 21.0]],
                'shipping' => 10.0,
                'shippingTax' => 2.10,
            ]);
            $details = detailsByDescription($invoices->buildPayload($order, '999'));

            return ($details['Verzending']['ledger_account_id'] ?? null) === '888'
                && ($details[array_key_first($details)]['ledger_account_id'] ?? null) === '777';
        } finally {
            applySettings(['defaultLedgerAccountId' => '', 'shippingLedgerAccountId' => '']);
        }
    });

    // ---------------------------------------------------------------------
    section('Reference and dates');

    check('the reference defaults to the order reference', function() use ($invoices, $product100) {
        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);
        $order->reference = 'ORD-42';

        return $invoices->referenceFor($order) === 'ORD-42';
    });

    check('a reference-less order falls back to the short number', function() use ($invoices, $product100) {
        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);

        return $invoices->referenceFor($order) === $order->getShortNumber();
    });

    check('the reference source is honoured', function() use ($invoices, $product100) {
        applySettings(['referenceSource' => 'number']);

        try {
            $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);
            $order->reference = 'ORD-42';

            return $invoices->referenceFor($order) === $order->number;
        } finally {
            applySettings(['referenceSource' => 'reference']);
        }
    });

    check('the invoice date is the order date in the site time zone', function() use ($invoices, $product100) {
        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);
        $order->dateOrdered = new DateTime('2026-03-01 23:30:00', new DateTimeZone('UTC'));

        $date = $invoices->invoiceDateFor($order);

        return $date->getTimezone()->getName() === Craft::$app->getTimeZone();
    });

    check('“today” ignores the order date', function() use ($invoices, $product100) {
        applySettings(['invoiceDateSource' => 'today']);

        try {
            $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);
            $order->dateOrdered = new DateTime('2020-01-01');

            return $invoices->invoiceDateFor($order)->format('Y') === date('Y');
        } finally {
            applySettings(['invoiceDateSource' => 'ordered']);
        }
    });

    // ---------------------------------------------------------------------
    section('Credit notes');

    check('a credit is a negative line at the order’s blended rate', function() use ($invoices, $product100, $TAX_21) {
        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);
        $payload = $invoices->buildCreditPayload($order, 121.0, 'ORD-42-R1', '999');
        $detail = $payload['attributes']['details_attributes'][0];

        return (float)$detail['price'] < 0
            && $detail['tax_rate_id'] === $TAX_21
            && $payload['attributes']['reference'] === 'ORD-42-R1'
            ? true
            : json_encode($detail);
    });

    check('a credit on an excl-tax order credits the net amount', function() use ($invoices, $product100) {
        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);
        $detail = $invoices->buildCreditPayload($order, 121.0, 'R', '999')['attributes']['details_attributes'][0];

        // 121 gross at 21% is 100 net; Moneybird puts the VAT back on.
        return abs((float)$detail['price'] + 100.0) < 0.01 ? true : $detail['price'];
    });

    // ---------------------------------------------------------------------
    section('Contacts');

    $contacts = $plugin->getContacts();

    check('a contact payload carries the address', function() use ($contacts, $product100) {
        $order = makeOrder(['items' => [['product' => $product100]], 'organization' => 'Muster GmbH', 'country' => 'DE']);
        $attributes = $contacts->buildAttributes($order);

        return ($attributes['company_name'] ?? null) === 'Muster GmbH'
            && ($attributes['country'] ?? null) === 'DE'
            && ($attributes['city'] ?? null) === 'Amsterdam'
            ? true
            : json_encode($attributes);
    });

    check('a full name is split into first and last', function() use ($contacts, $product100) {
        $order = makeOrder(['items' => [['product' => $product100]]]);
        $attributes = $contacts->buildAttributes($order);

        return ($attributes['firstname'] ?? null) === 'Jan' && ($attributes['lastname'] ?? null) === 'de Vries'
            ? true
            : json_encode($attributes);
    });

    check('a VAT number goes over as the tax number', function() use ($contacts, $product100) {
        $order = makeOrder(['items' => [['product' => $product100]], 'country' => 'DE', 'vatNumber' => 'DE123456789']);

        return ($contacts->buildAttributes($order)['tax_number'] ?? null) === 'DE123456789';
    });

    check('empty fields are stripped rather than sent as null', function() use ($contacts, $product100) {
        $order = makeOrder(['items' => [['product' => $product100]]]);

        foreach ($contacts->buildAttributes($order) as $key => $value) {
            if ($value === null || $value === '') {
                return "empty $key survived";
            }
        }

        return true;
    });

    check('the customer id is keyed on the Craft user Commerce ensures for the email', function() use ($contacts, $product100) {
        // Commerce 5 calls `Users::ensureUserByEmail()` inside `Order::setEmail()`, so even a
        // guest checkout has a user — and `craft-<id>` is a stabler key than an email somebody
        // can change.
        $order = makeOrder(['items' => [['product' => $product100]], 'email' => 'guest@example.nl']);
        $customerId = $contacts->customerIdForOrder($order);

        return str_starts_with((string)$customerId, 'craft-') || $customerId === 'guest@example.nl'
            ? true
            : var_export($customerId, true);
    });

    check('the order-number source gives a per-order customer id', function() use ($contacts, $product100) {
        applySettings(['contactCustomerIdSource' => 'orderNumber']);

        try {
            $order = makeOrder(['items' => [['product' => $product100]]]);

            return $contacts->customerIdForOrder($order) === $order->number;
        } finally {
            applySettings(['contactCustomerIdSource' => 'userId']);
        }
    });

    check('“none” sets no customer id at all', function() use ($contacts, $product100) {
        applySettings(['contactCustomerIdSource' => 'none']);

        try {
            return $contacts->customerIdForOrder(makeOrder(['items' => [['product' => $product100]]])) === null;
        } finally {
            applySettings(['contactCustomerIdSource' => 'userId']);
        }
    });

    check('contact syncing off returns no contact and makes no request', function() use ($contacts, $product100) {
        applySettings(['syncContacts' => false]);

        try {
            return $contacts->contactIdForOrder(makeOrder(['items' => [['product' => $product100]]])) === null;
        } finally {
            applySettings(['syncContacts' => true]);
        }
    });

    // ---------------------------------------------------------------------
    section('Documents');

    $documents = $plugin->getDocuments();

    // One saved order, because the documents table has a foreign key to `elements`.
    $savedOrder = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);
    $savedOrder->reference = "BIRD-$suffix";

    if (!Craft::$app->getElements()->saveElement($savedOrder)) {
        throw new RuntimeException('Could not save the fixture order: ' . json_encode($savedOrder->getErrors()));
    }

    $createdOrders[] = $savedOrder;

    check('a document row saves and reads back', function() use ($documents, $savedOrder, $suffix) {
        $document = new Document([
            'orderId' => $savedOrder->id,
            'kind' => Document::KIND_INVOICE,
            'sourceKey' => '',
            'documentType' => Settings::DOCUMENT_SALES_INVOICE,
            'moneybirdId' => "mb-$suffix",
            'reference' => "BIRD-$suffix",
            'state' => 'open',
            'currency' => 'EUR',
            'total' => 121.0,
        ]);

        $documents->save($document);

        $read = $documents->getDocumentForOrder($savedOrder->id);

        return $read !== null && $read->moneybirdId === "mb-$suffix" && abs($read->total - 121.0) < 0.001;
    });

    check('saving the same triple updates rather than duplicating', function() use ($documents, $savedOrder) {
        $document = $documents->getDocumentForOrder($savedOrder->id);
        $document->state = 'paid';
        $documents->save($document);

        $rows = (new craft\db\Query())
            ->from([Table::DOCUMENTS])
            ->where(['orderId' => $savedOrder->id, 'kind' => Document::KIND_INVOICE, 'sourceKey' => ''])
            ->count();

        return (int)$rows === 1 && $documents->getDocumentForOrder($savedOrder->id)->state === 'paid';
    });

    check('the unique index refuses a second row for the same triple', function() use ($savedOrder) {
        try {
            Craft::$app->getDb()->createCommand()->insert(Table::DOCUMENTS, [
                'orderId' => $savedOrder->id,
                'kind' => Document::KIND_INVOICE,
                'sourceKey' => '',
                'documentType' => 'sales_invoice',
                'state' => 'pending',
                'total' => 0,
                'totalPaid' => 0,
                'attempts' => 0,
                'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
                'uid' => craft\helpers\StringHelper::UUID(),
            ])->execute();

            return 'the database allowed a duplicate';
        } catch (Throwable) {
            return true;
        }
    });

    check('a credit note lives alongside the invoice', function() use ($documents, $savedOrder) {
        $credit = new Document([
            'orderId' => $savedOrder->id,
            'kind' => Document::KIND_CREDIT,
            'sourceKey' => 'txn-abc',
            'documentType' => Settings::DOCUMENT_SALES_INVOICE,
            'moneybirdId' => 'mb-credit',
            'state' => 'paid',
            'total' => -25.0,
        ]);

        $documents->save($credit);

        return count($documents->getDocumentsForOrder($savedOrder->id)) === 2;
    });

    check('two refunds of the same order are two credit notes', function() use ($documents, $savedOrder) {
        $documents->save(new Document([
            'orderId' => $savedOrder->id,
            'kind' => Document::KIND_CREDIT,
            'sourceKey' => 'txn-def',
            'moneybirdId' => 'mb-credit-2',
            'state' => 'paid',
            'total' => -10.0,
        ]));

        return count($documents->getDocumentsForOrder($savedOrder->id)) === 3;
    });

    check('states are counted', function() use ($documents) {
        $counts = $documents->countsByState();

        return ($counts['paid'] ?? 0) >= 3;
    });

    check('a document knows its Moneybird URL', function() use ($documents, $savedOrder, $suffix) {
        $document = $documents->getDocumentForOrder($savedOrder->id);

        return $document->getMoneybirdUrl() === "https://moneybird.com/123456789/sales_invoices/mb-$suffix";
    });

    check('an external sales invoice links to the other resource', function() {
        $document = new Document([
            'documentType' => Settings::DOCUMENT_EXTERNAL_SALES_INVOICE,
            'moneybirdId' => '42',
            'administrationId' => '99',
        ]);

        return $document->getMoneybirdUrl() === 'https://moneybird.com/99/external_sales_invoices/42';
    });

    check('an unbooked document has no Moneybird URL', function() {
        return (new Document())->getMoneybirdUrl() === null;
    });

    check('a failed document is retryable until the attempt limit', function() use ($documents, $savedOrder) {
        $document = $documents->getDocumentForOrder($savedOrder->id);
        $document->state = Document::STATE_FAILED;
        $document->attempts = 2;
        $documents->save($document);

        $retryable = $documents->getRetryable(5, 10);

        $ids = array_map(static fn(Document $d) => $d->id, $retryable);

        return in_array($document->id, $ids, true);
    });

    check('a document past the attempt limit is not retried', function() use ($documents, $savedOrder) {
        $document = $documents->getDocumentForOrder($savedOrder->id);
        $document->attempts = 9;
        $documents->save($document);

        $ids = array_map(static fn(Document $d) => $d->id, $documents->getRetryable(5, 10));

        return !in_array($document->id, $ids, true);
    });

    check('a document can be searched by reference', function() use ($documents, $suffix) {
        return count($documents->find(['search' => "BIRD-$suffix"])) >= 1;
    });

    check('forgetting a document removes only that row', function() use ($documents, $savedOrder) {
        $credits = array_values(array_filter(
            $documents->getDocumentsForOrder($savedOrder->id),
            static fn(Document $d) => $d->sourceKey === 'txn-def'
        ));

        $documents->deleteById($credits[0]->id);

        return count($documents->getDocumentsForOrder($savedOrder->id)) === 2;
    });

    // ---------------------------------------------------------------------
    section('Sync gating');

    $sync = $plugin->getSync();

    check('sync is ready when configured', fn() => $sync->isReady() === true);

    check('a cart is skipped', function() use ($sync, $product100) {
        $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);
        $order->id = 1;
        $order->isCompleted = false;

        $result = $sync->pushOrder($order);

        return $result->skipped === true && str_contains($result->message, 'cart');
    });

    check('an already-booked order is skipped, not booked twice', function() use ($sync, $savedOrder) {
        $savedOrder->isCompleted = true;
        $result = $sync->pushOrder($savedOrder);

        return $result->skipped === true && str_contains($result->message, 'Already booked');
    });

    check('a zero-total order is skipped', function() use ($sync, $product100) {
        $order = makeOrder();
        $order->id = 999999;
        $order->isCompleted = true;

        $result = $sync->pushOrder($order);

        return $result->skipped === true && str_contains($result->message, 'zero');
    });

    check('an unconfigured install fails rather than throwing', function() use ($sync, $savedOrder) {
        applySettings(['apiToken' => '', 'administrationId' => '']);

        try {
            $result = $sync->pushOrder($savedOrder, true);

            return $result->success === false && str_contains($result->message, 'token');
        } finally {
            applySettings(['apiToken' => 'test-token', 'administrationId' => '123456789']);
        }
    });

    check('the trigger only fires for the configured event', function() use ($sync, $product100) {
        applySettings(['trigger' => Settings::TRIGGER_MANUAL, 'queueSync' => true]);

        try {
            $before = Craft::$app->getQueue()->getTotalWaiting();

            $order = makeOrder(['items' => [['product' => $product100, 'tax' => 21.0]]]);
            $order->id = 1;
            $sync->handleTrigger($order, Settings::TRIGGER_PAID);

            return Craft::$app->getQueue()->getTotalWaiting() === $before;
        } finally {
            applySettings(['trigger' => Settings::TRIGGER_PAID]);
        }
    });

    check('a trigger handler never throws, whatever the order', function() use ($sync) {
        $broken = new Order();
        $sync->handleTrigger($broken, Settings::TRIGGER_PAID);

        return true;
    });

    check('the unbooked-order search excludes what is already booked', function() use ($sync, $savedOrder) {
        $ids = array_map(static fn(Order $o) => $o->id, $sync->findUnbookedOrders(null, 50));

        return !in_array($savedOrder->id, $ids, true);
    });

    // ---------------------------------------------------------------------
    section('API client');

    $api = $plugin->getApi();

    check('a call without a token throws before any HTTP', function() use ($api) {
        applySettings(['apiToken' => '']);

        try {
            $api->request('GET', 'contacts.json');

            return 'no exception';
        } catch (ApiException $e) {
            return str_contains($e->getMessage(), 'token');
        } finally {
            applySettings(['apiToken' => 'test-token']);
        }
    });

    check('a scoped call without an administration throws before any HTTP', function() use ($api) {
        applySettings(['administrationId' => '']);

        try {
            $api->request('GET', 'contacts.json');

            return 'no exception';
        } catch (ApiException $e) {
            return str_contains($e->getMessage(), 'administration');
        } finally {
            applySettings(['administrationId' => '123456789']);
        }
    });

    check('a 429 is worth retrying', fn() => (new ApiException('rate limited', 429))->isRetryable() === true);
    check('a 500 is worth retrying', fn() => (new ApiException('boom', 503))->isRetryable() === true);
    check('a 422 is not', fn() => (new ApiException('bad data', 422))->isRetryable() === false);
    check('a 404 is not', fn() => (new ApiException('gone', 404))->isRetryable() === false);
    check('a connection error with no status is', fn() => (new ApiException('timeout'))->isRetryable() === true);

    check('the base URL is Moneybird v2', fn() => justinholtweb\bird\services\Api::BASE === 'https://moneybird.com/api/v2/');

    check('a connection test without a token says so', function() use ($api) {
        applySettings(['apiToken' => '']);

        try {
            $result = $api->testConnection();

            return $result['success'] === false && str_contains($result['message'], 'token');
        } finally {
            applySettings(['apiToken' => 'test-token']);
        }
    });

    // ---------------------------------------------------------------------
    section('Webhook signatures');

    $webhooks = $plugin->getWebhooks();
    $secret = 'whsec-test';
    $body = '{"administration_id":123456789,"entity_type":"SalesInvoice"}';
    $now = 1787000000;

    $sign = static function(string $body, int $timestamp, string $secret): string {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    };

    check('a correct signature verifies', function() use ($webhooks, $body, $secret, $now, $sign) {
        return $webhooks->verify($body, $sign($body, $now, $secret), $secret, $now) === true;
    });

    check('a tampered body does not', function() use ($webhooks, $body, $secret, $now, $sign) {
        return $webhooks->verify($body . ' ', $sign($body, $now, $secret), $secret, $now) === false;
    });

    check('the wrong secret does not', function() use ($webhooks, $body, $secret, $now, $sign) {
        return $webhooks->verify($body, $sign($body, $now, 'other'), $secret, $now) === false;
    });

    check('a signature more than five minutes old does not', function() use ($webhooks, $body, $secret, $now, $sign) {
        return $webhooks->verify($body, $sign($body, $now - 400, $secret), $secret, $now) === false;
    });

    check('a signature five minutes old still does', function() use ($webhooks, $body, $secret, $now, $sign) {
        return $webhooks->verify($body, $sign($body, $now - 299, $secret), $secret, $now) === true;
    });

    check('a future timestamp beyond tolerance does not', function() use ($webhooks, $body, $secret, $now, $sign) {
        return $webhooks->verify($body, $sign($body, $now + 400, $secret), $secret, $now) === false;
    });

    check('a second v1 during a secret rotation is accepted', function() use ($webhooks, $body, $secret, $now) {
        $header = 't=' . $now
            . ',v1=' . hash_hmac('sha256', $now . '.' . $body, 'old-secret')
            . ',v1=' . hash_hmac('sha256', $now . '.' . $body, $secret);

        return $webhooks->verify($body, $header, $secret, $now) === true;
    });

    check('an empty secret verifies nothing', function() use ($webhooks, $body, $now, $sign) {
        return $webhooks->verify($body, $sign($body, $now, ''), '', $now) === false;
    });

    check('a malformed header verifies nothing', function() use ($webhooks, $body, $secret, $now) {
        return $webhooks->verify($body, 'nonsense', $secret, $now) === false;
    });

    check('a header with no timestamp verifies nothing', function() use ($webhooks, $body, $secret, $now) {
        return $webhooks->verify($body, 'v1=' . hash_hmac('sha256', $now . '.' . $body, $secret), $secret, $now) === false;
    });

    check('the five events Bird subscribes to are the ones Moneybird publishes', function() {
        $expected = [
            'sales_invoice_state_changed_to_paid',
            'sales_invoice_state_changed_to_late',
            'sales_invoice_state_changed_to_uncollectible',
            'external_sales_invoice_state_changed_to_paid',
            'external_sales_invoice_state_changed_to_late',
        ];

        return justinholtweb\bird\services\Webhooks::EVENTS === $expected;
    });

    // ---------------------------------------------------------------------
    section('Webhook handling');

    check('a paid event updates the document', function() use ($webhooks, $documents, $savedOrder, $suffix) {
        $document = $documents->getDocumentForOrder($savedOrder->id);
        $document->state = 'open';
        $document->totalPaid = 0;
        $documents->save($document);

        $result = $webhooks->handle([
            'administration_id' => 123456789,
            'entity_type' => 'SalesInvoice',
            'entity_id' => "mb-$suffix",
            'action' => 'sales_invoice_state_changed_to_paid',
            'entity' => ['state' => 'paid', 'total_paid' => '121.0', 'paid_at' => '2026-08-01', 'invoice_id' => '2026-0042'],
        ]);

        $reread = $documents->getDocumentForOrder($savedOrder->id);

        return $result['handled'] === true
            && $reread->state === 'paid'
            && $reread->invoiceNumber === '2026-0042'
            && abs($reread->totalPaid - 121.0) < 0.01
            ? true
            : json_encode([$result, $reread->state, $reread->invoiceNumber, $reread->totalPaid]);
    });

    check('an event for another administration is ignored', function() use ($webhooks, $suffix) {
        $result = $webhooks->handle([
            'administration_id' => 999,
            'entity_type' => 'SalesInvoice',
            'entity_id' => "mb-$suffix",
        ]);

        return $result['handled'] === false && str_contains($result['message'], 'another administration');
    });

    check('an entity Bird does not book is ignored', function() use ($webhooks) {
        $result = $webhooks->handle([
            'administration_id' => 123456789,
            'entity_type' => 'Contact',
            'entity_id' => '1',
        ]);

        return $result['handled'] === false;
    });

    check('an invoice Bird never created is ignored', function() use ($webhooks) {
        $result = $webhooks->handle([
            'administration_id' => 123456789,
            'entity_type' => 'SalesInvoice',
            'entity_id' => 'not-ours',
        ]);

        return $result['handled'] === false && str_contains($result['message'], 'No local document');
    });

    check('a paid invoice moves the order to the nominated status', function() use ($webhooks, $documents, $savedOrder, $suffix, $commerce, $storeId) {
        $status = $commerce->getOrderStatuses()->getAllOrderStatuses($storeId)->first();

        if ($status === null) {
            return 'no order statuses in this install';
        }

        applySettings(['paidOrderStatusHandle' => $status->handle]);

        try {
            $order = Order::find()->id($savedOrder->id)->status(null)->one();
            $order->orderStatusId = null;
            Craft::$app->getElements()->saveElement($order);

            $document = $documents->getDocumentForOrder($savedOrder->id);
            $document->state = 'open';
            $documents->save($document);

            $webhooks->handle([
                'administration_id' => 123456789,
                'entity_type' => 'SalesInvoice',
                'entity_id' => "mb-$suffix",
                'entity' => ['state' => 'paid'],
            ]);

            $reread = Order::find()->id($savedOrder->id)->status(null)->one();

            return $reread->orderStatusId === $status->id ? true : 'status is ' . var_export($reread->orderStatusId, true);
        } finally {
            applySettings(['paidOrderStatusHandle' => '']);
        }
    });

    // ---------------------------------------------------------------------
    section('Log');

    $log = $plugin->getLog();

    check('an entry is written', function() use ($log) {
        $before = $log->count();
        $log->write('invoice', ['summary' => 'Bird test entry', 'orderId' => null]);

        return $log->count() === $before + 1;
    });

    check('the webhook token is redacted out of a stored payload', function() use ($log) {
        $log->write('webhook', [
            'summary' => 'Bird redaction test',
            'request' => '{"webhook_token":"super-secret","entity_type":"SalesInvoice"}',
        ]);

        $entry = $log->getEntries(['action' => 'webhook'], 1)[0];
        $full = $log->getEntryById($entry->id);

        return str_contains((string)$full->request, '[redacted]') && !str_contains((string)$full->request, 'super-secret');
    });

    check('logging off writes nothing', function() use ($log) {
        applySettings(['loggingEnabled' => false]);

        try {
            $before = $log->count();
            $log->write('invoice', ['summary' => 'should not appear']);

            return $log->count() === $before;
        } finally {
            applySettings(['loggingEnabled' => true]);
        }
    });

    check('entries filter by level', function() use ($log) {
        $log->write('api', ['level' => 'error', 'summary' => 'Bird error entry']);

        foreach ($log->getEntries(['level' => 'error'], 20) as $entry) {
            if ($entry->level !== 'error') {
                return 'a non-error entry came back';
            }
        }

        return true;
    });

    check('pruning with a zero retention keeps everything', function() use ($log) {
        $before = $log->count();

        return $log->prune(0) === 0 && $log->count() === $before;
    });

    // ---------------------------------------------------------------------
    section('Lite');

    switchEdition(Plugin::EDITION_LITE);

    check('the edition switch takes', fn() => $plugin->isPro() === false);

    check('Lite caps log retention at a week', fn() => $plugin->getSettings()->getEffectiveLogRetentionDays() === 7);

    check('Lite stores no payloads', function() use ($log) {
        $log->write('api', ['summary' => 'Bird lite payload test', 'request' => '{"secret":"value"}']);

        $entry = $log->getEntries(['action' => 'api'], 1)[0];

        return $log->getEntryById($entry->id)->request === null;
    });

    check('Lite never treats an EU consumer as OSS', function() use ($vat, $product100) {
        applySettings(['ossEnabled' => true]);

        try {
            $order = makeOrder(['items' => [['product' => $product100, 'tax' => 19.0]], 'country' => 'DE']);

            return $vat->treatmentForOrder($order) === TaxTreatment::EU_HOME_RATE;
        } finally {
            applySettings(['ossEnabled' => false]);
        }
    });

    check('Lite credits no refunds', function() use ($sync, $savedOrder) {
        return $sync->pushRefunds($savedOrder) === [];
    });

    check('switching back to Pro restores the Pro behaviour', function() use ($plugin) {
        switchEdition(Plugin::EDITION_PRO);

        return $plugin->isPro() === true;
    });
} finally {
    section('Cleanup');

    $elements = Craft::$app->getElements();

    foreach ($createdOrders as $fixtureOrder) {
        try {
            Craft::$app->getDb()->createCommand()->delete(Table::DOCUMENTS, ['orderId' => $fixtureOrder->id])->execute();
            $elements->deleteElement($fixtureOrder, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$fixtureOrder->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdProducts as $fixtureProduct) {
        try {
            $elements->deleteElement($fixtureProduct, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$fixtureProduct->id}: {$e->getMessage()}\n";
        }
    }

    foreach ([Table::LOG, Table::CONTACTS] as $table) {
        try {
            Craft::$app->getDb()->createCommand()->delete($table)->execute();
        } catch (Throwable $e) {
            echo "  ! could not clear $table: {$e->getMessage()}\n";
        }
    }

    try {
        applySettings($originalSettings);
    } catch (Throwable $e) {
        echo "  ! could not restore settings: {$e->getMessage()}\n";
    }

    try {
        switchEdition($originalEdition);
    } catch (Throwable $e) {
        echo "  ! could not restore the plugin edition: {$e->getMessage()}\n";
    }

    echo "  ✓ fixtures removed, settings restored\n";

    echo "\n" . str_repeat('-', 60) . "\n";
    echo "  $passed passed, $failed failed\n";
    echo str_repeat('-', 60) . "\n";
}

exit($failed > 0 ? 1 : 0);
