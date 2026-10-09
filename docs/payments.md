---
title: Payment entries
slug: payments
order: 33
summary: Enter every Commerce payment in Exact as a bank or cash entry matched to its invoice — refunds to the credit note, processor fees on their own line — and compare Commerce with Exact per day.
---

# Payment entries

An invoice in Exact that nobody marks paid sits on the receivables list for ever, and a bookkeeper
ends up matching every Stripe, Mollie or PayPal payout against a column of invoice numbers by hand.
With **Enter payments in Exact** on, Exactly does the matching as the money arrives: each
successful capture or purchase becomes a line on a bank (or cash) entry in the journal you choose,
carrying the customer's account and the invoice number, which is how Exact matches a payment to an
open item. Exact then shows the invoice as settled.

Refunds are the mirror image, matched to the order's **credit note**. Processor fees can go on a
second line of the same entry, so it nets to what actually reached the bank.

This is the write side. **Read payment status back from Exact** (see [Usage](usage#payment-status))
is the read side, and the two work together: the read-back is what notices that Exact considers an
invoice settled, whether the payment came from Exactly or from your bank feed.

## What is sent

One `POST` per transaction, to `financialtransaction/BankEntries` (bank and payment-service
journals — what a PSP clearing journal is in Exact) or `financialtransaction/CashEntries`:

```json
{
  "JournalCode": "20",
  "Currency": "EUR",
  "BankEntryLines": [
    {
      "GLAccount": "<your receivables account>",
      "Account": "<the invoice's customer>",
      "OurRef": 20001,
      "AmountFC": 121.00,
      "Date": "2026-10-08T00:00:00",
      "Description": "1042-ABCD 3f9c1d0a7e"
    },
    {
      "GLAccount": "<your fee account>",
      "AmountFC": -3.81,
      "Date": "2026-10-08T00:00:00",
      "Description": "Fee 1042-ABCD 3f9c1d0a7e"
    }
  ]
}
```

- `OurRef` is the invoice number (Exact's field is an integer and is described as "Invoice
  number"); `Account` is the customer the invoice was raised for. Together they are what Exact
  matches on.
- `Date` is the day the transaction happened, in your site's time zone, sent zone-less.
- `Description` is your order reference plus the start of Commerce's own transaction hash — unique
  to the transaction, which matters below.
- A refund is the same shape with the opposite sign, `OurRef` set to the credit note's number, and a
  `Refund ` prefix on the description.

**Preview** any transaction before switching anything on: the order panel lists the order's
payments with a Preview link, the Payments screen has one per row, and
`php craft exactly/payments/preview --transaction=123` prints the body. The preview is built by the
same code that posts, so it is exactly what Exact would receive. Nothing is entered by looking.

## Setting it up

**Settings → Exactly → Payment entries.**

| Setting | Default | |
|---|---|---|
| **Enter payments in Exact** (`registerPayments`) | off | The switch |
| **Entry type** (`paymentEntryType`) | `bank` | `bank` posts BankEntries (bank and payment-service journals), `cash` posts CashEntries |
| **Payment journal** (`paymentJournalCode`) | empty | The Exact journal code for any gateway without its own |
| **Journal per gateway** (`paymentJournalByGateway`) | empty | One journal code per Commerce gateway — Stripe to its clearing journal, PayPal to its own |
| **Receivables GL account** (`paymentReceivablesGlAccountCode`) | empty | Your debtors control account, `1300` in most Dutch charts. Exact needs a ledger account on every line |
| **Money received is** (`paymentAmountSign`) | positive | See *Signs* below |
| **Enter refunds too** (`registerRefunds`) | on | |
| **Book processor fees** (`recordProcessorFees`) | off | |
| **Fee GL account** (`paymentFeeGlAccountCode`) | empty | Where fees are booked; required for a fee line |

Without a journal or a receivables account a payment is not guessed at: it fails with a message
saying which setting to fill in, does not spend a retry attempt, and goes through on the next run
once the setting is there.

## When a payment is entered

Every successful **capture** or **purchase** (and, with refunds on, every successful **refund**) is
*queued* the moment Commerce saves it — including a payment a gateway settles later by webhook,
which arrives as a new transaction. Nothing touches Exact on the checkout itself.

The job then needs something to match:

- **The invoice has to exist in Exact, and be processed.** A sales invoice created through the API
  is a *draft* until it is printed or sent, and a draft is not an open item — a payment line
  pointing at it has nothing to match. Exactly re-reads the invoice's status before deciding.
  Until it is processed, the payment **waits** (no attempt is spent). The usual order of events —
  the payment completes the order, the invoice follows — is exactly this case.
- When the invoice push succeeds, the order's waiting payments are queued again. Payments waiting
  for an invoice to be processed *in Exact* are picked up by `exactly/sync/maintenance`, which reads
  invoice statuses first and then enters whatever is now matchable. Set a delivery method (which
  processes the invoice) and most payments go through within one maintenance run.
- **A refund** is matched to the order's credit note, and waits for it the same way. A refund with
  no credit note coming — a partial refund, or credit notes switched off — is **skipped** with the
  reason, for you to book by hand: entering it against the invoice would re-open money the customer
  still paid.

## Never twice

Payments get the same guarantee invoices do:

- **One row per transaction per administration**, enforced by a unique index, and every path — the
  queue, the order panel's **Enter payments** button, the Payments screen, the console, the
  maintenance run — goes through the same claim. An entered payment is reported as skipped, never
  posted again.
- **A lost answer is not a second payment.** If a POST reached Exact but the answer never came back,
  the row says *failed* and a retry follows. Before any second attempt Exactly looks the line's
  description up in Exact's entry lines; if it is there, it records that entry and posts nothing.
- A rate limit gives the attempt back and the job comes back after Exact's reset; a 5xx or network
  failure is retried by the queue; a refusal (a closed period, a journal that does not exist) is
  recorded with Exact's message and left for a person.

## Signs

Exact's reference says only that a bank entry's opening balance plus its lines makes the closing
balance, which reads as *money in is positive* — that is the default. Like **Credit note amounts**,
it was not confirmed against a second independent implementation, so it is a switch: if payments
come out doubling the receivable instead of settling it, set **Money received is** to *negative*.
Refunds and fees always take the opposite sign to a payment.

## Processor fees

With **Book processor fees** on, the fee becomes a second line on the fee account, so the entry
nets to the payout. It is read from the transaction's stored gateway response — never a second
call to the gateway:

- **Stripe** — a balance transaction's `fee` (minor units; zero-decimal currencies respected).
  Commerce Stripe usually stores the payment intent with an *unexpanded* charge, so most Stripe
  stores read no fee here. Supply it from your own module (below).
- **PayPal Checkout** — `seller_receivable_breakdown.paypal_fee`.
- **PayPal Express** (NVP) — `PAYMENTINFO_0_FEEAMT` / `FEEAMT`.

A fee in another currency than the payment is ignored rather than converted, and so is a "fee"
that is not less than the payment — that is a misread. Leave fees off if you already book them from
the payout report, or they will be counted twice.

## The Payments screen

**Exactly → Payments** (shown while entering payments is on) compares the two systems per day, in
your site's time zone: what Commerce captured and refunded, what Exactly entered in Exact, how many
transactions are not entered yet (failed, waiting, or not tried), and how many of that day's paid
orders Exact now reports as **settled** (from the payment-status read-back). Below it, every
payment not yet in Exact with the reason, and **Enter what is missing** to run them again.

```sh
php craft exactly/payments/reconcile --days=7   # the same, one line per day
php craft exactly/payments/retry                # everything failed (with attempts left) or waiting
php craft exactly/payments/register --order=1234
php craft exactly/payments/preview --transaction=5678
```

## Events

```php
use justinholtweb\exactly\events\PaymentEntryEvent;
use justinholtweb\exactly\events\ProcessorFeeEvent;
use justinholtweb\exactly\services\PaymentEntries;

// Supply a fee Exactly cannot read — in the transaction's currency, positive.
Event::on(PaymentEntries::class, PaymentEntries::EVENT_DEFINE_PROCESSOR_FEE, function(ProcessorFeeEvent $e) {
    $e->fee = MyStripeFees::for($e->transaction);
});

// Add a cost centre to the line, or keep a transaction out of Exact entirely.
Event::on(PaymentEntries::class, PaymentEntries::EVENT_BEFORE_REGISTER, function(PaymentEntryEvent $e) {
    if ($e->transaction->getGateway()?->handle === 'giftCards') {
        $e->isValid = false;
        $e->message = 'Gift cards are booked from the voucher report.';
    }
});
```

A vetoed transaction is recorded as skipped, with your message, and never retried.
