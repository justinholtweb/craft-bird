<?php

namespace justinholtweb\bird\console\controllers;

use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\bird\exceptions\ApiException;
use justinholtweb\bird\exceptions\MappingException;
use justinholtweb\bird\models\Settings;
use justinholtweb\bird\Plugin;
use yii\console\ExitCode;

/**
 * Reading Moneybird, so a mapping can be filled in from a terminal rather than guessed at.
 */
class InspectController extends Controller
{
    /**
     * The administrations the API token can see.
     */
    public function actionAdministrations(): int
    {
        return $this->renderRows(
            static fn() => Plugin::getInstance()->getApi()->getAdministrations(),
            static fn(array $row) => sprintf('%-22s %-28s %s %s', $row['id'] ?? '?', $row['name'] ?? '?', $row['country'] ?? '??', $row['currency'] ?? '???')
        );
    }

    /**
     * Sales tax rates, with the ids the tax mapping needs.
     */
    public function actionTaxRates(): int
    {
        return $this->renderRows(
            static fn() => Plugin::getInstance()->getApi()->getTaxRates(),
            static fn(array $row) => sprintf('%-22s %6s%%  %-4s %s', $row['id'] ?? '?', $row['percentage'] ?? '?', $row['country'] ?? '', $row['name'] ?? '')
        );
    }

    /**
     * Ledger accounts, for the revenue mapping.
     */
    public function actionLedgerAccounts(): int
    {
        return $this->renderRows(
            static fn() => Plugin::getInstance()->getApi()->getLedgerAccounts(),
            static fn(array $row) => sprintf('%-22s %-22s %s', $row['id'] ?? '?', $row['account_type'] ?? '?', $row['name'] ?? '')
        );
    }

    /**
     * Financial accounts, for registering payments against.
     */
    public function actionFinancialAccounts(): int
    {
        return $this->renderRows(
            static fn() => Plugin::getInstance()->getApi()->getFinancialAccounts(),
            static fn(array $row) => sprintf('%-22s %-14s %s', $row['id'] ?? '?', $row['type'] ?? '', $row['name'] ?? $row['identifier'] ?? '')
        );
    }

    /**
     * Invoice workflows.
     */
    public function actionWorkflows(): int
    {
        return $this->renderRows(
            static fn() => Plugin::getInstance()->getApi()->getWorkflows(),
            static fn(array $row) => sprintf('%-22s %-12s %s', $row['id'] ?? '?', $row['type'] ?? '', $row['name'] ?? '')
        );
    }

    /**
     * The exact JSON Bird would post for an order, without posting it.
     *
     * The same builder the push uses, so this is the invoice — not an approximation of it.
     *
     * @param string $reference An order number, reference or element id.
     */
    public function actionPreview(string $reference): int
    {
        $order = $this->findOrder($reference);

        if ($order === null) {
            $this->stderr("No order matches “$reference”.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        $plugin = Plugin::getInstance();

        try {
            $payload = $plugin->getInvoices()->buildPayload($order, 'CONTACT_ID');
        } catch (MappingException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $wrapper = $payload['type'] === Settings::DOCUMENT_EXTERNAL_SALES_INVOICE
            ? 'external_sales_invoice'
            : 'sales_invoice';

        $this->stdout('VAT treatment: ' . $payload['treatment'] . "\n", Console::FG_YELLOW);
        $this->stdout('Order total:   ' . number_format($payload['orderTotal'], 2) . "\n");
        $this->stdout('Invoice total: ' . number_format($payload['expectedTotal'], 2) . "\n");

        if (abs($payload['rounding']) >= 0.01) {
            $this->stdout('Rounding line: ' . number_format($payload['rounding'], 2) . "\n", Console::FG_YELLOW);
        }

        $this->stdout("\n" . Json::encode([$wrapper => $payload['attributes']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return ExitCode::OK;
    }

    // Private
    // =========================================================================

    /**
     * @param callable(): array<int, array<string, mixed>> $fetch
     * @param callable(array<string, mixed>): string $format
     */
    private function renderRows(callable $fetch, callable $format): int
    {
        try {
            $rows = $fetch();
        } catch (ApiException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($rows === []) {
            $this->stdout("Nothing to show.\n");

            return ExitCode::OK;
        }

        foreach ($rows as $row) {
            $this->stdout($format($row) . "\n");
        }

        $this->stdout("\n" . count($rows) . " row(s).\n", Console::FG_GREY);

        return ExitCode::OK;
    }

    private function findOrder(string $reference): ?Order
    {
        if (ctype_digit($reference)) {
            $order = Order::find()->id((int)$reference)->status(null)->one();

            if ($order instanceof Order) {
                return $order;
            }
        }

        foreach (['reference', 'number', 'shortNumber'] as $param) {
            $order = Order::find()->$param($reference)->status(null)->one();

            if ($order instanceof Order) {
                return $order;
            }
        }

        return null;
    }
}
