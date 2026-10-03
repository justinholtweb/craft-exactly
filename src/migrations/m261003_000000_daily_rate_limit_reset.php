<?php

namespace justinholtweb\exactly\migrations;

use craft\db\Migration;
use justinholtweb\exactly\db\Table;

/**
 * Store when the daily call budget refills.
 *
 * Without it a connection whose stored daily remaining count reached 0 refused every call
 * pre-emptively, so no response ever came back to refresh the count and the block never lifted.
 * `X-RateLimit-Reset` is epoch **milliseconds**, like the minutely one.
 */
class m261003_000000_daily_rate_limit_reset extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Table::CONNECTIONS, 'dailyReset')) {
            $this->addColumn(Table::CONNECTIONS, 'dailyReset', $this->bigInteger()->after('dailyRemaining'));
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        if ($this->db->columnExists(Table::CONNECTIONS, 'dailyReset')) {
            $this->dropColumn(Table::CONNECTIONS, 'dailyReset');
        }

        return true;
    }
}
