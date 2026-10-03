<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\Db;
use DateTime;
use justinholtweb\exactly\db\Table;
use justinholtweb\exactly\errors\RateLimitException;
use justinholtweb\exactly\jobs\PushOrder;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\models\LogEntry;
use justinholtweb\exactly\Plugin;

/**
 * When an order gets invoiced, and how the work is scheduled.
 *
 * The rule that shapes everything here: **nothing Exactly does may be able to stop a customer
 * paying, or stop a merchant saving an order.** Exact Online is a third-party SaaS with a
 * ten-minute token and a sixty-calls-a-minute ceiling; an order save that waits on it is an order
 * save that eventually times out during a sale.
 *
 * So the automatic triggers queue, and only an explicit human action pushes inline.
 */
class Sync extends Component
{
    /**
     * Should this order be invoiced automatically, and why not if not.
     *
     * @return array{eligible: bool, reason: string|null}
     */
    public function evaluate(Order $order): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $trigger = $settings->pushTrigger;

        if ($trigger === 'manual') {
            return ['eligible' => false, 'reason' => Craft::t('exactly', 'Automatic invoicing is switched off.')];
        }

        if (!$order->isCompleted) {
            return ['eligible' => false, 'reason' => Craft::t('exactly', 'The order is not completed.')];
        }

        if (!$plugin->getOauth()->isConnected()) {
            return ['eligible' => false, 'reason' => Craft::t('exactly', 'Exactly is not connected to Exact Online.')];
        }

        $division = $plugin->getOauth()->getDivision();

        if ($division === null || $division <= 0) {
            return ['eligible' => false, 'reason' => Craft::t('exactly', 'No division is selected.')];
        }

        $existing = $plugin->getDocuments()->getDocument((int)$order->id, $division);

        if ($existing !== null && $existing->isSent()) {
            return ['eligible' => false, 'reason' => Craft::t('exactly', 'Already invoiced.')];
        }

        if ($existing !== null && in_array($existing->status, [Document::STATUS_QUEUED, Document::STATUS_SENDING], true)) {
            return ['eligible' => false, 'reason' => Craft::t('exactly', 'Already in flight.')];
        }

        return match ($trigger) {
            'completed' => ['eligible' => true, 'reason' => null],
            'paid' => $order->getIsPaid()
                ? ['eligible' => true, 'reason' => null]
                : ['eligible' => false, 'reason' => Craft::t('exactly', 'The order is not paid yet.')],
            'status' => $this->matchesStatus($order)
                ? ['eligible' => true, 'reason' => null]
                : ['eligible' => false, 'reason' => Craft::t('exactly', 'The order status is not one of the configured triggers.')],
            default => ['eligible' => false, 'reason' => Craft::t('exactly', 'Unknown trigger.')],
        };
    }

    private function matchesStatus(Order $order): bool
    {
        $handles = array_filter((array)Plugin::getInstance()->getSettings()->triggerStatusHandles);

        if (!$handles) {
            return false;
        }

        $handle = $order->getOrderStatus()?->handle;

        return $handle !== null && in_array($handle, $handles, true);
    }

    /**
     * Handle an order that just changed. Called from the element save handler, so it must be cheap
     * and it must never throw.
     */
    public function handleOrder(Order $order): void
    {
        try {
            $evaluation = $this->evaluate($order);

            if (!$evaluation['eligible']) {
                return;
            }

            $this->schedule($order);
        } catch (\Throwable $e) {
            // An order save is not the place to surface an accounting problem.
            Craft::warning('Exactly could not schedule order ' . $order->id . ': ' . $e->getMessage(), __METHOD__);

            Plugin::getInstance()->getLog()->write('sync.schedule', [
                'level' => LogEntry::LEVEL_ERROR,
                'orderId' => $order->id,
                'summary' => Craft::t('exactly', 'Could not schedule the order for invoicing'),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Queue a credit note for a refunded order, when `creditNotesOnRefund` is on.
     *
     * Called for every transaction Commerce saves (see `Plugin::_registerAutomaticTriggers()`),
     * so it must never throw: a refund that has already
     * gone through at the gateway cannot be un-done because Exact is down, and the merchant must
     * not see an error for it. It only ever *queues* — even with the queue switched off — because
     * the refund screen is not the place to wait on Exact.
     *
     * **Only a full refund issues a credit note.** Exactly's credit note reverses the whole
     * invoice, so issuing one for a partial refund would credit money the customer still paid.
     * A partial refund is logged with the amounts and left to the bookkeeper; once later refunds
     * bring the total refunded up to the order total, that refund issues the credit note.
     *
     * Retries and duplicate events cannot double-issue: the job goes through `Documents::claim()`
     * with kind `credit-note`, and the unique index allows one per order per administration.
     *
     * @return string|null why nothing was queued, or null when a credit note was queued
     */
    public function handleRefund(Transaction $refund): ?string
    {
        $plugin = Plugin::getInstance();

        try {
            if (!$plugin->getSettings()->creditNotesOnRefund) {
                return 'off';
            }

            if ($refund->type !== TransactionRecord::TYPE_REFUND || $refund->status !== TransactionRecord::STATUS_SUCCESS) {
                return 'not a successful refund';
            }

            $order = $refund->getOrder();
            $division = $plugin->getOauth()->getDivision();

            if (!$order instanceof Order || $division === null || $division <= 0) {
                return 'no order or division';
            }

            $invoice = $plugin->getDocuments()->getDocument((int)$order->id, $division, Document::KIND_INVOICE);

            if ($invoice === null || !$invoice->isSent()) {
                return 'not invoiced';
            }

            $credit = $plugin->getDocuments()->getDocument((int)$order->id, $division, Document::KIND_CREDIT_NOTE);

            if ($credit !== null && $credit->isSent()) {
                return 'already credited';
            }

            $refunded = $this->getRefundedTotal($order, $refund);
            $total = round((float)$order->getTotalPrice(), 2);

            // The same tolerance reconciliation allows: a multi-currency refund converted back can
            // land a cent short of the total and is still a full refund.
            $tolerance = max(0.005, (float)$plugin->getSettings()->roundingTolerance);

            if ($refunded < $total - $tolerance) {
                $plugin->getLog()->write('sync.refund', [
                    'level' => LogEntry::LEVEL_WARNING,
                    'orderId' => $order->id,
                    'division' => $division,
                    'summary' => mb_substr(Craft::t('exactly', 'Order {number} was partly refunded ({refunded} of {total}), so no credit note was issued. Exactly’s credit notes reverse the whole invoice; book a partial credit in Exact Online by hand.', [
                        'number' => $plugin->getDocuments()->orderNumber($order),
                        'refunded' => number_format($refunded, 2),
                        'total' => number_format($total, 2),
                    ]), 0, 255),
                ]);

                return 'partial';
            }

            // Atomic, and no job when it fails: a credit note mid-send must not get a sibling.
            if ($credit !== null && !$plugin->getDocuments()->markQueued($credit)) {
                return $credit->isSent() ? 'already credited' : 'in flight';
            }

            Craft::$app->getQueue()->push(new PushOrder([
                'orderId' => (int)$order->id,
                'orderNumber' => $plugin->getDocuments()->orderNumber($order),
                'kind' => Document::KIND_CREDIT_NOTE,
            ]));

            return null;
        } catch (\Throwable $e) {
            Craft::warning('Exactly could not queue a credit note for refund ' . $refund->id . ': ' . $e->getMessage(), __METHOD__);

            try {
                $plugin->getLog()->write('sync.refund', [
                    'level' => LogEntry::LEVEL_ERROR,
                    'orderId' => $refund->orderId,
                    'summary' => Craft::t('exactly', 'Could not queue a credit note for the refund'),
                    'message' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
            }

            return 'error';
        }
    }

    /**
     * Everything refunded on an order so far, in the order's currency, counting this refund even
     * if Commerce has not handed it back from the database yet.
     */
    private function getRefundedTotal(Order $order, Transaction $refund): float
    {
        $total = 0.0;
        $seen = false;

        foreach (Commerce::getInstance()->getTransactions()->getAllTransactionsByOrderId((int)$order->id) as $transaction) {
            if ($transaction->type !== TransactionRecord::TYPE_REFUND || $transaction->status !== TransactionRecord::STATUS_SUCCESS) {
                continue;
            }

            $total += (float)$transaction->amount;
            $seen = $seen || ($refund->id !== null && (int)$transaction->id === (int)$refund->id);
        }

        if (!$seen) {
            $total += (float)$refund->amount;
        }

        return round($total, 2);
    }

    /**
     * Put an order on the queue (or push it inline, if the merchant switched the queue off).
     *
     * @return array{queued: bool, message: string}
     */
    public function schedule(Order $order, int $delaySeconds = 0, string $kind = Document::KIND_INVOICE): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->useQueue) {
            try {
                $result = $plugin->getInvoices()->push($order, ['kind' => $kind]);
            } catch (RateLimitException $e) {
                // The row is `failed` with the attempt uncounted, so the next retry picks it up.
                return ['queued' => false, 'message' => Invoices::rateLimitMessage($e)];
            }

            return ['queued' => false, 'message' => $result['message']];
        }

        $division = $plugin->getOauth()->getDivision();

        if ($division !== null) {
            $document = $plugin->getDocuments()->getDocument((int)$order->id, $division, $kind);

            // Atomic: a sent row, or one a worker is sending right now, is left alone and no job
            // is pushed — a queued row is one a new job may claim, and that would be a second
            // invoice beside the one in flight.
            if ($document !== null && !$plugin->getDocuments()->markQueued($document)) {
                return ['queued' => false, 'message' => $plugin->getDocuments()->queueRefusal($document)];
            }
        }

        Craft::$app->getQueue()->delay($delaySeconds)->push(new PushOrder([
            'orderId' => (int)$order->id,
            'orderNumber' => $plugin->getDocuments()->orderNumber($order),
            'kind' => $kind,
        ]));

        return [
            'queued' => true,
            'message' => Craft::t('exactly', 'Queued for Exact Online.'),
        ];
    }

    // Backfill and retries
    // -------------------------------------------------------------------------

    /**
     * Orders that have never been invoiced into the current division.
     *
     * Done as a plain query rather than an element query with an extra condition. An
     * `ElementQuery` only builds its `subQuery` during `prepare()`, so there is nothing to hang a
     * join on beforehand, and a condition set on the element query itself lands on the *outer*
     * query — which selects from that subquery and can only see `elements.id`.
     *
     * An anti-join, not a `NOT IN (…)` list: a store with 50,000 orders would otherwise build a
     * 50,000-element bound-parameter list to find the twelve that are missing.
     *
     * @return Order[]
     */
    public function getUninvoicedOrders(?DateTime $since = null, int $limit = 100): array
    {
        $division = Plugin::getInstance()->getOauth()->getDivision();

        if ($division === null) {
            return [];
        }

        $query = (new Query())
            ->select(['o.id'])
            ->from(['o' => CommerceTable::ORDERS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[o.id]]')
            ->leftJoin(
                ['d' => Table::DOCUMENTS],
                '[[d.orderId]] = [[o.id]] AND [[d.division]] = :division AND [[d.kind]] = :kind AND [[d.status]] = :status',
                [
                    ':division' => $division,
                    ':kind' => Document::KIND_INVOICE,
                    ':status' => Document::STATUS_SENT,
                ],
            )
            ->where(['d.id' => null])
            ->andWhere(['o.isCompleted' => true])
            ->andWhere(['e.dateDeleted' => null])
            ->orderBy(['o.dateOrdered' => SORT_ASC])
            ->limit($limit);

        if ($since !== null) {
            $query->andWhere(['>=', 'o.dateOrdered', Db::prepareDateForDb($since)]);
        }

        $ids = $query->column();

        if (!$ids) {
            return [];
        }

        // `status(null)` because an order's element status has nothing to do with whether it needs
        // invoicing, and the default would silently drop most of them.
        return Order::find()
            ->id($ids)
            ->status(null)
            ->fixedOrder(true)
            ->limit(null)
            ->all();
    }

    /**
     * Queue everything that has not been invoiced yet.
     *
     * @return array{queued: int, skipped: int}
     */
    public function backfill(?DateTime $since = null, int $limit = 100, bool $dryRun = false): array
    {
        $orders = $this->getUninvoicedOrders($since, $limit);
        $queued = 0;
        $skipped = 0;

        foreach ($orders as $index => $order) {
            if ($dryRun) {
                $queued++;
                continue;
            }

            try {
                // Stagger them. Sixty calls a minute is the ceiling and one invoice costs three or
                // four, so a backfill that fires everything at once spends the budget in ten
                // seconds and then fails the rest.
                $this->schedule($order, $index * 5);
                $queued++;
            } catch (\Throwable $e) {
                $skipped++;
                Craft::warning('Exactly could not queue order ' . $order->id . ': ' . $e->getMessage(), __METHOD__);
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Re-queue failed documents that still have attempts left.
     *
     * @return array{queued: int}
     */
    public function retryFailed(int $limit = 50): array
    {
        $plugin = Plugin::getInstance();
        $queued = 0;

        foreach ($plugin->getDocuments()->getRetryable($limit) as $index => $document) {
            $order = $document->getOrder();

            if (!$order instanceof Order) {
                $plugin->getDocuments()->markSkipped($document, Craft::t('exactly', 'The order no longer exists.'));
                continue;
            }

            // The row's own kind: a failed credit note is retried as a credit note.
            $this->schedule($order, $index * 5, $document->kind);
            $queued++;
        }

        return ['queued' => $queued];
    }

    /**
     * A credit note for a refunded order.
     *
     * @return array{success: bool, message: string, document: Document|null}
     */
    public function creditNote(Order $order, bool $force = false): array
    {
        $plugin = Plugin::getInstance();

        $division = $plugin->getOauth()->getDivision();
        $invoice = $division !== null
            ? $plugin->getDocuments()->getDocument((int)$order->id, $division, Document::KIND_INVOICE)
            : null;

        if ($invoice === null || !$invoice->isSent()) {
            return [
                'success' => false,
                'message' => Craft::t('exactly', 'There is no Exact Online invoice to credit for this order.'),
                'document' => null,
            ];
        }

        try {
            $result = $plugin->getInvoices()->push($order, [
                'kind' => Document::KIND_CREDIT_NOTE,
                'force' => $force,
            ]);
        } catch (RateLimitException $e) {
            return [
                'success' => false,
                'message' => Invoices::rateLimitMessage($e),
                'document' => null,
            ];
        }

        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'document' => $result['document'],
        ];
    }

    /**
     * Housekeeping: prune the log, retry what can be retried, reconcile payments.
     *
     * @return array<string, mixed>
     */
    public function runMaintenance(): array
    {
        $plugin = Plugin::getInstance();

        return [
            'pruned' => $plugin->getLog()->prune(),
            'retried' => $plugin->getSync()->retryFailed()['queued'],
            'payments' => $plugin->getPayments()->sync(),
        ];
    }

    /**
     * A one-line summary for the CP dashboard and the console.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        $plugin = Plugin::getInstance();
        $counts = $plugin->getDocuments()->getStatusCounts();

        return [
            'connected' => $plugin->getOauth()->isConnected(),
            'division' => $plugin->getOauth()->getDivision(),
            'sent' => $counts[Document::STATUS_SENT] ?? 0,
            'failed' => $counts[Document::STATUS_FAILED] ?? 0,
            'queued' => ($counts[Document::STATUS_QUEUED] ?? 0) + ($counts[Document::STATUS_SENDING] ?? 0),
            'pending' => $counts[Document::STATUS_PENDING] ?? 0,
            'trigger' => $plugin->getSettings()->pushTrigger,
            'daysUntilExpiry' => $plugin->getOauth()->getConnection()?->getDaysUntilExpiry(),
            'lastCall' => $plugin->getOauth()->getConnection()?->dateLastCall,
        ];
    }

    /**
     * Wipe every ledger row whose order no longer exists.
     *
     * The foreign key covers deletes that go through the database, so in a healthy install this
     * finds nothing — which is the point. It exists for the installs that are not healthy: a
     * partially restored database, a table imported without its constraints, a migration that
     * dropped the key and never put it back. A Documents screen listing invoices against orders
     * nobody can open is worse than one that is a row short.
     */
    public function garbageCollect(): int
    {
        $orphans = (new Query())
            ->select(['d.id'])
            ->from(['d' => Table::DOCUMENTS])
            ->leftJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[d.orderId]]')
            ->where(['e.id' => null])
            ->column();

        if (!$orphans) {
            return 0;
        }

        return (int)Craft::$app->getDb()->createCommand()
            ->delete(Table::DOCUMENTS, ['id' => $orphans])
            ->execute();
    }
}
