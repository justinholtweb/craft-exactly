<?php

namespace justinholtweb\exactly\console\controllers;

use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use DateTime;
use justinholtweb\exactly\errors\RateLimitException;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\Plugin;
use justinholtweb\exactly\services\Invoices;
use yii\console\ExitCode;

/**
 * Push orders into Exact Online from the command line.
 */
class SyncController extends Controller
{
    /**
     * Only count what would be done; change nothing.
     */
    public bool $dryRun = false;

    /**
     * How many orders to act on.
     */
    public int $limit = 100;

    /**
     * Only orders placed on or after this date (anything `DateTime` can parse).
     */
    public ?string $since = null;

    /**
     * Send inline rather than through the queue (`backfill` and `retry`).
     */
    public bool $now = false;

    /**
     * Each action registers exactly the options it reads, and no others — an option Yii accepts
     * but the action ignores is worse than an error.
     */
    public const ACTION_OPTIONS = [
        'backfill' => ['dryRun', 'limit', 'since', 'now'],
        'retry' => ['limit', 'now'],
        'payments' => ['limit'],
    ];

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), self::ACTION_OPTIONS[$actionID] ?? []);
    }

    /**
     * Send one order to Exact Online.
     */
    public function actionOrder(int $orderId): int
    {
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            $this->stderr("No order with ID $orderId.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        try {
            $result = Plugin::getInstance()->getInvoices()->push($order);
        } catch (RateLimitException $e) {
            $this->stderr(Invoices::rateLimitMessage($e) . "\n", Console::FG_YELLOW);

            return ExitCode::TEMPFAIL;
        }

        foreach ($result['warnings'] as $warning) {
            $this->stdout("  ! $warning\n", Console::FG_YELLOW);
        }

        $this->stdout($result['message'] . "\n", $result['success'] ? Console::FG_GREEN : Console::FG_RED);

        return $result['success'] || $result['skipped'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Show exactly what would be sent for an order, and send nothing.
     */
    public function actionPreview(int $orderId): int
    {
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            $this->stderr("No order with ID $orderId.\n", Console::FG_RED);

            return ExitCode::DATAERR;
        }

        try {
            $built = Plugin::getInstance()->getInvoices()->preview($order);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("VAT treatment: " . $built['treatment']['treatment'] . "\n", Console::BOLD);

        foreach ($built['warnings'] as $warning) {
            $this->stdout("  ! $warning\n", Console::FG_YELLOW);
        }

        $this->stdout("\n" . json_encode($built['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        $totals = $built['totals'];
        $this->stdout(sprintf(
            "\nExact will invoice %s (%s ex VAT + %s VAT); the customer paid %s. Difference: %s\n",
            number_format($totals['expectedTotal'], 2),
            number_format($totals['subtotalExVat'], 2),
            number_format($totals['expectedVat'], 2),
            number_format($totals['chargedTotal'], 2),
            number_format($totals['delta'], 2),
        ), abs($totals['delta']) < 0.005 ? Console::FG_GREEN : Console::FG_YELLOW);

        return ExitCode::OK;
    }

    /**
     * Queue every completed order that has never been invoiced.
     */
    public function actionBackfill(): int
    {
        $since = null;

        if ($this->since !== null) {
            try {
                $since = new DateTime($this->since);
            } catch (\Throwable) {
                $this->stderr("Could not read “{$this->since}” as a date.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }
        }

        $plugin = Plugin::getInstance();

        if ($this->now) {
            return $this->pushInline(array_map(
                static fn(Order $order) => ['order' => $order, 'kind' => Document::KIND_INVOICE],
                $plugin->getSync()->getUninvoicedOrders($since, $this->limit),
            ));
        }

        $result = $plugin->getSync()->backfill($since, $this->limit, $this->dryRun);

        $this->stdout(sprintf(
            "%s %d orders%s.\n",
            $this->dryRun ? 'Would queue' : 'Queued',
            $result['queued'],
            $result['skipped'] ? " ({$result['skipped']} skipped)" : '',
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Re-queue documents that failed and still have attempts left.
     */
    public function actionRetry(): int
    {
        $plugin = Plugin::getInstance();

        if ($this->now) {
            $orders = [];

            foreach ($plugin->getDocuments()->getRetryable($this->limit) as $document) {
                $order = $document->getOrder();

                if ($order instanceof Order) {
                    // The row's own kind: a failed credit note is retried as a credit note.
                    $orders[] = ['order' => $order, 'kind' => $document->kind];
                } else {
                    $plugin->getDocuments()->markSkipped($document, \Craft::t('exactly', 'The order no longer exists.'));
                }
            }

            $exit = $this->pushInline($orders);
            $this->checkAlerts();

            return $exit;
        }

        $result = $plugin->getSync()->retryFailed($this->limit);

        $this->stdout("Re-queued {$result['queued']} documents.\n", Console::FG_GREEN);
        $this->checkAlerts();

        return ExitCode::OK;
    }

    /**
     * Cron runs this when nothing else is running, so it is where an incident is seen to clear.
     */
    private function checkAlerts(): void
    {
        foreach (Plugin::getInstance()->getAlerts()->check() as $result) {
            if ($result['transition'] !== null) {
                $this->stdout(sprintf('Alert %s: %s%s', $result['transition'], $result['incident'], PHP_EOL), Console::FG_YELLOW);
            }
        }
    }

    /**
     * Reconcile invoice payment status against Exact's open items.
     */
    public function actionPayments(): int
    {
        $result = Plugin::getInstance()->getPayments()->sync($this->limit);

        foreach ($result['errors'] as $error) {
            $this->stderr("  ! $error\n", Console::FG_RED);
        }

        $this->stdout(sprintf(
            "%d checked — %d paid, %d still open.\n",
            $result['checked'],
            $result['paid'],
            $result['outstanding'],
        ), Console::FG_GREEN);

        return $result['errors'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Everything a cron should do: prune the log, retry failures, reconcile payments, enter the
     * payments still waiting, and check the failure alerts.
     */
    public function actionMaintenance(): int
    {
        $result = Plugin::getInstance()->getSync()->runMaintenance();

        $this->stdout(sprintf(
            "Pruned %d log entries, re-queued %d documents, checked %d invoices, entered %d payments (%d waiting, %d failed).\n",
            $result['pruned'],
            $result['retried'],
            $result['payments']['checked'],
            $result['entries']['sent'],
            $result['entries']['waiting'],
            $result['entries']['failed'],
        ), Console::FG_GREEN);

        foreach ($result['alerts'] as $alert) {
            if ($alert['transition'] !== null) {
                $this->stdout(sprintf('Alert %s: %s%s', $alert['transition'], $alert['incident'], PHP_EOL), Console::FG_YELLOW);
            }
        }

        return ExitCode::OK;
    }

    /**
     * Push orders one after another in this process (`--now`), stopping at a rate limit: every
     * order after it would be refused too, and each refusal is a call out of the same budget.
     *
     * @param array<int, array{order: Order, kind: string}> $orders
     */
    private function pushInline(array $orders): int
    {
        $plugin = Plugin::getInstance();
        $sent = 0;
        $failed = 0;

        foreach ($orders as ['order' => $order, 'kind' => $kind]) {
            if ($this->dryRun) {
                $this->stdout("  would send order {$order->id} ({$order->reference})\n");
                continue;
            }

            try {
                $result = $plugin->getInvoices()->push($order, ['kind' => $kind]);
            } catch (RateLimitException $e) {
                $this->stderr('  ! ' . Invoices::rateLimitMessage($e) . "\n", Console::FG_YELLOW);
                $this->stdout("\n$sent sent, $failed failed; stopped at the rate limit.\n", Console::FG_YELLOW);

                return ExitCode::TEMPFAIL;
            }

            if ($result['skipped']) {
                $this->stdout('  - ' . $result['message'] . "\n");
                continue;
            }

            $this->stdout(sprintf("  %s %s\n", $result['success'] ? '✓' : '✗', $result['message']));
            $result['success'] ? $sent++ : $failed++;
        }

        $this->stdout("\n$sent sent, $failed failed.\n", $failed ? Console::FG_YELLOW : Console::FG_GREEN);

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * A one-screen summary.
     */
    public function actionStatus(): int
    {
        $summary = Plugin::getInstance()->getSync()->getSummary();

        foreach ([
            'Connected' => $summary['connected'] ? 'yes' : 'no',
            'Division' => (string)($summary['division'] ?? '—'),
            'Trigger' => $summary['trigger'],
            'Sent' => (string)$summary['sent'],
            'Queued' => (string)$summary['queued'],
            'Failed' => (string)$summary['failed'],
            'Reconnect within' => $summary['daysUntilExpiry'] !== null ? $summary['daysUntilExpiry'] . ' days' : '—',
        ] as $label => $value) {
            $this->stdout(str_pad($label, 20) . $value . "\n");
        }

        return ExitCode::OK;
    }
}
