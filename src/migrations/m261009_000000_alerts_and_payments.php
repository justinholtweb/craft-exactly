<?php

namespace justinholtweb\exactly\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\exactly\db\Table;

/**
 * Failure alerts and payment registration (5.1.0).
 *
 * Both table definitions live in static methods so `Install` and this migration cannot drift
 * apart — a fresh install and an upgrade end up with the same columns.
 */
class m261009_000000_alerts_and_payments extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(Table::ALERTS)) {
            self::createAlertsTable($this);
        }

        if (!$this->db->tableExists(Table::PAYMENTS)) {
            self::createPaymentsTable($this);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::PAYMENTS);
        $this->dropTableIfExists(Table::ALERTS);

        return true;
    }

    /**
     * The alert latch. Unique on `incident`, so there is exactly one row to compare and stamp — the
     * database, not a remembered check, is what makes "one email per incident" true when a queue
     * worker and cron both run a check in the same minute.
     *
     * Erpy keys the same table on `(connectionId, incident)`. Exactly has one connection per
     * install, so the column would only ever hold one value.
     */
    public static function createAlertsTable(Migration $migration): void
    {
        $migration->createTable(Table::ALERTS, [
            'id' => $migration->primaryKey(),
            'incident' => $migration->string(32)->notNull(),
            // `ok` or `open`.
            'state' => $migration->string(8)->notNull()->defaultValue('ok'),
            // Redacted, short: what the last check saw. Never a payload or a credential.
            'detail' => $migration->text(),
            // Pushed signals (authentication) — the other incidents are measured, not signalled.
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

    /**
     * One row per Commerce transaction per administration. The unique index on
     * `(transactionId, division)` is the guarantee that a payment is entered in Exact's bank
     * journal once, however many queue retries, buttons and console runs ask — the same rule
     * `{{%exactly_documents}}` applies to invoices, keyed on the transaction rather than the order
     * because an order can be paid in several captures and refunded in several refunds.
     */
    public static function createPaymentsTable(Migration $migration): void
    {
        $migration->createTable(Table::PAYMENTS, [
            'id' => $migration->primaryKey(),
            'transactionId' => $migration->integer()->notNull(),
            'orderId' => $migration->integer()->notNull(),
            'division' => $migration->integer()->notNull(),
            // `payment` (a capture or purchase) or `refund`.
            'kind' => $migration->string(16)->notNull()->defaultValue('payment'),
            // pending, waiting, sending, sent, failed, skipped.
            'status' => $migration->string(16)->notNull()->defaultValue('pending'),
            // The invoice (or credit note) row it is matched against.
            'documentId' => $migration->integer(),
            'invoiceNumber' => $migration->string(64),
            'gatewayHandle' => $migration->string(255),
            'entryType' => $migration->string(8),
            'journal' => $migration->string(16),
            'currency' => $migration->string(8),
            'amount' => $migration->decimal(14, 4),
            'fee' => $migration->decimal(14, 4),
            // The Exact line description, unique per transaction. It is also what finds an entry
            // whose POST reached Exact but whose answer never came back.
            'reference' => $migration->string(64),
            'exactEntryId' => $migration->string(36),
            'entryNumber' => $migration->string(64),
            'payload' => $migration->mediumText(),
            'attempts' => $migration->integer()->notNull()->defaultValue(0),
            'lastError' => $migration->text(),
            'dateTransaction' => $migration->dateTime(),
            'dateSent' => $migration->dateTime(),
            'dateLastAttempt' => $migration->dateTime(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, Table::PAYMENTS, ['transactionId', 'division'], true);
        $migration->createIndex(null, Table::PAYMENTS, ['orderId'], false);
        $migration->createIndex(null, Table::PAYMENTS, ['status'], false);
        $migration->createIndex(null, Table::PAYMENTS, ['dateTransaction'], false);

        // Deleting an order takes its rows with it. The Exact entry, like the invoice, stays.
        $migration->addForeignKey(null, Table::PAYMENTS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
    }
}
