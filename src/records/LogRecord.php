<?php

namespace justinholtweb\exactly\records;

use craft\db\ActiveRecord;
use justinholtweb\exactly\db\Table;

/**
 * @see Table::LOG
 */
class LogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LOG;
    }
}
