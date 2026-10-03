<?php

namespace justinholtweb\exactly\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\exactly\db\Table;

/**
 * Exactly install migration.
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
        $this->dropTableIfExists(Table::ITEMS);
        $this->dropTableIfExists(Table::ACCOUNTS);
        $this->dropTableIfExists(Table::DOCUMENTS);
        $this->dropTableIfExists(Table::CONNECTIONS);

        return true;
    }

    private function createTables(): void
    {
        // The OAuth token store. Tokens are secrets and never go into project config — they are
        // encrypted with the Craft security key and live only here.
        //
        // Exact Online rotates the refresh token on *every* refresh and invalidates the old one,
        // so this row is the single source of truth for the connection and every write to it has
        // to be atomic. `refreshLock` records who is currently refreshing.
        $this->createTable(Table::CONNECTIONS, [
            'id' => $this->primaryKey(),
            // Identifies the app + region this token belongs to, so changing the client ID or
            // moving from .nl to .be does not silently reuse a token that cannot work.
            'connectionKey' => $this->string(64)->notNull(),
            'baseUrl' => $this->string(255)->notNull(),
            'accessToken' => $this->text(),
            'refreshToken' => $this->text(),
            'accessTokenExpires' => $this->dateTime(),
            'refreshTokenExpires' => $this->dateTime(),
            'division' => $this->integer(),
            'divisionName' => $this->string(255),
            'userName' => $this->string(255),
            'userEmail' => $this->string(255),
            // Rate-limit budget as of the last call, straight off the response headers.
            'dailyLimit' => $this->integer(),
            'dailyRemaining' => $this->integer(),
            // Epoch milliseconds, from `X-RateLimit-Reset`. Without it an exhausted daily budget
            // could never be seen to refill.
            'dailyReset' => $this->bigInteger(),
            'minutelyLimit' => $this->integer(),
            'minutelyRemaining' => $this->integer(),
            'minutelyReset' => $this->bigInteger(),
            'dateConnected' => $this->dateTime(),
            'dateLastCall' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // One row per (order, division). The unique index below *is* the guarantee that an order
        // can never be invoiced twice into the same administration, however many retries,
        // parallel queue jobs or impatient merchants hit the button.
        $this->createTable(Table::DOCUMENTS, [
            'id' => $this->primaryKey(),
            'orderId' => $this->integer()->notNull(),
            'division' => $this->integer()->notNull(),
            'kind' => $this->string(16)->notNull()->defaultValue('invoice'),
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'orderNumber' => $this->string(255),
            'exactInvoiceId' => $this->string(36),
            'exactEntryId' => $this->string(36),
            'exactAccountId' => $this->string(36),
            'invoiceNumber' => $this->string(64),
            'entryNumber' => $this->string(64),
            // Exact's own document status: 10 draft, 20 open, 50 processed. A *draft* invoice is
            // not in the ledger and not receivable, so anything reasoning about payment has to
            // know the difference.
            'exactStatus' => $this->integer(),
            'journal' => $this->string(16),
            'currency' => $this->string(8),
            'amount' => $this->decimal(14, 4),
            'amountExclVat' => $this->decimal(14, 4),
            'vatTreatment' => $this->string(32),
            // Lets the CP say "the order changed since it was invoiced" without diffing payloads.
            'payloadHash' => $this->string(40),
            'payload' => $this->mediumText(),
            'attempts' => $this->integer()->notNull()->defaultValue(0),
            'lastError' => $this->text(),
            'deliveryStatus' => $this->string(32),
            'paymentStatus' => $this->string(32),
            'amountPaid' => $this->decimal(14, 4),
            'dateInvoiced' => $this->dateTime(),
            'dateSent' => $this->dateTime(),
            'dateLastAttempt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Craft customer -> Exact Account. Cached rather than looked up every push, because a
        // customer lookup is a whole request out of a 60-per-minute budget.
        $this->createTable(Table::ACCOUNTS, [
            'id' => $this->primaryKey(),
            'division' => $this->integer()->notNull(),
            // Lower-cased email, or `vat:<number>` when the order matched on VAT number instead.
            'matchKey' => $this->string(255)->notNull(),
            'customerId' => $this->integer(),
            'exactAccountId' => $this->string(36)->notNull(),
            'exactAccountCode' => $this->string(32),
            'name' => $this->string(255),
            'vatNumber' => $this->string(32),
            'countryCode' => $this->string(2),
            'dateSynced' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Purchasable SKU -> Exact Item. `Item` is mandatory on a sales invoice line, so nothing
        // can be invoiced until every line resolves to one of these (or to the fallback item).
        $this->createTable(Table::ITEMS, [
            'id' => $this->primaryKey(),
            'division' => $this->integer()->notNull(),
            'sku' => $this->string(255)->notNull(),
            'purchasableId' => $this->integer(),
            'exactItemId' => $this->string(36)->notNull(),
            'exactItemCode' => $this->string(64),
            'description' => $this->string(255),
            'glAccountId' => $this->string(36),
            'dateSynced' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable(Table::LOG, [
            'id' => $this->primaryKey(),
            'action' => $this->string(32)->notNull(),
            'level' => $this->string(16)->notNull()->defaultValue('info'),
            'method' => $this->string(8),
            'endpoint' => $this->string(255),
            'statusCode' => $this->integer(),
            'durationMs' => $this->integer(),
            'orderId' => $this->integer(),
            'division' => $this->integer(),
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
        $this->createIndex(null, Table::CONNECTIONS, ['connectionKey'], true);

        // The idempotency guarantee.
        $this->createIndex(null, Table::DOCUMENTS, ['orderId', 'division', 'kind'], true);
        $this->createIndex(null, Table::DOCUMENTS, ['status'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['exactInvoiceId'], false);
        $this->createIndex(null, Table::DOCUMENTS, ['dateCreated'], false);

        $this->createIndex(null, Table::ACCOUNTS, ['division', 'matchKey'], true);
        $this->createIndex(null, Table::ACCOUNTS, ['customerId'], false);

        $this->createIndex(null, Table::ITEMS, ['division', 'sku'], true);
        $this->createIndex(null, Table::ITEMS, ['purchasableId'], false);

        $this->createIndex(null, Table::LOG, ['action'], false);
        $this->createIndex(null, Table::LOG, ['level'], false);
        $this->createIndex(null, Table::LOG, ['dateCreated'], false);
    }

    private function addForeignKeys(): void
    {
        // Deleting an order takes its ledger row with it. The Exact invoice, of course, stays —
        // an issued invoice is not Craft's to retract.
        $this->addForeignKey(null, Table::DOCUMENTS, ['orderId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
    }
}
