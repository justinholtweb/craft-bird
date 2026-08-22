<?php

namespace justinholtweb\bird\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\bird\db\Table;

/**
 * Bird install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::LOG);
        $this->dropTableIfExists(Table::CONTACTS);
        $this->dropTableIfExists(Table::DOCUMENTS);

        return true;
    }

    private function createTables(): void
    {
        $this->createTable(Table::DOCUMENTS, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            // `invoice` or `credit`.
            'kind' => $this->string(16)->notNull()->defaultValue('invoice'),
            // Empty for the invoice; the Commerce refund transaction hash for a credit note.
            'sourceKey' => $this->string(64)->notNull()->defaultValue(''),
            'documentType' => $this->string(32)->notNull()->defaultValue('sales_invoice'),
            // Moneybird ids are 18-digit snowflakes that arrive as JSON strings. Storing them as
            // integers would work today and silently truncate on a 32-bit build, so: strings.
            'moneybirdId' => $this->string(32),
            'invoiceNumber' => $this->string(64),
            'reference' => $this->string(128),
            'state' => $this->string(32)->notNull()->defaultValue('pending'),
            'currency' => $this->string(8),
            'total' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'totalPaid' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'administrationId' => $this->string(32),
            'taxTreatment' => $this->string(32),
            'publicUrl' => $this->text(),
            'dateSent' => $this->dateTime(),
            'datePaid' => $this->dateTime(),
            'dateSynced' => $this->dateTime(),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'lastError' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::CONTACTS, [
            'id' => $this->primaryKey(),
            'moneybirdContactId' => $this->string(32)->notNull(),
            // What Bird wrote into Moneybird's own `customer_id` field, and looks contacts up by.
            'customerId' => $this->string(128),
            'userId' => $this->integer(),
            'email' => $this->string(255),
            // A hash of the payload last pushed, so an unchanged customer costs no API call.
            'fingerprint' => $this->string(64),
            'countryCode' => $this->string(8),
            'vatNumber' => $this->string(32),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'action' => $this->string(32)->notNull(),
            'level' => $this->string(16)->notNull()->defaultValue('info'),
            'statusCode' => $this->integer(),
            'durationMs' => $this->integer(),
            // No foreign key: a log row outlives the order it describes, which is the whole point
            // of keeping one.
            'orderId' => $this->integer(),
            'ip' => $this->string(45),
            'summary' => $this->string(255),
            'message' => $this->text(),
            'request' => $this->mediumText(),
            'response' => $this->mediumText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    private function createIndexes(): void
    {
        // The idempotency guarantee. A retried queue job, a double-clicked button and a webhook
        // arriving mid-push all try to insert this triple, and only one of them can win.
        $this->createIndex(null, Table::DOCUMENTS, ['orderId', 'kind', 'sourceKey'], true);
        $this->createIndex(null, Table::DOCUMENTS, ['moneybirdId'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['state'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['reference'], false);

        $this->createIndex(null, Table::CONTACTS, ['moneybirdContactId'], true);
        $this->createIndex(null, Table::CONTACTS, ['customerId'], false);
        $this->createIndex(null, Table::CONTACTS, ['userId'], false);
        $this->createIndex(null, Table::CONTACTS, ['email'], false);

        $this->createIndex(null, Table::LOG, ['action'], false);
        $this->createIndex(null, Table::LOG, ['level'], false);
        $this->createIndex(null, Table::LOG, ['orderId'], false);
        $this->createIndex(null, Table::LOG, ['dateCreated'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::DOCUMENTS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        // A deleted Craft user must not take the Moneybird contact mapping with it: the contact
        // still exists over there, and a guest re-ordering under the same email should find it.
        $this->addForeignKey(null, Table::CONTACTS, ['userId'], CraftTable::USERS, ['id'], 'SET NULL', null);
    }
}
