<?php

namespace justinholtweb\exactly\events;

use craft\commerce\models\Transaction;
use craft\events\CancelableEvent;

/**
 * Fired before a payment or refund is posted to Exact Online as a bank or cash entry.
 *
 * `payload` is exactly what will be posted (and what the preview shows). Change it to add a cost
 * centre or a note; set `isValid` to false to keep this transaction out of Exact — the row is
 * recorded as skipped, with the reason in `message`, and never retried.
 */
class PaymentEntryEvent extends CancelableEvent
{
    public ?Transaction $transaction = null;

    /** `payment` or `refund`. */
    public string $kind = 'payment';

    /** The `BankEntries` / `CashEntries` body. */
    public array $payload = [];

    public string $message = '';
}
