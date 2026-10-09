<?php

namespace justinholtweb\exactly\console\controllers;

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\exactly\Plugin;
use yii\console\ExitCode;

/**
 * Commerce payments as Exact Online bank or cash entries, from the command line.
 *
 *     php craft exactly/payments/preview --transaction=123   # what would be posted; posts nothing
 *     php craft exactly/payments/register --order=456        # enter that order's payments now
 *     php craft exactly/payments/retry                       # everything failed or waiting
 *     php craft exactly/payments/reconcile --days=7          # Commerce vs Exact, per day
 *
 * `exactly/sync/maintenance` already runs `retry`; this is for doing it by hand.
 */
class PaymentsController extends Controller
{
    /** The Commerce transaction ID (`preview`). */
    public ?int $transaction = null;

    /** The order ID (`register`). */
    public ?int $order = null;

    /** How many rows to try (`retry`). */
    public int $limit = 100;

    /** How many days back (`reconcile`). */
    public int $days = 30;

    public const ACTION_OPTIONS = [
        'preview' => ['transaction'],
        'register' => ['order'],
        'retry' => ['limit'],
        'reconcile' => ['days'],
    ];

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), self::ACTION_OPTIONS[$actionID] ?? []);
    }

    /**
     * Print the entry a transaction would become, without posting it.
     */
    public function actionPreview(): int
    {
        $transaction = $this->transaction ? Commerce::getInstance()->getTransactions()->getTransactionById($this->transaction) : null;

        if ($transaction === null) {
            $this->stderr("Pass --transaction=<id> of an existing Commerce transaction.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $preview = Plugin::getInstance()->getPaymentEntries()->preview($transaction);

        if ($preview['error'] !== null) {
            $this->stderr($preview['error'] . "\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        foreach ($preview['warnings'] as $warning) {
            $this->stderr('  ! ' . $warning . "\n", Console::FG_YELLOW);
        }

        $this->stdout('POST ' . $preview['endpoint'] . "\n");
        $this->stdout(json_encode($preview['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return ExitCode::OK;
    }

    /**
     * Enter one order's payments and refunds now. Anything already in Exact is skipped.
     */
    public function actionRegister(): int
    {
        $order = $this->order ? Order::find()->id($this->order)->status(null)->one() : null;

        if (!$order instanceof Order) {
            $this->stderr("Pass --order=<id> of an existing order.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        if (!Plugin::getInstance()->getSettings()->registerPayments) {
            $this->stderr("Entering payments in Exact Online is switched off.\n", Console::FG_YELLOW);

            return ExitCode::CONFIG;
        }

        $result = Plugin::getInstance()->getPaymentEntries()->registerForOrder($order);

        foreach (array_unique($result['messages']) as $message) {
            $this->stdout('  ' . $message . "\n", Console::FG_YELLOW);
        }

        $this->stdout(sprintf(
            "%d entered, %d waiting, %d failed, %d already done or skipped.\n",
            $result['sent'],
            $result['waiting'],
            $result['failed'],
            $result['skipped'],
        ), $result['failed'] ? Console::FG_YELLOW : Console::FG_GREEN);

        return $result['failed'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Re-run every payment that failed (with attempts left) or is waiting for its invoice.
     */
    public function actionRetry(): int
    {
        $result = Plugin::getInstance()->getPaymentEntries()->retryUnsent($this->limit);

        $this->stdout(sprintf(
            "%d tried: %d entered, %d waiting, %d failed.\n",
            $result['attempted'],
            $result['sent'],
            $result['waiting'],
            $result['failed'],
        ), $result['failed'] ? Console::FG_YELLOW : Console::FG_GREEN);

        return $result['failed'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Commerce's captures and refunds against Exact's entries, one line per day.
     */
    public function actionReconcile(): int
    {
        $rows = Plugin::getInstance()->getPaymentEntries()->reconciliation($this->days);
        $gaps = 0;

        $this->stdout(sprintf("%-12s %14s %14s %14s %14s %8s\n", 'Day', 'Captured', 'Entered', 'Refunded', 'Ref. entered', 'Missing'));

        foreach ($rows as $row) {
            if ($row['capturedCount'] === 0 && $row['refundedCount'] === 0) {
                continue;
            }

            $missing = $row['missing'] + $row['failed'] + $row['waiting'];
            $gaps += $missing;

            $this->stdout(sprintf(
                "%-12s %14.2f %14.2f %14.2f %14.2f %8d\n",
                $row['date'],
                $row['captured'],
                $row['entered'],
                $row['refunded'],
                $row['refundsEntered'],
                $missing,
            ), $missing ? Console::FG_YELLOW : Console::FG_GREEN);
        }

        $this->stdout(sprintf("\n%d transactions not in Exact.\n", $gaps));

        // Exit 0 either way: a gap is information, and cron would otherwise mail about it daily.
        return ExitCode::OK;
    }
}
