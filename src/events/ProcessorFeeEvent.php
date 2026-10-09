<?php

namespace justinholtweb\exactly\events;

use craft\commerce\models\Transaction;
use yii\base\Event;

/**
 * Fired when Exactly works out a payment processor's fee for a transaction.
 *
 * `fee` arrives holding what Exactly read from the gateway's stored response, or null when it
 * found nothing. Set it — in the transaction's currency, as a positive amount — for a gateway
 * Exactly cannot read, or one whose response does not carry the fee (Commerce Stripe stores the
 * payment intent, whose charge is usually not expanded). Set it to null to record no fee.
 */
class ProcessorFeeEvent extends Event
{
    public ?Transaction $transaction = null;

    public ?float $fee = null;
}
