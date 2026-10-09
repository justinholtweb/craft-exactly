<?php

namespace justinholtweb\exactly\db;

/**
 * Exactly's database tables.
 */
abstract class Table
{
    public const CONNECTIONS = '{{%exactly_connections}}';
    public const DOCUMENTS = '{{%exactly_documents}}';
    public const ACCOUNTS = '{{%exactly_accounts}}';
    public const ITEMS = '{{%exactly_items}}';
    public const LOG = '{{%exactly_log}}';

    /** Failure-alert latches: one row per incident type. */
    public const ALERTS = '{{%exactly_alerts}}';

    /** Commerce payments and refunds registered as bank or cash entries in Exact. */
    public const PAYMENTS = '{{%exactly_payments}}';
}
