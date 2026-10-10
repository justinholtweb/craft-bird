<?php

namespace justinholtweb\bird\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\bird\exceptions\ApiException;
use justinholtweb\bird\Plugin;
use justinholtweb\bird\services\Webhooks;
use yii\console\ExitCode;

/**
 * The Moneybird webhook, from the command line.
 */
class WebhooksController extends Controller
{
    /**
     * Register the webhook that tells this site when an invoice is paid.
     */
    public function actionInstall(): int
    {
        if (!Plugin::getInstance()->isPro()) {
            $this->stderr("Webhooks require Bird Pro.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $result = Plugin::getInstance()->getWebhooks()->install();

        $this->stdout($result['message'] . "\n", $result['success'] ? Console::FG_GREEN : Console::FG_RED);

        return $result['success'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * List the webhooks on the administration.
     */
    public function actionList(): int
    {
        try {
            $webhooks = Plugin::getInstance()->getApi()->getWebhooks();
        } catch (ApiException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($webhooks === []) {
            $this->stdout("No webhooks registered.\n");

            return ExitCode::OK;
        }

        foreach ($webhooks as $webhook) {
            $this->stdout(($webhook['id'] ?? '?') . '  ' . ($webhook['url'] ?? '?') . "\n");

            foreach ($webhook['enabled_events'] ?? [] as $event) {
                $this->stdout("    $event\n", Console::FG_GREY);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Remove the webhook Bird installed.
     */
    public function actionRemove(): int
    {
        $result = Plugin::getInstance()->getWebhooks()->remove();

        $this->stdout($result['message'] . "\n", $result['success'] ? Console::FG_GREEN : Console::FG_RED);

        return $result['success'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * The events Bird subscribes to, and the URL it listens on.
     */
    public function actionInfo(): int
    {
        $settings = Plugin::getInstance()->getSettings();
        $webhooks = Plugin::getInstance()->getWebhooks();
        $installed = $webhooks->installed();

        $this->stdout('URL:    ' . $settings->getWebhookUrl() . "\n");
        $this->stdout('Id:     ' . ($webhooks->webhookId() ?: '—') . "\n");
        $this->stdout('Secret: ' . match (true) {
            $installed !== null && $installed['secret'] !== null => 'stored, encrypted',
            $installed !== null => 'stored, but can’t be decrypted (security key changed?) — remove and install again',
            $settings->getParsedWebhookSecret() !== '' => str_starts_with(trim($settings->webhookSecret), '$') ? 'from ' . trim($settings->webhookSecret) : 'in project config — remove it from the settings screen',
            default => 'not set',
        } . "\n");
        $this->stdout("Events:\n");

        foreach (Webhooks::EVENTS as $event) {
            $this->stdout("  $event\n");
        }

        return ExitCode::OK;
    }
}
