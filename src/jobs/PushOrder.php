<?php

namespace justinholtweb\exactly\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use justinholtweb\exactly\errors\RateLimitException;
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

        try {
            $result = $plugin->getInvoices()->push($order);
        } catch (RateLimitException $e) {
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

        Craft::$app->getQueue()->delay(max(5, $delaySeconds))->push(new self([
            'orderId' => $this->orderId,
            'orderNumber' => $this->orderNumber,
            'mayRequeue' => false,
        ]));
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('exactly', 'Sending order {number} to Exact Online', [
            'number' => $this->orderNumber ?? $this->orderId,
        ]);
    }
}
