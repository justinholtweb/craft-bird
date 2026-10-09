<?php
/**
 * Failure alerts and the Dashboard widget.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-bird/tests/integration/alerts.php
 *
 * Drives the real latch, the real mailer and the real webhook code: a failed booking, a refused
 * API token and an order that never got booked each open exactly one incident, send exactly one
 * alert, and send exactly one recovery. Moneybird is a Guzzle MockHandler handed to `Api` through
 * Craft's DI container (Api builds its client with `Craft::createGuzzleClient()`); the alert
 * webhook goes to a MockHandler after passing the SSRF guard for real (the harness has no outbound
 * network).
 *
 * Settings and edition stay in memory. Self-cleaning: fixture orders (and with them, by cascade,
 * their document rows), the latch rows and the fixture users are removed at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\mail\Mailer;
use craft\web\View;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as Psr7Response;
use justinholtweb\bird\db\Table;
use justinholtweb\bird\events\AlertEvent;
use justinholtweb\bird\models\Document;
use justinholtweb\bird\models\Settings;
use justinholtweb\bird\Plugin;
use justinholtweb\bird\services\Alerts;
use justinholtweb\bird\widgets\HealthWidget;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

// craft-penny's broken beforeSaveElement handler (see checks.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$alerts = $plugin->getAlerts();
$documents = $plugin->getDocuments();
$originalSettings = $plugin->getSettings()->toArray();
$originalEdition = $plugin->edition;
$suffix = bin2hex(random_bytes(3));
$store = Commerce::getInstance()->getStores()->getPrimaryStore();
$cleanup = ['orders' => [], 'users' => []];
$apiToken = "mb-token-$suffix-abcdef123";

// A status no other order in this harness uses, so the stall check sees only these fixtures. The
// harness is full of other plugins' completed orders, none of which are in Moneybird.
$quietStatus = null;

foreach (Commerce::getInstance()->getOrderStatuses()->getAllOrderStatuses($store->id) as $status) {
    $used = (new Query())->from('{{%commerce_orders}}')->where(['isCompleted' => true, 'orderStatusId' => $status->id])->exists();

    if (!$used) {
        $quietStatus = $status;
        break;
    }
}

$base = array_merge($originalSettings, [
    'apiToken' => $apiToken,
    'administrationId' => '123456789',
    'syncContacts' => false,
    'queueSync' => false,
    'trigger' => Settings::TRIGGER_STATUS,
    'triggerStatusHandle' => $quietStatus?->handle ?? 'none',
    // The fixture orders have no lines, so a zero total must still count.
    'skipZeroTotalOrders' => false,
    'loggingEnabled' => false,
    'alertRecipients' => 'books@example.test, owner@example.test',
    'alertWebhookUrl' => '',
    'alertWebhookSecret' => '',
    'alertWebhookFormat' => 'slack',
    'alertOnDocuments' => true,
    'alertDocumentThreshold' => 1,
    'alertOnAuthFailure' => true,
    'alertStallHours' => 6,
    'alertCooldownMinutes' => 0,
    'allowPrivateAlertWebhookHosts' => false,
]);

function withSettings(array $values = []): void
{
    global $plugin, $base;

    $settings = new Settings();
    Craft::configure($settings, array_merge($base, $values));
    $plugin->setSettings($settings->toArray());
}

// The real mailer, on Symfony's null transport: the whole compose/send path runs, but the result
// does not depend on whether the harness's Mailpit happens to be up.
Craft::$app->getMailer()->setTransport(new Symfony\Component\Mailer\Transport\NullTransport());

$mail = [];
$mailFails = false;
Event::on(Mailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $e) use (&$mailFails) {
    if ($mailFails) {
        $e->isValid = false;
    }
});
Event::on(Mailer::class, BaseMailer::EVENT_AFTER_SEND, function(MailEvent $e) use (&$mail) {
    if ($e->isSuccessful) {
        $to = (array)$e->message->getTo();
        $mail[] = [
            'to' => array_map(static fn($k, $v) => is_string($k) ? $k : (string)$v, array_keys($to), $to),
            'subject' => $e->message->getSubject(),
            'body' => quoted_printable_decode((string)$e->message->toString()),
        ];
    }
});

// The alert webhook receiver.
$history = [];
$mock = new MockHandler();
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($history));
$alerts->webhookClient = new Client(['handler' => $stack]);

/**
 * Moneybird, as a queue of canned answers. `Api::request()` builds a fresh client per call through
 * `Craft::createGuzzleClient()`, which asks the DI container for `Client` — so that is where the
 * stub goes. Cleared again with `moneybird(null)`.
 */
function moneybird(?array $responses): void
{
    if ($responses === null) {
        Craft::$container->clear(Client::class);

        return;
    }

    $handler = HandlerStack::create(new MockHandler($responses));
    Craft::$container->set(Client::class, static function($container, $params) use ($handler) {
        return new Client(array_merge($params[0] ?? [], ['handler' => $handler]));
    });
}

$reset = function(array $overrides = []) use (&$mail, &$history, $mock) {
    withSettings($overrides);
    Craft::$app->getDb()->createCommand()->delete(Table::ALERTS)->execute();
    $mail = [];
    $history = [];
    $mock->reset();
};

$latch = fn(string $incident) => (new Query())->from(Table::ALERTS)->where(['incident' => $incident])->one() ?: [];
$ago = fn(string $modify) => Db::prepareDateForDb((new DateTime())->modify($modify));

/**
 * A completed order with no lines — the alerts only ever look at its id, status and dates.
 */
$makeOrder = function(bool $quiet = true) use ($store, $suffix, $quietStatus, &$cleanup): Order {
    // Moving the order into the trigger status would fire Bird's own trigger and book it (and,
    // with no contact to invoice, fail it) — so the fixture is made with the trigger off.
    $settings = Plugin::getInstance()->getSettings()->toArray();
    withSettings(['trigger' => Settings::TRIGGER_MANUAL]);

    $order = new Order();
    $order->storeId = $store->id;
    $order->setEmail("bird-alerts-$suffix@example.nl");
    $order->currency = 'EUR';
    $order->paymentCurrency = 'EUR';
    $order->origin = Order::ORIGIN_CP;

    Craft::$app->getElements()->saveElement($order, false, true, false) or throw new RuntimeException('order');

    $order->isCompleted = true;
    $order->dateOrdered = new DateTime();

    if ($quiet && $quietStatus) {
        $order->orderStatusId = $quietStatus->id;
    }

    Craft::$app->getElements()->saveElement($order, false, true, false) or throw new RuntimeException('complete');
    $cleanup['orders'][] = $order->id;
    Plugin::getInstance()->setSettings($settings);

    return Order::find()->id($order->id)->status(null)->one();
};

/**
 * Write an invoice row through `Documents::save()`, the path every real change takes.
 */
$document = function(Order $order, string $state, array $extra = []) use ($documents): Document {
    $kind = $extra['kind'] ?? Document::KIND_INVOICE;
    $sourceKey = $extra['sourceKey'] ?? '';
    $doc = $documents->getDocumentForOrder($order->id, $kind, $sourceKey) ?? new Document([
        'orderId' => $order->id,
        'kind' => $kind,
        'sourceKey' => $sourceKey,
    ]);
    $doc->state = $state;
    $doc->lastError = $extra['lastError'] ?? null;
    $doc->moneybirdId = $extra['moneybirdId'] ?? null;
    $doc->invoiceNumber = $extra['invoiceNumber'] ?? null;

    return $documents->save($doc);
};

$booked = fn(Order $order, string $id) => $document($order, 'open', ['moneybirdId' => $id, 'invoiceNumber' => "2026-$id"]);

echo "\nBird alerts checks\n==================\n";

try {
    $plugin->edition = Plugin::EDITION_LITE;
    $reset();

    // ---------------------------------------------------------------------
    section('Settings');

    check('a fresh install saves with no recipients and no webhook (nothing is required)', function() use ($originalSettings) {
        $s = new Settings();
        Craft::configure($s, array_merge($originalSettings, ['alertRecipients' => '', 'alertWebhookUrl' => '', 'webhookSecret' => '']));

        return $s->validate() ?: Json::encode($s->getErrors());
    });

    check('a bad address is refused, and named', function() {
        $s = new Settings();
        $s->alertRecipients = 'books@example.test, not-an-address';

        return !$s->validate(['alertRecipients']) && str_contains(implode(' ', $s->getErrors('alertRecipients')), 'not-an-address') ?: Json::encode($s->getErrors());
    });

    check('an unset $ENV reference is allowed and means nobody', function() {
        $s = new Settings();
        $s->alertRecipients = '$BIRD_ALERTS_NOT_SET';
        $s->alertWebhookUrl = '$BIRD_HOOK_NOT_SET';

        return $s->validate(['alertRecipients', 'alertWebhookUrl']) && $s->recipientList() === [] ?: Json::encode($s->getErrors());
    });

    check('a non-http webhook URL is refused at save', function() {
        $s = new Settings();
        $s->alertWebhookUrl = 'ftp://hooks.example.test/x';

        return !$s->validate(['alertWebhookUrl']) ?: 'accepted';
    });

    check('recipients split on commas, semicolons and newlines, de-duplicated', function() {
        $s = new Settings();
        $s->alertRecipients = "a@example.test; b@example.test\nc@example.test, a@example.test";

        return $s->recipientList() === ['a@example.test', 'b@example.test', 'c@example.test'] ?: Json::encode($s->recipientList());
    });

    check('thresholds are bounded', function() {
        $s = new Settings();
        $s->alertDocumentThreshold = 0;
        $s->alertStallHours = -1;

        return !$s->validate(['alertDocumentThreshold', 'alertStallHours']) && $s->hasErrors('alertDocumentThreshold') && $s->hasErrors('alertStallHours') ?: 'accepted';
    });

    check('an unknown webhook format falls back to Slack rather than saving nonsense', function() {
        $s = new Settings();
        $s->alertWebhookFormat = 'carrier-pigeon';

        return $s->validate(['alertWebhookFormat']) && $s->alertWebhookFormat === 'slack' ?: $s->alertWebhookFormat;
    });

    check('the alert settings are plain attributes, so Craft persists them', function() {
        $attributes = (new Settings())->attributes();
        $want = ['alertRecipients', 'alertWebhookUrl', 'alertWebhookFormat', 'alertWebhookSecret', 'alertOnDocuments', 'alertDocumentThreshold', 'alertOnAuthFailure', 'alertStallHours', 'alertCooldownMinutes'];

        return array_diff($want, $attributes) === [] ?: implode(', ', array_diff($want, $attributes));
    });

    // ---------------------------------------------------------------------
    section('The SSRF guard on the webhook');

    foreach ([
        'http://127.0.0.1/hook' => 'loopback',
        'http://169.254.169.254/latest/meta-data/' => 'the cloud metadata service',
        'http://10.1.2.3/hook' => 'a private address',
        'http://[::1]/hook' => 'IPv6 loopback',
        'http://[::ffff:127.0.0.1]/hook' => 'IPv4-mapped loopback',
        'http://100.64.0.1/hook' => 'carrier-grade NAT',
        'ftp://93.184.215.14/hook' => 'a non-http scheme',
        'https://user:pass@93.184.215.14/hook' => 'credentials in the URL',
    ] as $url => $what) {
        check("refuses $what", fn() => is_string($alerts->webhookTarget($url)) ?: 'allowed');
    }

    check('a public address is allowed, and pinned', function() use ($alerts) {
        $t = $alerts->webhookTarget('https://93.184.215.14/hook');

        return is_array($t) && $t['addresses'] === ['93.184.215.14'] && $t['port'] === 443 ?: Json::encode($t);
    });

    check('a refused URL is never requested', function() use ($alerts, &$history) {
        $result = $alerts->postWebhook('http://127.0.0.1:8080/hook', ['text' => 'x']);

        return is_string($result) && $history === [] ?: 'requested: ' . count($history);
    });

    check('the send pins the address, refuses redirects and does not throw on a 4xx', function() use ($alerts, $mock, &$history) {
        $mock->append(new Psr7Response(404));
        $result = $alerts->postWebhook('https://93.184.215.14/hook', ['text' => 'x']);
        $options = $history[0]['options'] ?? [];
        $pin = $options['curl'][CURLOPT_RESOLVE][0] ?? '';

        return $result === 'HTTP 404' && $pin === '93.184.215.14:443:93.184.215.14' && ($options['allow_redirects'] ?? null) === false
            ?: Json::encode(['result' => $result, 'pin' => $pin]);
    });

    check('allowPrivateAlertWebhookHosts lets a LAN host through, unpinned', function() use ($alerts) {
        withSettings(['allowPrivateAlertWebhookHosts' => true]);
        $t = $alerts->webhookTarget('http://10.1.2.3/hook');
        withSettings();

        return is_array($t) && $t['addresses'] === [] ?: Json::encode($t);
    });

    // ---------------------------------------------------------------------
    section('Redaction');

    check('the API token is taken out by value', function() use ($alerts, $apiToken) {
        $out = $alerts->redact("Moneybird said: token $apiToken is unknown");

        return !str_contains($out, $apiToken) ?: $out;
    });

    check('the Moneybird webhook signing secret is taken out by value', function() use ($alerts, $suffix) {
        withSettings(['webhookSecret' => '$BIRD_ALERTS_TEST_SECRET']);
        $_SERVER['BIRD_ALERTS_TEST_SECRET'] = "mbwh-$suffix-secretvalue";
        putenv("BIRD_ALERTS_TEST_SECRET=mbwh-$suffix-secretvalue");
        // Only when no webhook is installed does the setting win; either way the secret in force goes.
        $secret = Plugin::getInstance()->getWebhooks()->signingSecret();
        $out = $alerts->redact("signed with $secret");
        putenv('BIRD_ALERTS_TEST_SECRET');
        unset($_SERVER['BIRD_ALERTS_TEST_SECRET']);
        withSettings();

        return $secret !== '' && !str_contains($out, $secret) ?: $out;
    });

    check('anything shaped like a credential is taken out by pattern', function() use ($alerts) {
        $out = $alerts->redact('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.abc {"api_key":"hunter22xyz"} password=Sup3rS3cret&x=1');

        return !str_contains($out, 'eyJhbGciOiJIUzI1NiJ9') && !str_contains($out, 'hunter22xyz') && !str_contains($out, 'Sup3rS3cret') ?: $out;
    });

    check('Bird’s own messages that reach an alert survive redaction whole, in every language', function() use ($alerts) {
        // The credential pattern eats the word after "Token " — "API-Token abgelehnt" lost its verb.
        $bad = [];

        foreach (['en', 'de', 'nl', 'nl-BE', 'fr-BE'] as $language) {
            foreach (['401: Moneybird rejected the API token.', '403: the token is missing the scope this call needs.', 'No Moneybird API token is configured.'] as $message) {
                $text = Craft::t('bird', $message, [], $language);

                if ($alerts->redact($text) !== $text) {
                    $bad[] = "$language: " . $alerts->redact($text);
                }
            }
        }

        return $bad === [] ?: implode(' | ', $bad);
    });

    check('tags are stripped and the length is capped', function() use ($alerts) {
        $out = $alerts->redact('<html><body>' . str_repeat('x', 3000) . '</body></html>');

        return !str_contains($out, '<') && mb_strlen($out) <= 500 ?: mb_strlen($out) . ' chars';
    });

    // ---------------------------------------------------------------------
    section('Failed bookings');
    $reset();

    $orderA = $makeOrder();
    $orderB = $makeOrder();

    check('a failed booking opens the incident from the write itself, and emails every recipient once', function() use ($document, $orderA, $latch, &$mail, $apiToken) {
        $document($orderA, Document::STATE_FAILED, ['lastError' => "No Moneybird tax rate is mapped for 9% (token $apiToken)"]);
        $row = $latch(Alerts::INCIDENT_DOCUMENTS);

        return ($row['state'] ?? null) === 'open' && $row['notifiedAt'] !== null && count($mail) === 1
            && $mail[0]['to'] === ['books@example.test', 'owner@example.test']
            ?: Json::encode(['row' => $row, 'mail' => count($mail)]);
    });

    check('the email says what is wrong, links the Documents screen filtered to failures, and is redacted', function() use (&$mail, $apiToken) {
        $body = $mail[0]['body'] ?? '';

        return str_contains($mail[0]['subject'], 'Orders failed to book in Moneybird')
            && str_contains($body, '1 invoices and 0 credit notes failed to book')
            && str_contains($body, 'mapped for 9%')
            && preg_match('#bird/documents\?state=failed#', $body)
            && str_contains($body, 'bird/sync/retry')
            && !str_contains($body, $apiToken)
            ?: $mail[0]['subject'] . "\n" . substr($body, -900);
    });

    check('a second failure while it is open sends nothing, but updates what the widget shows', function() use ($document, $orderB, $latch, &$mail) {
        $document($orderB, Document::STATE_FAILED, ['kind' => Document::KIND_CREDIT, 'sourceKey' => 'refund-1', 'lastError' => '422: contact_id can’t be blank']);
        $row = $latch(Alerts::INCIDENT_DOCUMENTS);

        return count($mail) === 1 && str_contains((string)$row['detail'], '1 invoices and 1 credit notes') ?: Json::encode(['row' => $row, 'mail' => count($mail)]);
    });

    check('booking one of two keeps it open (no early "recovered")', function() use ($booked, $orderA, $latch, &$mail) {
        $booked($orderA, 'mb-a');

        return $latch(Alerts::INCIDENT_DOCUMENTS)['state'] === 'open' && count($mail) === 1 ?: 'closed early';
    });

    check('forgetting the last one sends exactly one recovery', function() use ($documents, $orderB, $latch, &$mail) {
        $credit = $documents->getDocumentForOrder($orderB->id, Document::KIND_CREDIT, 'refund-1');
        $documents->deleteById((int)$credit?->id);
        $row = $latch(Alerts::INCIDENT_DOCUMENTS);

        return $row['state'] === 'ok' && $row['recoveryNotifiedAt'] !== null && count($mail) === 2 && str_contains($mail[1]['subject'], 'Recovered')
            ?: Json::encode(['row' => $row, 'mail' => array_column($mail, 'subject')]);
    });

    check('below a threshold of two, one failure opens nothing', function() use ($document, $orderA, $latch, &$mail) {
        withSettings(['alertDocumentThreshold' => 2]);
        $document($orderA, Document::STATE_FAILED, ['lastError' => 'Read timed out']);
        $state = $latch(Alerts::INCIDENT_DOCUMENTS)['state'];
        withSettings();

        return $state === 'ok' && count($mail) === 2 ?: "state $state, " . count($mail) . ' emails';
    });

    check('a reopening inside the quiet period is held, then sent once it ends', function() use ($alerts, $latch, &$mail) {
        withSettings(['alertCooldownMinutes' => 60]);
        Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['quietUntil' => Db::prepareDateForDb((new DateTime())->modify('+30 minutes'))], ['incident' => Alerts::INCIDENT_DOCUMENTS])->execute();
        $alerts->check([Alerts::INCIDENT_DOCUMENTS]);
        $held = $latch(Alerts::INCIDENT_DOCUMENTS)['state'] === 'open' && count($mail) === 2;
        Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['quietUntil' => Db::prepareDateForDb((new DateTime())->modify('-1 minute'))], ['incident' => Alerts::INCIDENT_DOCUMENTS])->execute();
        $alerts->check([Alerts::INCIDENT_DOCUMENTS]);
        withSettings();

        return $held && count($mail) === 3 && str_contains($mail[2]['body'], 'Read timed out') ?: Json::encode(['held' => $held, 'mail' => array_column($mail, 'subject')]);
    });

    check('a failed send is released and retried on the next check, not lost', function() use ($alerts, $document, $booked, $orderA, $latch, &$mail, &$mailFails) {
        $booked($orderA, 'mb-a');
        $mail = [];
        Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['quietUntil' => null], ['incident' => Alerts::INCIDENT_DOCUMENTS])->execute();
        $mailFails = true;
        $document($orderA, Document::STATE_FAILED, ['lastError' => 'Contact missing']);
        $afterFailure = $latch(Alerts::INCIDENT_DOCUMENTS);
        $mailFails = false;
        $alerts->check([Alerts::INCIDENT_DOCUMENTS]);

        return $afterFailure['state'] === 'open' && $afterFailure['notifiedAt'] === null && count($mail) === 1
            ?: Json::encode(['afterFailure' => $afterFailure, 'mail' => count($mail)]);
    });

    check('switched off, nothing opens', function() use ($alerts, $latch) {
        Craft::$app->getDb()->createCommand()->delete(Table::ALERTS)->execute();
        withSettings(['alertOnDocuments' => false]);
        $alerts->check([Alerts::INCIDENT_DOCUMENTS]);
        $state = $latch(Alerts::INCIDENT_DOCUMENTS)['state'];
        withSettings();

        return $state === 'ok' ?: $state;
    });

    check('with no token or no administration, nothing is checked at all (Bird is not switched on yet)', function() use ($alerts) {
        withSettings(['apiToken' => '']);
        $a = $alerts->check();
        withSettings(['administrationId' => '']);
        $b = $alerts->check();
        withSettings();

        return $a === [] && $b === [] ?: Json::encode([$a, $b]);
    });

    $booked($orderA, 'mb-a');

    // ---------------------------------------------------------------------
    section('Authentication');
    $reset();

    check('a 401 from Moneybird opens the auth incident at once, and the email links Settings', function() use ($plugin, $latch, &$mail, $apiToken) {
        moneybird([new Psr7Response(401, ['Content-Type' => 'application/json'], '{"error":"Token ' . $apiToken . ' is invalid"}')]);

        try {
            $plugin->getApi()->getAdministrations();
        } catch (Throwable) {
        }

        $row = $latch(Alerts::INCIDENT_AUTH);
        $body = $mail[0]['body'] ?? '';

        return ($row['state'] ?? null) === 'open' && count($mail) === 1 && str_contains($mail[0]['subject'], 'Moneybird refused the API token')
            && str_contains($body, 'settings/plugins/bird') && str_contains($body, 'HTTP 401') && !str_contains($body, $apiToken)
            ?: Json::encode(['row' => $row, 'mail' => array_column($mail, 'subject'), 'body' => $body]);
    });

    check('a second 401 does not send a second alert', function() use ($plugin, &$mail) {
        moneybird([new Psr7Response(401, [], '{}')]);

        try {
            $plugin->getApi()->getAdministrations();
        } catch (Throwable) {
        }

        return count($mail) === 1 ?: count($mail) . ' emails';
    });

    check('the next successful call recovers it', function() use ($plugin, $latch, &$mail) {
        moneybird([new Psr7Response(200, ['Content-Type' => 'application/json'], '[{"id":"123456789","name":"Testbedrijf"}]')]);
        $plugin->getApi()->getAdministrations();
        $row = $latch(Alerts::INCIDENT_AUTH);

        return $row['state'] === 'ok' && $row['signalClearedAt'] !== null && count($mail) === 2 && str_contains($mail[1]['subject'], 'Recovered')
            ?: Json::encode(['row' => $row, 'mail' => array_column($mail, 'subject')]);
    });

    check('a 403 (a token without the scope) is an authentication failure too', function() use ($plugin, $alerts, $latch) {
        // Past this process's one-minute "auth is clear" memo, as a later request would be.
        $memo = new ReflectionProperty($alerts, 'authClearAt');
        $memo->setAccessible(true);
        moneybird([new Psr7Response(403, [], '{"error":"Forbidden"}')]);

        try {
            $plugin->getApi()->getTaxRates();
        } catch (Throwable) {
        }

        $open = $latch(Alerts::INCIDENT_AUTH)['state'] === 'open';
        $memo->setValue($alerts, null);
        moneybird([new Psr7Response(200, [], '[]')]);
        $plugin->getApi()->getAdministrations();

        return $open && $latch(Alerts::INCIDENT_AUTH)['state'] === 'ok' ?: 'open=' . var_export($open, true);
    });

    check('a 422 (Moneybird refusing one invoice) is not an authentication failure', function() use ($plugin, $latch, &$mail) {
        $count = count($mail);
        moneybird([new Psr7Response(422, [], '{"error":{"contact_id":["can\'t be blank"]}}')]);

        try {
            $plugin->getApi()->createSalesInvoice([]);
        } catch (Throwable) {
        }

        return $latch(Alerts::INCIDENT_AUTH)['state'] === 'ok' && count($mail) === $count ?: Json::encode($latch(Alerts::INCIDENT_AUTH));
    });

    check('switched off, a 401 records the signal but alerts nobody', function() use ($plugin, $latch, &$mail) {
        withSettings(['alertOnAuthFailure' => false]);
        $count = count($mail);
        moneybird([new Psr7Response(401, [], '{}')]);

        try {
            $plugin->getApi()->getAdministrations();
        } catch (Throwable) {
        }

        $row = $latch(Alerts::INCIDENT_AUTH);
        withSettings();

        return $row['state'] === 'ok' && $row['signalClearedAt'] === null && count($mail) === $count ?: Json::encode($row);
    });

    moneybird(null);

    // ---------------------------------------------------------------------
    section('Stalled booking');
    $reset();

    check('the fixtures have an order status of their own (so the harness’s other orders stay out of it)', fn() => $quietStatus !== null ?: 'every status is in use; the stall checks below cannot be isolated');

    $late = $makeOrder();
    $fresh = $makeOrder();
    $elsewhere = $makeOrder(false);

    foreach ([$late, $elsewhere] as $old) {
        Craft::$app->getDb()->createCommand()->update('{{%commerce_orders}}', ['dateOrdered' => $ago('-10 hours'), 'dateUpdated' => $ago('-10 hours')], ['id' => $old->id])->execute();
        Craft::$app->getDb()->createCommand()->update('{{%elements}}', ['dateUpdated' => $ago('-10 hours')], ['id' => $old->id])->execute();
    }

    check('an order due a booking for longer than allowed is a stall; a fresh one is not, nor one in another status', function() use ($alerts, $late, $fresh, $elsewhere) {
        $ids = array_column($alerts->stalled()['orders'], 'id');

        return in_array($late->id, $ids, true) && !in_array($fresh->id, $ids, true) && !in_array($elsewhere->id, $ids, true) ?: Json::encode($ids);
    });

    check('bird/alerts/check opens it and emails once, pointing at the queue and the backfill', function() use ($alerts, $latch, &$mail) {
        $alerts->check();
        $alerts->check();
        $row = $latch(Alerts::INCIDENT_STALLED);
        $body = $mail[0]['body'] ?? '';

        return ($row['state'] ?? null) === 'open' && count($mail) === 1 && str_contains($mail[0]['subject'], 'Booking into Moneybird has stalled')
            && str_contains($body, 'queue') && str_contains($body, 'bird/sync/backfill')
            ?: Json::encode(['row' => $row, 'mail' => array_column($mail, 'subject')]);
    });

    check('the paid trigger counts from the payment, not the order', function() use ($alerts, $late, $ago) {
        // A zero-total fixture is paid as far as Commerce is concerned; only the date matters here.
        Craft::$app->getDb()->createCommand()->update('{{%commerce_orders}}', ['datePaid' => $ago('-1 hour')], ['id' => $late->id])->execute();
        withSettings(['trigger' => Settings::TRIGGER_PAID]);
        $recent = array_column($alerts->stalled()['orders'], 'id');
        Craft::$app->getDb()->createCommand()->update('{{%commerce_orders}}', ['datePaid' => $ago('-9 hours')], ['id' => $late->id])->execute();
        $old = array_column($alerts->stalled()['orders'], 'id');
        withSettings();

        return !in_array($late->id, $recent, true) && in_array($late->id, $old, true) ?: Json::encode(['recent' => in_array($late->id, $recent, true), 'old' => in_array($late->id, $old, true)]);
    });

    check('the complete trigger counts from the order date', function() use ($alerts, $late, $fresh) {
        withSettings(['trigger' => Settings::TRIGGER_COMPLETE]);
        $ids = array_column($alerts->stalled()['orders'], 'id');
        withSettings();

        return in_array($late->id, $ids, true) && !in_array($fresh->id, $ids, true) ?: Json::encode($ids);
    });

    check('the manual trigger has nothing to stall', function() use ($alerts) {
        withSettings(['trigger' => Settings::TRIGGER_MANUAL]);
        $stalled = $alerts->stalled();
        withSettings();

        return $stalled['orders'] === [] ?: count($stalled['orders']) . ' orders';
    });

    check('a zero-total order is not stalled when zero totals are skipped', function() use ($alerts, $late) {
        withSettings(['skipZeroTotalOrders' => true]);
        $ids = array_column($alerts->stalled()['orders'], 'id');
        withSettings();

        return !in_array($late->id, $ids, true) ?: 'counted';
    });

    check('alertStallHours = 0 switches it off', function() use ($alerts) {
        withSettings(['alertStallHours' => 0]);
        $stalled = $alerts->stalled();
        withSettings();

        return $stalled['orders'] === [] && $stalled['pending'] === 0 ?: Json::encode($stalled);
    });

    check('booking the order recovers it, from the document write itself', function() use ($booked, $late, $latch, &$mail) {
        $booked($late, 'mb-late');
        $row = $latch(Alerts::INCIDENT_STALLED);

        return $row['state'] === 'ok' && count($mail) === 2 && str_contains($mail[1]['subject'], 'Recovered')
            ?: Json::encode(['row' => $row, 'mail' => array_column($mail, 'subject')]);
    });

    check('a push stuck in "pending" for hours counts as stalled', function() use ($alerts, $document, $fresh) {
        $doc = $document($fresh, Document::STATE_PENDING);
        Craft::$app->getDb()->createCommand()->update(Table::DOCUMENTS, ['dateUpdated' => Db::prepareDateForDb((new DateTime())->modify('-7 hours'))], ['id' => $doc->id])->execute();
        $stalled = $alerts->stalled();
        Craft::$app->getDb()->createCommand()->delete(Table::DOCUMENTS, ['id' => $doc->id])->execute();

        return $stalled['pending'] >= 1 ?: Json::encode($stalled);
    });

    // ---------------------------------------------------------------------
    section('The webhook');
    $reset(['alertRecipients' => '', 'alertWebhookUrl' => 'https://93.184.215.14/services/T000/B000/xyz', 'alertWebhookSecret' => "whsec-$suffix"]);

    check('an incident posts a Slack message, signed, to the pinned address', function() use ($alerts, $mock, &$history, $suffix) {
        $mock->append(new Psr7Response(200));
        $alerts->noteAuthFailure('HTTP 401');
        $request = $history[0]['request'] ?? null;

        if (!$request) {
            return 'nothing posted';
        }

        $body = (string)$request->getBody();
        $payload = json_decode($body, true);
        $ts = $request->getHeaderLine('X-Bird-Timestamp');
        $expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, "whsec-$suffix");

        return str_contains($payload['text'] ?? '', 'Moneybird refused the API token') && isset($payload['blocks'])
            && hash_equals($expected, $request->getHeaderLine('X-Bird-Signature'))
            && ($history[0]['options']['curl'][CURLOPT_RESOLVE][0] ?? '') === '93.184.215.14:443:93.184.215.14'
            ?: $body;
    });

    check('a webhook that fails with no email configured is retried, not lost', function() use ($alerts, $mock, $latch, &$history) {
        $mock->append(new Psr7Response(500));
        $alerts->noteAuthSuccess();
        $owed = $latch(Alerts::INCIDENT_AUTH)['recoveryNotifiedAt'] === null;
        $mock->append(new Psr7Response(200));
        $alerts->check([Alerts::INCIDENT_AUTH]);

        return $owed && $latch(Alerts::INCIDENT_AUTH)['recoveryNotifiedAt'] !== null && count($history) === 3
            ?: Json::encode(['owed' => $owed, 'posts' => count($history)]);
    });

    check('Teams gets an Adaptive Card, JSON gets a flat event', function() use ($alerts) {
        $m = $alerts->compose(Alerts::INCIDENT_DOCUMENTS, false, '1 failed');
        $teams = $alerts->payload('teams', $m);
        $json = $alerts->payload('json', $m);

        return ($teams['attachments'][0]['content']['type'] ?? null) === 'AdaptiveCard'
            && $json['event'] === 'bird.alert.opened' && $json['incident'] === 'documents' && str_contains($json['documentsUrl'], 'bird/documents')
            ?: Json::encode([$teams, $json]);
    });

    check('a handler on EVENT_BEFORE_NOTIFY can reword or swallow an alert', function() use ($alerts) {
        $seen = null;
        $handler = function(AlertEvent $e) use (&$seen) {
            $seen = $e->subject;
            $e->isValid = false;
        };
        $alerts->on(Alerts::EVENT_BEFORE_NOTIFY, $handler);
        $result = $alerts->notify('test', false, 'x');
        $alerts->off(Alerts::EVENT_BEFORE_NOTIFY, $handler);

        return $result === true && is_string($seen) ?: 'not called';
    });

    check('sendTest reports each channel separately', function() use ($alerts, $mock) {
        withSettings(['alertWebhookUrl' => 'https://93.184.215.14/hook']);
        $mock->append(new Psr7Response(200));
        $result = $alerts->sendTest();
        withSettings(['alertRecipients' => '', 'alertWebhookUrl' => '']);
        $none = $alerts->sendTest();
        withSettings();

        return $result['email'] === true && $result['webhook'] === true && $none === ['email' => null, 'webhook' => null] ?: Json::encode([$result, $none]);
    });

    // ---------------------------------------------------------------------
    section('Dashboard widget, CP banner and settings screen');
    $reset();
    $admin = User::find()->admin()->status(null)->one();
    Craft::$app->getUser()->setIdentity($admin);

    check('the widget shows counts by state linked to the filtered screen, and open incidents', function() use ($document, $orderB) {
        $document($orderB, Document::STATE_FAILED, ['lastError' => 'rejected']);
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        $html = (string)(new HealthWidget())->getBodyHtml();
        $view->setTemplateMode($mode);

        return str_contains($html, 'Orders failed to book in Moneybird') && preg_match('#bird/documents\?state=failed#', $html) && HealthWidget::isSelectable()
            ?: substr(strip_tags($html), 0, 400);
    });

    check('it is registered with the Dashboard', fn() => in_array(HealthWidget::class, Craft::$app->getDashboard()->getAllWidgetTypes(), true) ?: 'missing');

    check('the settings screen renders the Alerts section and its test button', function() use ($plugin) {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        try {
            $html = $view->renderTemplate('bird/settings', ['plugin' => $plugin, 'settings' => $plugin->getSettings()]);
        } finally {
            $view->setTemplateMode($mode);
        }

        // The autosuggest fields render as a Vue component, so their names are not plain attributes.
        $want = ['alertRecipients', 'alertWebhookUrl', 'name="alertStallHours"', 'name="alertDocumentThreshold"', 'id="bird-test-alert"', 'id="bird-test-alert-result"'];
        $missing = array_filter($want, static fn($w) => !str_contains($html, $w));

        return $missing === [] ?: 'missing: ' . implode(', ', $missing);
    });

    check('an open incident puts a banner across the CP', function() {
        $event = new craft\events\RegisterCpAlertsEvent();
        Event::trigger(Cp::class, Cp::EVENT_REGISTER_ALERTS, $event);
        $html = implode(' ', array_filter($event->alerts, 'is_string'));

        return str_contains($html, 'Bird: Orders failed to book in Moneybird') && str_contains($html, 'bird/documents') ?: $html;
    });

    check('the banner and the widget are not for someone who cannot view documents', function() use ($suffix, &$cleanup) {
        $nobody = new User(['username' => "bird-nobody-$suffix", 'email' => "bird-nobody-$suffix@example.com"]);
        Craft::$app->getElements()->saveElement($nobody, false);
        $cleanup['users'][] = $nobody;
        Craft::$app->getUserPermissions()->saveUserPermissions($nobody->id, ['accesscp']);
        $previous = Craft::$app->getUser()->getIdentity();
        Craft::$app->getUser()->setIdentity(User::find()->id($nobody->id)->status(null)->one());

        try {
            $event = new craft\events\RegisterCpAlertsEvent();
            Event::trigger(Cp::class, Cp::EVENT_REGISTER_ALERTS, $event);
            $html = implode(' ', array_filter($event->alerts, 'is_string'));
            $body = (new HealthWidget())->getBodyHtml();
            $selectable = HealthWidget::isSelectable();
        } finally {
            Craft::$app->getUser()->setIdentity($previous);
        }

        return !str_contains($html, 'Bird:') && $body === null && !$selectable ?: Json::encode(['banner' => $html, 'body' => $body]);
    });

    $booked($orderB, 'mb-b');

    check('and it goes once the incident clears', function() {
        $event = new craft\events\RegisterCpAlertsEvent();
        Event::trigger(Cp::class, Cp::EVENT_REGISTER_ALERTS, $event);

        return !str_contains(implode(' ', array_filter($event->alerts, 'is_string')), 'Bird:') ?: 'still there';
    });

    check('with no connection configured, a stale latch shows nothing', function() use ($alerts) {
        Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['state' => 'open'], ['incident' => Alerts::INCIDENT_AUTH])->execute();
        withSettings(['apiToken' => '']);
        $open = $alerts->openIncidents();
        withSettings();
        Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['state' => 'ok'], ['incident' => Alerts::INCIDENT_AUTH])->execute();

        return $open === [] ?: Json::encode($open);
    });

    // ---------------------------------------------------------------------
    section('Over HTTP');

    $password = 'Bird-' . bin2hex(random_bytes(6));
    $viewer = new User(['username' => "bird-alerts-$suffix", 'email' => "bird-alerts-$suffix@example.com", 'newPassword' => $password]);
    Craft::$app->getElements()->saveElement($viewer, false);
    Craft::$app->getUsers()->activateUser($viewer);
    Craft::$app->getUserPermissions()->saveUserPermissions($viewer->id, ['accesscp', 'accessplugin-bird', 'bird-viewdocuments', 'bird-pushorders', 'bird-viewlog', 'bird-manageconnection']);
    $cleanup['users'][] = $viewer;

    $client = function(?string $username, ?string $password): Closure {
        $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
        $json = ['Accept' => 'application/json'];
        $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');

        if ($username !== null) {
            $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode() === 200
                or throw new RuntimeException("Could not sign in as $username");
        }

        return static fn(string $action, array $params = [], string $method = 'POST', bool $withCsrf = true) => $http->request($method, "index.php?p=admin/actions/$action", [
            'headers' => $json,
            'form_params' => $method === 'POST' ? $params + ($withCsrf ? ['CRAFT_CSRF_TOKEN' => $csrf()] : []) : null,
        ]);
    };

    check('“Send a test alert”: anonymous is refused', function() use ($client) {
        $status = $client(null, null)('bird/alerts/test')->getStatusCode();

        return in_array($status, [400, 401, 403], true) ?: "status $status";
    });

    check('“Send a test alert”: a non-admin with every Bird permission is refused', function() use ($client, $viewer, $password) {
        $status = $client($viewer->username, $password)('bird/alerts/test')->getStatusCode();

        return $status === 403 ?: "status $status";
    });

    check('“Send a test alert”: an admin without a CSRF token is refused', function() use ($client) {
        $status = $client('admin', 'claudepassword')('bird/alerts/test', [], 'POST', false)->getStatusCode();

        return $status === 400 ?: "status $status";
    });

    check('“Send a test alert”: an admin GET is refused', function() use ($client) {
        $status = $client('admin', 'claudepassword')('bird/alerts/test', [], 'GET')->getStatusCode();

        return in_array($status, [400, 405], true) ?: "status $status";
    });

    check('“Send a test alert”: an admin POST answers from the saved settings and takes no URL from the request', function() use ($client) {
        $response = $client('admin', 'claudepassword')('bird/alerts/test', ['alertWebhookUrl' => 'http://169.254.169.254/']);
        $data = json_decode((string)$response->getBody(), true);

        return $response->getStatusCode() === 200 && is_string($data['message'] ?? null) && !str_contains((string)$data['message'], '169.254')
            ?: $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 200);
    });
} finally {
    section('Cleanup');
    moneybird(null);
    Craft::$app->getUser()->setIdentity(null);

    foreach ($cleanup['orders'] as $orderId) {
        Craft::$app->getDb()->createCommand()->delete(Table::DOCUMENTS, ['orderId' => $orderId])->execute();
        Craft::$app->getElements()->deleteElementById($orderId, Order::class, null, true);
    }

    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    // Latch rows written with this run's in-memory token; the install measures its own afresh.
    Craft::$app->getDb()->createCommand()->delete(Table::ALERTS)->execute();
    $plugin->setSettings($originalSettings);
    $plugin->edition = $originalEdition;
    echo '  · removed ' . count($cleanup['orders']) . ' orders and ' . count($cleanup['users']) . " users\n";
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
