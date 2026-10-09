<?php

namespace justinholtweb\exactly\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\commerce\elements\Order;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\exactly\Plugin;

/**
 * "Send to Exact Online" on the Orders index: queue every selected, completed order.
 *
 * Each order goes through `Sync::schedule()`, so the same rules hold as for the order screen's
 * **Queue it** button: an order already invoiced, or one a worker is sending right now, is left
 * alone and no job is pushed, and the job itself lands in `Documents::claim()`. Selecting an
 * invoiced order is therefore harmless — it is counted as skipped, never invoiced twice. Always
 * through the queue, even with `useQueue` off: a hundred orders inline is a request that times out
 * halfway.
 */
class SendToExact extends ElementAction
{
    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return Craft::t('exactly', 'Send to Exact Online');
    }

    /**
     * @inheritdoc
     */
    public function performAction(ElementQueryInterface $query): bool
    {
        // The action is only offered to people who may push, but the request can be made by
        // anyone who can see the index.
        if (!Craft::$app->getUser()->checkPermission('exactly-pushOrders')) {
            $this->setMessage(Craft::t('exactly', 'You are not allowed to send orders to Exact Online.'));

            return false;
        }

        $plugin = Plugin::getInstance();

        if (!$plugin->getOauth()->isConnected() || $plugin->getOauth()->getDivision() === null) {
            $this->setMessage(Craft::t('exactly', 'Exactly is not connected to Exact Online.'));

            return false;
        }

        $ids = (clone $query)->status(null)->ids();
        // A cart is not a sale; there is nothing to invoice.
        $orders = $ids === [] ? [] : Order::find()->id($ids)->status(null)->isCompleted(true)->all();

        $queued = 0;
        $refused = 0;

        foreach ($orders as $index => $order) {
            // Staggered like a backfill: sixty calls a minute is the ceiling, and one invoice
            // costs three or four.
            $result = $plugin->getSync()->schedule($order, $index * 5, alwaysQueue: true);
            $result['queued'] ? $queued++ : $refused++;
        }

        $incomplete = count($ids) - count($orders);
        $skipped = $refused + $incomplete;

        $this->setMessage($skipped > 0
            ? Craft::t('exactly', '{queued} orders queued for Exact Online; {skipped} skipped (already invoiced, in flight, or not completed).', ['queued' => $queued, 'skipped' => $skipped])
            : Craft::t('exactly', '{queued} orders queued for Exact Online.', ['queued' => $queued]));

        return $queued > 0 || $skipped > 0;
    }
}
