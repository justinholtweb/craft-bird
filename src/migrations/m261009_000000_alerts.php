<?php

namespace justinholtweb\bird\migrations;

use craft\db\Migration;
use justinholtweb\bird\db\Table;

/**
 * Failure alerts: one latch row per incident type.
 *
 * Bird books into one Moneybird administration per install, so where Erpy keys the latch on
 * `(connectionId, incident)` this keys it on `incident` alone. The table definition lives in
 * {@see createAlertsTable()} so `Install` and this migration cannot drift apart.
 */
class m261009_000000_alerts extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::ALERTS)) {
            self::createAlertsTable($this);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::ALERTS);

        return true;
    }

    /**
     * The latch. Unique on `incident`, so there is exactly one row to compare and stamp — the
     * database, not a remembered check, is what makes "one email per incident" true when a queue
     * worker and cron both run a check in the same minute.
     */
    public static function createAlertsTable(Migration $migration): void
    {
        $migration->createTable(Table::ALERTS, [
            'id' => $migration->primaryKey(),
            'incident' => $migration->string(32)->notNull(),
            // `ok` or `open`.
            'state' => $migration->string(8)->notNull()->defaultValue('ok'),
            // Redacted, short: what the last check saw. Never a payload, an address or the token.
            'detail' => $migration->text(),
            // Pushed signals (Moneybird refusing the token). The other incidents are measured.
            'signalledAt' => $migration->dateTime()->null(),
            'signalClearedAt' => $migration->dateTime()->null(),
            'openedAt' => $migration->dateTime()->null(),
            'notifiedAt' => $migration->dateTime()->null(),
            'recoveredAt' => $migration->dateTime()->null(),
            'recoveryNotifiedAt' => $migration->dateTime()->null(),
            // Set when a recovery goes out: a reopening before this is held, not sent.
            'quietUntil' => $migration->dateTime()->null(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, Table::ALERTS, ['incident'], true);
    }
}
