<?php

namespace justinholtweb\bird\console\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use DateTime;
use justinholtweb\bird\Plugin;
use yii\console\ExitCode;

/**
 * Booking orders into Moneybird from the command line.
 *
 * Craft lists plugin commands under `craft help`, and they run as `bird/sync/…`.
 */
class SyncController extends Controller
{
    /**
     * How many orders to work through in one run.
     */
    public int $limit = 100;

    /**
     * Only orders placed on or after this date, e.g. `2026-01-01`.
     */
    public string $since = '';

    /**
     * Report what would be booked without booking it.
     */
    public bool $dryRun = false;

    /**
     * Book an order that is already booked all over again.
     */
    public bool $force = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'backfill' => array_merge($options, ['limit', 'since', 'dryRun']),
            'order' => array_merge($options, ['force']),
            'retry' => array_merge($options, ['limit']),
            default => $options,
        };
    }

    /**
     * Send one order to Moneybird.
     *
     * @param string $reference An order number, reference or element id.
     */
    public function actionOrder(string $reference): int
    {
        $order = $this->findOrder($reference);

        if ($order === null) {
            $this->stderr("No order matches “$reference”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $result = Plugin::getInstance()->getSync()->pushOrder($order, $this->force);

        $this->stdout($result->message . "\n", $result->success ? Console::FG_GREEN : Console::FG_RED);

        return $result->success ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Credit an order's refunds as Moneybird credit notes.
     *
     * @param string $reference An order number, reference or element id.
     */
    public function actionRefunds(string $reference): int
    {
        $order = $this->findOrder($reference);

        if ($order === null) {
            $this->stderr("No order matches “$reference”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $results = Plugin::getInstance()->getSync()->pushRefunds($order);

        if ($results === []) {
            $this->stdout("Nothing to credit.\n");

            return ExitCode::OK;
        }

        $failed = 0;

        foreach ($results as $result) {
            $this->stdout($result->message . "\n", $result->success ? Console::FG_GREEN : Console::FG_RED);
            $failed += $result->success ? 0 : 1;
        }

        return $failed === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Book every completed order that is not in Moneybird yet.
     *
     * The one to run after switching Bird on, or after a spell where the queue was not running.
     */
    public function actionBackfill(): int
    {
        $sync = Plugin::getInstance()->getSync();

        if (!$sync->isReady()) {
            $this->stderr("Bird is not connected to Moneybird yet.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $since = null;

        if ($this->since !== '') {
            try {
                $since = new DateTime($this->since);
            } catch (\Throwable) {
                $this->stderr("Could not read “{$this->since}” as a date.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }
        }

        $orders = $sync->findUnbookedOrders($since, max(1, $this->limit));

        if ($orders === []) {
            $this->stdout("Everything is already in Moneybird.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout(count($orders) . " order(s) to book.\n");

        $booked = 0;
        $failed = 0;

        foreach ($orders as $order) {
            $label = $order->reference ?: $order->getShortNumber();

            if ($this->dryRun) {
                $this->stdout("  would book $label\n");
                continue;
            }

            $result = $sync->pushOrder($order);

            if ($result->success) {
                $booked++;
                $this->stdout("  ✓ $label — {$result->message}\n", Console::FG_GREEN);
            } else {
                $failed++;
                $this->stdout("  ✗ $label — {$result->message}\n", Console::FG_RED);
            }
        }

        if ($this->dryRun) {
            return ExitCode::OK;
        }

        $this->stdout("\n$booked booked, $failed failed.\n");

        return $failed === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Retry the pushes that failed and have attempts left.
     */
    public function actionRetry(): int
    {
        $results = Plugin::getInstance()->getSync()->retryFailed(max(1, $this->limit));

        if ($results === []) {
            $this->stdout("Nothing to retry.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $failed = 0;

        foreach ($results as $result) {
            $this->stdout(($result->success ? '  ✓ ' : '  ✗ ') . $result->message . "\n", $result->success ? Console::FG_GREEN : Console::FG_RED);
            $failed += $result->success ? 0 : 1;
        }

        return $failed === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * What Bird thinks the state of play is.
     */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $this->stdout('Edition:        ' . ($plugin->isPro() ? 'Pro' : 'Lite') . "\n");
        $this->stdout('Administration: ' . ($settings->getParsedAdministrationId() ?: '—') . "\n");
        $this->stdout('Document type:  ' . $settings->documentType . "\n");
        $this->stdout('Trigger:        ' . $settings->trigger . "\n");
        $this->stdout('Home country:   ' . $settings->getHomeCountry() . "\n");
        $this->stdout('Documents:      ' . $plugin->getDocuments()->count() . "\n");

        foreach ($plugin->getDocuments()->countsByState() as $state => $count) {
            $this->stdout("  $state: $count\n");
        }

        if (!$settings->isConfigured()) {
            $this->stdout("\nNot connected: set an API token and an administration id.\n", Console::FG_YELLOW);

            return ExitCode::CONFIG;
        }

        $test = $plugin->getApi()->testConnection();
        $this->stdout("\n" . $test['message'] . "\n", $test['success'] ? Console::FG_GREEN : Console::FG_RED);

        return $test['success'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * An order by reference, number or id — whichever the merchant has to hand.
     */
    private function findOrder(string $reference): ?Order
    {
        if (ctype_digit($reference)) {
            $order = Order::find()->id((int)$reference)->status(null)->one();

            if ($order instanceof Order) {
                return $order;
            }
        }

        $order = Order::find()->reference($reference)->status(null)->one();

        if ($order instanceof Order) {
            return $order;
        }

        $order = Order::find()->number($reference)->status(null)->one();

        if ($order instanceof Order) {
            return $order;
        }

        // Commerce shows a short number in the CP; people copy that.
        $order = Order::find()->shortNumber($reference)->status(null)->one();

        return $order instanceof Order ? $order : null;
    }
}
