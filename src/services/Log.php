<?php

namespace justinholtweb\bird\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\bird\db\Table;
use justinholtweb\bird\models\LogEntry;
use justinholtweb\bird\Plugin;

/**
 * The connection log.
 *
 * Bookkeeping integrations fail quietly: the shop keeps taking orders, Moneybird keeps looking
 * fine, and nobody notices the gap until the quarter is filed. Every call Bird makes and every
 * webhook it receives lands here with its payload, so "why is order 1042 not in Moneybird" has an
 * answer that is not a shrug.
 */
class Log extends Component
{
    /**
     * Payload bodies over this are truncated.
     */
    public const MAX_PAYLOAD = 65535;

    /**
     * @param array{
     *     level?: string,
     *     statusCode?: int|null,
     *     durationMs?: int|null,
     *     orderId?: int|null,
     *     summary?: string|null,
     *     message?: string|null,
     *     request?: string|null,
     *     response?: string|null,
     * } $data
     */
    public function write(string $action, array $data = []): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->loggingEnabled) {
            return;
        }

        $keepPayloads = $settings->logPayloads && Plugin::getInstance()->isPro();

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::LOG, [
                'action' => $action,
                'level' => $data['level'] ?? LogEntry::LEVEL_INFO,
                'statusCode' => $data['statusCode'] ?? null,
                'durationMs' => $data['durationMs'] ?? null,
                'orderId' => $data['orderId'] ?? null,
                'ip' => $this->clientIp(),
                'summary' => isset($data['summary']) ? mb_substr((string)$data['summary'], 0, 255) : null,
                'message' => $data['message'] ?? null,
                'request' => $keepPayloads ? $this->redact($this->truncate($data['request'] ?? null)) : null,
                'response' => $keepPayloads ? $this->truncate($data['response'] ?? null) : null,
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\Throwable $e) {
            // The log is diagnostics, never the point. Failing to write it must not take down the
            // invoice it was describing.
            Craft::warning('Bird could not write a log entry: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * @return LogEntry[]
     */
    public function getEntries(array $criteria = [], int $limit = 200): array
    {
        $query = (new Query())
            ->select(['id', 'action', 'level', 'statusCode', 'durationMs', 'orderId', 'ip', 'summary', 'message', 'dateCreated', 'uid'])
            ->from([Table::LOG])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);

        if (!empty($criteria['action'])) {
            $query->andWhere(['action' => $criteria['action']]);
        }

        if (!empty($criteria['level'])) {
            $query->andWhere(['level' => $criteria['level']]);
        }

        if (!empty($criteria['orderId'])) {
            $query->andWhere(['orderId' => $criteria['orderId']]);
        }

        return array_map(static fn(array $row) => new LogEntry($row), $query->all());
    }

    public function getEntryById(int $id): ?LogEntry
    {
        $row = (new Query())->from([Table::LOG])->where(['id' => $id])->one();

        return $row ? new LogEntry($row) : null;
    }

    /**
     * Drop entries older than the configured retention. Returns the number deleted.
     */
    public function prune(?int $days = null): int
    {
        $days = $days ?? Plugin::getInstance()->getSettings()->getEffectiveLogRetentionDays();

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-$days days");

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG, [
            '<', 'dateCreated', Db::prepareDateForDb($cutoff),
        ])->execute();
    }

    public function clear(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    }

    public function count(): int
    {
        return (int)(new Query())->from([Table::LOG])->count();
    }

    private function truncate(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        if (strlen($payload) <= self::MAX_PAYLOAD) {
            return $payload;
        }

        return substr($payload, 0, self::MAX_PAYLOAD) . "\n…[truncated]";
    }

    /**
     * Webhook payloads carry the token Moneybird signs with, and a support screenshot of the log
     * is the easiest way in the world to leak it.
     */
    private function redact(?string $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        return preg_replace('/("(?:webhook_token|secret|api_token)"\s*:\s*")[^"]*(")/i', '$1[redacted]$2', $payload);
    }

    private function clientIp(): ?string
    {
        $request = Craft::$app->getRequest();

        // A console request has no user IP at all.
        if ($request instanceof \craft\console\Request) {
            return null;
        }

        return $request->getUserIP();
    }
}
