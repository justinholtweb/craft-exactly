<?php

namespace justinholtweb\exactly\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use justinholtweb\exactly\errors\RateLimitException;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\Plugin;

/**
 * Send one order to Exact Online, off the request.
 *
 * Two things this job is careful about:
 *
 * 1. **A rate limit is not a failure.** Exact allows a fixed number of calls per minute per
 *    administration; a backfill hits that ceiling by design. Burning one of the five retry
 *    attempts on "come back in forty seconds" would exhaust the budget on nothing. So a
 *    `RateLimitException` re-queues the order with a delay and this job reports success.
 * 2. **"Already invoiced" is not a failure either.** `Invoices::push()` returns `skipped` for an
 *    order another process got to first, and a job that threw on that would retry forever against
 *    a condition that can never change.
 */
class PushOrder extends BaseJob
{
    public int $orderId;
    public ?string $orderNumber = null;

    /**
     * Whether a rate-limited push may re-queue itself. Set false on the re-queued copy so a
     * permanently exhausted daily budget cannot produce an endless chain of jobs.
     */
    public bool $mayRequeue = true;

    /**
     * `invoice`, or `credit-note` for the credit note queued when an order is refunded. Either
     * way it lands in `Documents::claim()`, so a retried or duplicated job cannot issue twice.
     */
    public string $kind = Document::KIND_INVOICE;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        $order = Order::find()->id($this->orderId)->status(null)->one();

        if (!$order instanceof Order) {
            // The order was deleted between queueing and running. Nothing to do, and nothing wrong.
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('exactly', 'Building the invoice'));

        if ($this->kind === Document::KIND_CREDIT_NOTE) {
            $division = $plugin->getOauth()->getDivision();
            $invoice = $division !== null ? $plugin->getDocuments()->getDocument($this->orderId, $division) : null;

            // The invoice could have been un-tracked between queueing and running. A credit note
            // against nothing is not something to retry — but a credit row this job's queueing
            // left `queued` must not stay that way for ever (no retry path takes `queued`), so it
            // is closed as `skipped`, with the reason, where the Documents screen shows it.
            if ($invoice === null || !$invoice->isSent()) {
                $credit = $division !== null
                    ? $plugin->getDocuments()->getDocument($this->orderId, $division, Document::KIND_CREDIT_NOTE)
                    : null;

                if ($credit !== null) {
                    $plugin->getDocuments()->markSkipped(
                        $credit,
                        Craft::t('exactly', 'There is no Exact Online invoice to credit for this order.'),
                        Document::STATUS_QUEUED,
                    );
                }

                return;
            }
        }

        try {
            $result = $plugin->getInvoices()->push($order, ['kind' => $this->kind]);
        } catch (RateLimitException $e) {
            // `push()` has already released the claim (as `failed`, attempt not counted), so the
            // row is never left in `sending`; a re-queued copy marks it `queued` again.
            $this->requeue($e->retryAfter);

            return;
        }

        $this->setProgress($queue, 1);

        if ($result['success'] || $result['skipped']) {
            return;
        }

        // A genuine failure. The ledger row already records it with the message; throwing is what
        // gets the job into Craft's failed queue where somebody will see it.
        throw new \RuntimeException($result['message']);
    }

    private function requeue(int $delaySeconds): void
    {
        if (!$this->mayRequeue) {
            return;
        }

        // `push()` left the row `failed`. Only that row, still `failed`, is moved to `queued` — and
        // the copy is pushed only if the move happened. Anything else means another process has
        // it (sending, sent, or already queued with its own job), and a second job would only
        // race it. Without a re-queue the row stays `failed`, which `exactly/sync/retry` and the
        // maintenance cron pick up — so even the second rate limit in a row loses nothing.
        $plugin = Plugin::getInstance();
        $division = $plugin->getOauth()->getDivision();
        $document = $division !== null ? $plugin->getDocuments()->getDocument($this->orderId, $division, $this->kind) : null;

        if ($document !== null && !$plugin->getDocuments()->markQueued($document, Document::STATUS_FAILED)) {
            return;
        }

        Craft::$app->getQueue()->delay(max(5, $delaySeconds))->push(new self([
            'orderId' => $this->orderId,
            'orderNumber' => $this->orderNumber,
            'mayRequeue' => false,
            'kind' => $this->kind,
        ]));
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        if ($this->kind === Document::KIND_CREDIT_NOTE) {
            return Craft::t('exactly', 'Sending a credit note for order {number} to Exact Online', [
                'number' => $this->orderNumber ?? $this->orderId,
            ]);
        }

        return Craft::t('exactly', 'Sending order {number} to Exact Online', [
            'number' => $this->orderNumber ?? $this->orderId,
        ]);
    }
}
