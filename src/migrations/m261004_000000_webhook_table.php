<?php

namespace justinholtweb\bird\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\bird\db\Table;
use justinholtweb\bird\helpers\Secret;

/**
 * Moves the webhook out of project config (5.0.2).
 *
 * Until now `install()` saved Moneybird's signing secret as a plugin setting, so it was committed
 * with the site. The webhook now lives in its own table, encrypted. A secret already stored as a
 * literal setting is copied in here so the webhook keeps verifying; the setting itself is left
 * alone — a migration that rewrote project config would fight the deploy that brings it — and the
 * settings screen asks for it to be removed.
 */
class m261004_000000_webhook_table extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::WEBHOOK)) {
            $this->createTable(Table::WEBHOOK, Install::webhookColumns($this));
        }

        $settings = Craft::$app->getProjectConfig()->get('plugins.bird.settings') ?? [];
        $webhookId = trim((string)($settings['webhookId'] ?? ''));
        $secret = trim((string)($settings['webhookSecret'] ?? ''));

        // Only a literal: an `$ENV` reference was never in project config, and keeps working as the
        // override it now is.
        if ($webhookId !== '' && $secret !== '' && !str_starts_with($secret, '$')
            && !(new \craft\db\Query())->from(Table::WEBHOOK)->exists()) {
            $now = Db::prepareDateForDb(new \DateTime());
            $this->insert(Table::WEBHOOK, [
                'webhookId' => $webhookId,
                'secret' => Secret::encrypt($secret),
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ]);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::WEBHOOK);

        return true;
    }
}
