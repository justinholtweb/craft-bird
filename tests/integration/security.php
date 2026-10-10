<?php
/**
 * Where the webhook's signing secret lives, and who may wipe the log — checked in the
 * plugin-testing harness, partly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-bird/tests/integration/security.php
 *
 * Until 5.0.2 installing the webhook saved Moneybird's signing secret as a plugin setting — project
 * config, so committed with the site, where anyone with the repository could sign a "paid" webhook —
 * and failed outright on a live site with admin changes off. And "View the connection log" was
 * enough to clear it.
 *
 * Moneybird itself is never called: the `api` component is swapped for a stub in-process. Self-
 * cleaning: Bird's settings and webhook row are restored exactly as they were.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\bird\db\Table;
use justinholtweb\bird\helpers\Secret;
use justinholtweb\bird\migrations\m261004_000000_webhook_table;
use justinholtweb\bird\models\Settings;
use justinholtweb\bird\Plugin;
use justinholtweb\bird\services\Api;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

// craft-penny's broken beforeSaveElement handler (see checks.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$bird = Plugin::getInstance();
$webhooks = $bird->getWebhooks();
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'Bird-' . bin2hex(random_bytes(6));
$db = Craft::$app->getDb();
$settingsBefore = Craft::$app->getProjectConfig()->get('plugins.bird.settings');
$rowsBefore = (new Query())->from(Table::WEBHOOK)->all();
$cleanup = ['users' => []];

$reloadConfig = static function() {
    Craft::$app->getInfo()->configVersion = (string)(new Query())->select('configVersion')->from('{{%info}}')->scalar();
    Craft::$app->getProjectConfig()->reset();
};

$storeSettings = static function(array $settings, string $why) use ($reloadConfig) {
    $reloadConfig();
    $pc = Craft::$app->getProjectConfig();
    $pc->set('plugins.bird.settings', $settings, $why);
    $pc->saveModifiedConfigData();
    $pc->writeYamlFiles(true);
    Plugin::getInstance()->getSettings()->setAttributes(\craft\helpers\ProjectConfig::unpackAssociativeArray($settings), false);
};

$stored = static function(string $key) use ($reloadConfig) {
    $reloadConfig();

    return Craft::$app->getProjectConfig()->get("plugins.bird.settings.$key");
};

register_shutdown_function(function() use (&$cleanup, $db, $rowsBefore, $settingsBefore, $storeSettings, $reloadConfig) {
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
    $db->createCommand()->delete(Table::WEBHOOK)->execute();
    foreach ($rowsBefore as $row) {
        $db->createCommand()->insert(Table::WEBHOOK, $row)->execute();
    }
    $db->createCommand()->delete(Table::LOG, ['like', 'summary', 'bird-security'])->execute();
    $reloadConfig();
    if (Craft::$app->getProjectConfig()->get('plugins.bird.settings') !== $settingsBefore) {
        $storeSettings($settingsBefore, 'Restore after security.php');
    }
});

function client(string $username, string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
    $login = $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]]);

    if ($login->getStatusCode() !== 200) {
        echo "Could not sign in as $username\n";
        exit(1);
    }

    $post = static fn(string $action, array $params) => $http->post("index.php?p=admin/actions/$action", [
        'headers' => $json,
        'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf()],
    ]);

    return [$http, $post];
}

/** A Moneybird that answers the two webhook calls and nothing else. */
$stub = new class() extends Api {
    public string $secret = '';
    public array $deleted = [];

    public function createWebhook(string $url, array $events): array
    {
        return ['id' => 'bird-security-hook', 'secret' => $this->secret];
    }

    public function deleteWebhook(string $id): void
    {
        $this->deleted[] = $id;
    }
};
$stub->secret = 'mbsec_' . bin2hex(random_bytes(16));
$bird->set('api', $stub);

// Pretend to be connected, in memory only, so install() gets past its first check.
$settings = $bird->getSettings();
$settings->apiToken = 'bird-security-token';
$settings->administrationId = '123456';
$settings->webhooksEnabled = false;
$settings->webhookSecret = '';
$settings->webhookId = '';

$db->createCommand()->delete(Table::WEBHOOK)->execute();

echo "\nInstalling\n";

$configBefore = Craft::$app->getProjectConfig()->get('plugins.bird.settings');
$result = $webhooks->install();

check('install succeeds without touching project config', function() use ($result, $stored, $configBefore) {
    $reloadAll = Craft::$app->getProjectConfig()->get('plugins.bird.settings');

    return ($result['success'] ?? false) && $reloadAll === $configBefore && ($stored('webhookSecret') ?? '') === ''
        ?: json_encode(['result' => $result, 'config changed' => $reloadAll !== $configBefore]);
});

check('…and hands the secret to nobody', fn() => !array_key_exists('secret', $result) ?: 'secret in the result');

check('the secret is stored encrypted, in Bird’s own table', function() use ($stub) {
    $raw = (string)(new Query())->select('secret')->from(Table::WEBHOOK)->scalar();

    return $raw !== '' && !str_contains($raw, $stub->secret) && Secret::decrypt($raw) === $stub->secret ?: 'raw: ' . substr($raw, 0, 40);
});

check('deliveries are verified with it, and accepted with "Accept webhooks" off', function() use ($webhooks, $stub) {
    return $webhooks->signingSecret() === $stub->secret && $webhooks->isAccepting() && $webhooks->webhookId() === 'bird-security-hook' ?: 'not wired';
});

echo "\nOver HTTP\n";

$anon = new Client(['base_uri' => 'http://localhost/', 'http_errors' => false, 'allow_redirects' => false]);
$deliver = static function(string $secret) use ($anon, $run) {
    $body = json_encode(['entity_type' => 'Contact', 'action' => 'bird-security-' . $run, 'administration_id' => '123456']);
    $t = (string)time();

    return $anon->post('index.php?p=actions/bird/webhook/receive', [
        'headers' => ['Content-Type' => 'application/json', 'Moneybird-Signature' => "t=$t,v1=" . hash_hmac('sha256', "$t.$body", $secret)],
        'body' => $body,
    ])->getStatusCode();
};

check('a delivery signed with the stored secret is accepted', fn() => ($s = $deliver($stub->secret)) === 200 ?: "status $s");
check('one signed with anything else is refused', fn() => ($s = $deliver('wrong-' . $stub->secret)) === 401 ?: "status $s");

echo "\nRemoving\n";

check('remove deletes the row and the Moneybird webhook, without touching project config', function() use ($webhooks, $stub, $configBefore) {
    $result = $webhooks->remove();

    return ($result['success'] ?? false) && in_array('bird-security-hook', $stub->deleted, true)
        && !(new Query())->from(Table::WEBHOOK)->exists() && Craft::$app->getProjectConfig()->get('plugins.bird.settings') === $configBefore
        && !$webhooks->isAccepting()
        ?: json_encode($result);
});

echo "\nA secret stored before 5.0.2\n";

$legacySecret = 'mbsec_legacy_' . bin2hex(random_bytes(12));
$storeSettings(['webhookId' => 'bird-legacy-hook', 'webhookSecret' => $legacySecret] + $settingsBefore, 'A literal secret, as 5.0.1 stored it');

check('the migration copies it into the table, encrypted', function() use ($legacySecret, $webhooks) {
    $db = Craft::$app->getDb();
    $db->createCommand()->delete(Table::WEBHOOK)->execute();
    (new m261004_000000_webhook_table())->safeUp();
    $installed = $webhooks->installed();

    return $installed !== null && $installed['webhookId'] === 'bird-legacy-hook' && $installed['secret'] === $legacySecret ?: json_encode($installed);
});

check('…but not an environment-variable reference, which was never in config', function() use ($storeSettings, $settingsBefore) {
    $db = Craft::$app->getDb();
    $db->createCommand()->delete(Table::WEBHOOK)->execute();
    $storeSettings(['webhookId' => 'bird-env-hook', 'webhookSecret' => '$BIRD_WEBHOOK_SECRET'] + $settingsBefore, 'An env reference');
    (new m261004_000000_webhook_table())->safeUp();

    return !(new Query())->from(Table::WEBHOOK)->exists() ?: 'copied';
});

$storeSettings(['webhookId' => 'bird-legacy-hook', 'webhookSecret' => $legacySecret] + $settingsBefore, 'A literal secret again');

$model = static function(string $secret): Settings {
    $s = new Settings();
    $s->webhookSecret = $secret;

    return $s;
};

check('the stored literal still validates, so other settings save', fn() => $model($legacySecret)->validate(['webhookSecret']) ?: 'refused');
check('a new literal secret is refused', fn() => !$model('mbsec_new_' . $run)->validate(['webhookSecret']) ?: 'accepted');
check('an environment variable is fine', fn() => $model('$BIRD_WEBHOOK_SECRET')->validate(['webhookSecret']) ?: 'refused');

check('an empty field posted with the kept flag leaves it alone', function() use ($legacySecret) {
    $s = new Settings();
    $s->webhookSecret = $legacySecret;
    $s->setAttributes(['webhookSecret' => '', 'webhookSecretKept' => '1', 'maxAttempts' => '7'], false);

    return $s->webhookSecret === $legacySecret && (int)$s->maxAttempts === 7 ?: 'changed';
});

check('…unless “Remove it from project config” is ticked', function() use ($legacySecret) {
    $s = new Settings();
    $s->webhookSecret = $legacySecret;
    $s->setAttributes(['webhookSecret' => '', 'webhookSecretKept' => '1', 'webhookSecretRemove' => '1'], false);

    return $s->webhookSecret === '' ?: 'kept';
});

[$adminHttp, $adminPost] = client('admin', 'claudepassword');

check('the settings screen never renders it, and says to remove it', function() use ($adminHttp, $legacySecret) {
    $body = (string)$adminHttp->get('index.php?p=admin/settings/plugins/bird')->getBody();

    return !str_contains($body, $legacySecret) && str_contains($body, 'Remove it from project config') && str_contains($body, 'webhookSecretKept')
        ?: json_encode(['shown' => str_contains($body, $legacySecret), 'checkbox' => str_contains($body, 'Remove it from project config'), 'status' => strlen($body)]);
});

check('every element the settings script looks up is on the page — the buttons work', function() use ($adminHttp) {
    // Before 5.0.2 the script asked for `bird-…` while Craft's namespace rendered `settings-bird-…`,
    // so every button on the screen did nothing.
    $body = (string)$adminHttp->get('index.php?p=admin/settings/plugins/bird')->getBody();
    preg_match_all("/byId\\('([\\w-]+)'\\)/", $body, $m);
    $ids = array_unique(array_merge($m[1], ['bird-test', 'bird-install-webhook', 'bird-remove-webhook', 'bird-webhook-result']));
    // Craft namespaces every id on this screen, so that is the spelling the lookup has to try.
    $missing = array_values(array_filter($ids, static fn($id) => !str_contains($body, 'id="settings-' . $id . '"')));
    $namespaced = str_contains($body, "document.getElementById('settings-' + id)");

    return count($m[1]) >= 4 && $missing === [] && $namespaced ?: 'lookups ' . count($m[1]) . ', missing ' . json_encode($missing) . ', namespaced lookup ' . var_export($namespaced, true);
});

$saveSettings = static fn(array $values) => $adminPost('plugins/save-plugin-settings', ['pluginHandle' => 'bird', 'settings' => $values]);

check('saving the screen as rendered keeps it', function() use ($saveSettings, $stored, $legacySecret) {
    $status = $saveSettings(['webhookSecret' => '', 'webhookSecretKept' => '1'])->getStatusCode();

    return $status === 200 && $stored('webhookSecret') === $legacySecret ?: "status $status";
});

check('ticking the box takes it out of project config', function() use ($saveSettings, $stored) {
    $status = $saveSettings(['webhookSecret' => '', 'webhookSecretKept' => '1', 'webhookSecretRemove' => '1'])->getStatusCode();

    return $status === 200 && ($stored('webhookSecret') ?? '') === '' ?: "status $status, stored " . var_export($stored('webhookSecret'), true);
});

echo "\nThe connection log\n";

$viewer = new User(['username' => "bird-viewer-$run", 'email' => "bird-viewer-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($viewer, false);
Craft::$app->getUsers()->activateUser($viewer);
Craft::$app->getUserPermissions()->saveUserPermissions($viewer->id, ['accesscp', 'accessplugin-bird', 'bird-viewlog']);
$cleanup['users'][] = $viewer;
[$viewerHttp, $viewerPost] = client($viewer->username, $password);

$bird->getLog()->write('webhook', ['level' => 'info', 'summary' => "bird-security $run"]);
$logRows = static fn() => (int)(new Query())->from(Table::LOG)->where(['like', 'summary', 'bird-security'])->count();

check('“View the connection log” alone can’t clear it', function() use ($viewerPost, $logRows) {
    $status = $viewerPost('bird/log/clear', [])->getStatusCode();

    return $status === 403 && $logRows() > 0 ?: "status $status, rows " . $logRows();
});

check('…or prune it', function() use ($viewerPost, $logRows) {
    $status = $viewerPost('bird/log/prune', ['days' => 1])->getStatusCode();

    return $status === 403 ?: "status $status";
});

check('…and isn’t offered the button', function() use ($viewerHttp) {
    $body = (string)$viewerHttp->get('index.php?p=admin/bird/log')->getBody();

    return str_contains($body, 'Connection log') && !str_contains($body, 'id="bird-prune"') ?: 'button shown';
});

check('an admin can prune', fn() => ($s = $adminPost('bird/log/prune', ['days' => 3650])->getStatusCode()) === 200 ?: "status $s");

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
