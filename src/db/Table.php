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
}
