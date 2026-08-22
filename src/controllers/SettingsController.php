<?php

namespace justinholtweb\bird\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use justinholtweb\bird\exceptions\ApiException;
use justinholtweb\bird\Plugin;
use yii\web\Response;

/**
 * The buttons on the settings screen.
 *
 * Everything here is a read against Moneybird plus a suggestion. Nothing writes settings except
 * the webhook actions, which have to store the id and secret Moneybird only hands out once.
 */
class SettingsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('bird-manageConnection');
        $this->requireAcceptsJson();

        return true;
    }

    public function actionTestConnection(): Response
    {
        $result = Plugin::getInstance()->getApi()->testConnection();

        return $this->asJson($result);
    }

    /**
     * The administrations this token can see, for the picker.
     */
    public function actionAdministrations(): Response
    {
        try {
            $administrations = Plugin::getInstance()->getApi()->getAdministrations();
        } catch (ApiException $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }

        $options = array_map(static fn(array $administration) => [
            'value' => (string)($administration['id'] ?? ''),
            'label' => sprintf(
                '%s — %s (%s)',
                $administration['name'] ?? '?',
                $administration['country'] ?? '?',
                $administration['currency'] ?? '?'
            ),
        ], $administrations);

        return $this->asJson(['success' => true, 'options' => $options]);
    }

    /**
     * Tax rates, ledger accounts, financial accounts, workflows or document styles, as
     * `{value, label}` pairs the settings screen can drop into a select.
     */
    public function actionOptions(): Response
    {
        $type = (string)Craft::$app->getRequest()->getParam('type');
        $api = Plugin::getInstance()->getApi();

        try {
            $options = match ($type) {
                'taxRates' => array_map(static fn(array $rate) => [
                    'value' => (string)($rate['id'] ?? ''),
                    'label' => sprintf('%s — %s%%%s', $rate['name'] ?? '?', $rate['percentage'] ?? '?', !empty($rate['country']) ? ' (' . $rate['country'] . ')' : ''),
                ], $api->getTaxRates()),
                'ledgerAccounts' => array_map(static fn(array $account) => [
                    'value' => (string)($account['id'] ?? ''),
                    'label' => sprintf('%s (%s)', $account['name'] ?? '?', $account['account_type'] ?? '?'),
                ], $api->getLedgerAccounts()),
                'financialAccounts' => array_map(static fn(array $account) => [
                    'value' => (string)($account['id'] ?? ''),
                    'label' => (string)($account['name'] ?? $account['identifier'] ?? '?'),
                ], $api->getFinancialAccounts()),
                'workflows' => array_map(static fn(array $workflow) => [
                    'value' => (string)($workflow['id'] ?? ''),
                    'label' => (string)($workflow['name'] ?? '?'),
                ], $api->getWorkflows()),
                'documentStyles' => array_map(static fn(array $style) => [
                    'value' => (string)($style['id'] ?? ''),
                    'label' => (string)($style['name'] ?? '?'),
                ], $api->getDocumentStyles()),
                default => null,
            };
        } catch (ApiException $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }

        if ($options === null) {
            return $this->asJson(['success' => false, 'message' => Craft::t('bird', 'Unknown option list.')]);
        }

        return $this->asJson(['success' => true, 'options' => array_values($options)]);
    }

    /**
     * Propose a tax-rate mapping by matching percentages.
     *
     * A suggestion, never a save: getting VAT wrong is expensive, and a merchant should look at
     * the rows before they become the thing that decides what goes on a return.
     */
    public function actionSuggestTaxRates(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        try {
            $moneybirdRates = $plugin->getApi()->getTaxRates();
        } catch (ApiException $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }

        $home = $settings->getHomeCountry();
        $rows = [];
        $seen = [];

        foreach ($this->commercePercentages() as $percentage) {
            $key = $this->formatPercentage($percentage);

            if (isset($seen[$key])) {
                continue;
            }

            $match = $this->bestRateFor($moneybirdRates, $percentage, $home);

            if ($match === null) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = [
                'percentage' => $key,
                'taxRateId' => (string)$match['id'],
                'label' => (string)($match['name'] ?? ''),
            ];
        }

        // 0% is not optional even when Commerce has no zero rate: it is what a rounding line and
        // an unclassified export fall back to.
        if (!isset($seen['0'])) {
            $zero = $this->bestRateFor($moneybirdRates, 0.0, $home);

            if ($zero !== null) {
                $rows[] = ['percentage' => '0', 'taxRateId' => (string)$zero['id'], 'label' => (string)($zero['name'] ?? '')];
            }
        }

        return $this->asJson([
            'success' => true,
            'rows' => $rows,
            'reverseCharge' => $this->guessSpecialRate($moneybirdRates, ['verlegd', 'reverse', 'intracommunautair', 'intra-community']),
            'export' => $this->guessSpecialRate($moneybirdRates, ['export', 'buiten de eu', 'outside the eu', 'niet-eu', 'non-eu']),
        ]);
    }

    public function actionInstallWebhook(): Response
    {
        $this->requirePostRequest();

        if (!Plugin::getInstance()->isPro()) {
            return $this->asJson(['success' => false, 'message' => Craft::t('bird', 'Webhooks require Bird Pro.')]);
        }

        return $this->asJson(Plugin::getInstance()->getWebhooks()->install());
    }

    public function actionRemoveWebhook(): Response
    {
        $this->requirePostRequest();

        return $this->asJson(Plugin::getInstance()->getWebhooks()->remove());
    }

    // Private
    // =========================================================================

    /**
     * Every VAT percentage Commerce could charge on this store.
     *
     * @return float[]
     */
    private function commercePercentages(): array
    {
        if (!Plugin::commerceIsReady()) {
            return [21.0, 9.0, 0.0];
        }

        $percentages = [];

        foreach (Commerce::getInstance()->getTaxRates()->getAllTaxRates() as $rate) {
            if (!$rate->enabled) {
                continue;
            }

            $percentages[] = round((float)$rate->rate * 100, 2);
        }

        $percentages[] = 0.0;

        sort($percentages);

        return array_values(array_unique($percentages));
    }

    /**
     * @param array<int, array<string, mixed>> $rates
     * @return array<string, mixed>|null
     */
    private function bestRateFor(array $rates, float $percentage, string $homeCountry): ?array
    {
        $candidates = [];

        foreach ($rates as $rate) {
            if (!($rate['active'] ?? true)) {
                continue;
            }

            if (abs((float)($rate['percentage'] ?? -1) - $percentage) > 0.001) {
                continue;
            }

            $candidates[] = $rate;
        }

        if ($candidates === []) {
            return null;
        }

        // A rate for the shop's own country beats one for somebody else's.
        foreach ($candidates as $candidate) {
            if (strtoupper((string)($candidate['country'] ?? '')) === $homeCountry) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /**
     * @param array<int, array<string, mixed>> $rates
     * @param string[] $needles
     * @return array{value: string, label: string}|null
     */
    private function guessSpecialRate(array $rates, array $needles): ?array
    {
        foreach ($rates as $rate) {
            if (abs((float)($rate['percentage'] ?? -1)) > 0.001) {
                continue;
            }

            $name = mb_strtolower((string)($rate['name'] ?? ''));

            foreach ($needles as $needle) {
                if (str_contains($name, $needle)) {
                    return ['value' => (string)$rate['id'], 'label' => (string)$rate['name']];
                }
            }
        }

        return null;
    }

    private function formatPercentage(float $percentage): string
    {
        return rtrim(rtrim(number_format($percentage, 2, '.', ''), '0'), '.') ?: '0';
    }
}
