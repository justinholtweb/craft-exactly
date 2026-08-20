<?php

namespace justinholtweb\exactly\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\exactly\Plugin;
use yii\console\ExitCode;

/**
 * Housekeeping for the connection log.
 */
class LogController extends Controller
{
    /**
     * Days of history to keep. Defaults to the configured retention.
     */
    public ?int $days = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return $actionID === 'prune' ? array_merge($options, ['days']) : $options;
    }

    /**
     * Drop entries older than the retention period.
     */
    public function actionPrune(): int
    {
        $deleted = Plugin::getInstance()->getLog()->prune($this->days);

        $this->stdout("Removed $deleted log entries.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Empty the log.
     */
    public function actionClear(): int
    {
        if (!$this->confirm('Delete every log entry?')) {
            return ExitCode::OK;
        }

        $deleted = Plugin::getInstance()->getLog()->clear();

        $this->stdout("Removed $deleted log entries.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Show the most recent entries.
     */
    public function actionTail(int $limit = 25): int
    {
        $entries = Plugin::getInstance()->getLog()->getEntries([], $limit);

        if (!$entries) {
            $this->stdout("Nothing logged yet.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        foreach (array_reverse($entries) as $entry) {
            $line = sprintf(
                "%s  %-22s %-5s %-4s %s\n",
                $entry->dateCreated?->format('Y-m-d H:i:s') ?? '',
                $entry->action,
                $entry->method ?? '',
                $entry->statusCode ?? '',
                mb_substr((string)$entry->summary, 0, 70),
            );

            // `stdout()` passes every extra argument to `Console::ansiFormat()`, so a null colour
            // is not "no colour" — it is an argument that blows up in there.
            if ($entry->level === 'error') {
                $this->stdout($line, Console::FG_RED);
            } else {
                $this->stdout($line);
            }
        }

        return ExitCode::OK;
    }
}
