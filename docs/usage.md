---
title: Usage
slug: usage
order: 30
summary: How an order becomes an invoice, previewing, the order panel, the Orders index, credit notes, backfilling, payment status, the console and Twig.
---

## How an order becomes an invoice

1. Something asks for it — a trigger, the order panel, the console, a backfill or a retry.
2. Exactly **claims** the order. The documents table is unique on order, administration and kind,
   and every path goes through the same claim, so the "has this been invoiced?" decision is made
   once. An order that is already invoiced is reported as *skipped*, not failed.
3. It works out the VAT treatment, finds or creates the Exact account, and resolves an item and a
   GL account for every line.
4. It builds the payload, predicts the total Exact will arrive at, and compares it with the order
   total. A cent or two becomes a rounding line; more than the tolerance stops the push.
5. It creates the sales invoice and records the invoice number, Exact's IDs and status, the amounts
   and the exact payload that was sent.
6. If a delivery method is set, it asks Exact to print, email, post or Peppol it.

One payload builder does all of this, so the preview, the console preview, the order-panel button
and the queue job cannot disagree about what an order looks like in Exact.

## Previewing

**Preview payload** is on the order panel and on every document's detail screen. It shows:

- the **VAT treatment**, the VAT number and the countries it was decided from;
- **Lines excluding VAT**, **VAT Exact will add**, **Invoice total**, **The customer paid** and
  the **Difference**, with a green light when they agree;
- **Worth knowing** — every decision Exactly had to make: an SKU that fell back to the generic item,
  a rounding line, a VIES result, shipping or discount that was switched off;
- the JSON payload itself.

Nothing is created in Exact by previewing — not even a customer account. For a customer who is not
in Exact yet, the preview says one will be created and leaves `OrderedBy` out; that is the one field
that differs on the real send.

```sh
php craft exactly/sync/preview 1234
```

prints the same thing for order ID 1234.

## The order panel

Commerce's order edit screen gets an **Exact Online** panel showing the invoice number, its status,
when it was sent, the VAT treatment, payment and delivery status, and the last error if there was
one. **Open the record** goes to the document's detail screen.

| Button | What it does |
| --- | --- |
| Preview payload | Opens the preview |
| Send to Exact Online | Pushes the order now, inline |
| Send again | Shown once the order is invoiced. Creates a **second** invoice, after a confirmation |
| Queue it | Hands the order to the queue instead — useful for a large order on a slow connection |
| Credit note | Shown once the order is invoiced. Issues a credit note, after a confirmation |
| Enter payments | Shown once the order is invoiced, with [payment entries](payments) on. Enters the order's payments and refunds that are not in Exact yet; the panel lists each with its status and a Preview link |

**Send again** is the only way to get a duplicate invoice, and it is deliberate. The document record
then tracks the new invoice; the first one stays in Exact.

## The Documents screen

**Exactly → Documents** lists every invoice and credit note Exactly knows about, newest first, with
status, VAT treatment, amount and payment status. Filter by status (sent, failed, queued, sending,
pending, skipped), by kind, or search by order or invoice number.

The three buttons along the top:

- **Backfill** queues up to 100 completed orders that have never been invoiced into this
  administration.
- **Retry failures** re-queues failed documents that still have attempts left and whose last
  attempt is at least **Retry delay** old. A failed credit note is retried as a credit note.
- **Check payments** runs the payment reconciliation below.

A document's detail screen shows everything recorded about it, the payload that was actually sent,
the last error, and the related log entries. **Stop tracking** removes the record from Craft only —
an issued invoice is not Craft's to retract, and the invoice in Exact is untouched.

## The Orders index

Commerce's own **Orders** index gets three things:

- **An Exact Online column.** Add it from the index's column picker. Each row shows where the order
  stands in the current administration — *Failed*, *Credited*, *Paid in Exact*, *Invoiced* (with
  the invoice number), *Queued or sending*, *Pending*, *Skipped* or *Not invoiced* — read with one
  query per page, not one per row. *Failed* means the invoice, the credit note or one of its
  payment entries failed: whatever needs a person.
- **An "Exact Online status" filter**, for the index's condition builder and custom sources. Build a
  **Not yet invoiced** source with *Exact Online status is one of Not invoiced*, or a **Needs a
  look** source with *Failed*. The filter and the column are the same sets, so they never disagree.
- **A "Send to Exact Online" bulk action** for people with *Send orders to Exact Online*. It queues
  every selected completed order — always through the queue, staggered like a backfill — and skips
  carts, orders already invoiced and orders being sent right now, saying how many it skipped. It goes
  through the same claim as every other path, so selecting an invoiced order cannot invoice it
  twice.

## Drafts

An invoice created through Exact's API is a **draft** (Exact status 10). A draft exists in Exact but
is not in the general ledger and is not receivable. Sync a year of orders with delivery set to
*Don't* and your revenue report will be empty.

With delivery set to *Don't*, Exactly warns you every time it creates one. Either process the
invoices in Exact, or set a delivery method in [Configuration](configuration#delivery) so Exact
processes them as they are sent.

## Credit notes

**Credit note** on the order panel issues an Exact credit note (document type 8021) for an order
that has an invoice in the current administration. It needs the *Issue credit notes* permission.

Be clear about what it is: the credit note is built from the **whole order**, through the same
payload builder and reconciliation as the invoice. It credits the full amount; there is no partial
credit. One credit note per order per administration — a second press is refused.

### On refund

With **Credit notes on refund** on, a refund recorded in Commerce queues a credit note for the
order — always through the queue, even with **Push through the queue** off, and never in a way that
can make the refund itself fail. Only when:

- the order has a sent invoice in the current administration, and
- everything refunded on the order so far adds up to the order total.

"Adds up to" allows the **Rounding tolerance**. A refund the gateway settles later by webhook
queues the credit note when the settlement is saved. A partial refund issues nothing, because the
credit note would reverse money the customer still paid; it is logged with the amounts instead. A second refund event, a retried job or a duplicate
job cannot issue a second credit note — they all go through the same one-per-order record.

Exact's field reference does not say whether credit-note lines take positive or negative amounts.
Exactly sends positive by default; if yours comes out doubling the invoice instead of cancelling
it, change **Credit note amounts** in [Configuration](configuration#automation).

## Backfilling an existing store

```sh
php craft exactly/sync/backfill --dry-run --limit=20            # count; queue nothing
php craft exactly/sync/backfill --since=2026-01-01 --limit=250  # queue
php craft exactly/sync/backfill --limit=50 --now                # send inline from the console
php craft exactly/sync/backfill --limit=50 --now --dry-run      # list the orders that would go
```

A backfill picks completed orders with no sent invoice in the current administration, oldest
first. Queued orders are staggered five seconds apart: an invoice costs three or four calls, and
firing everything at once would spend Exact's 60-a-minute budget in ten seconds. `--limit` defaults
to 100.

Preview a few orders first. A backfill is the moment a VAT mapping mistake becomes a hundred
mistakes.

## Payment status

With **Read payment status back from Exact** on, Exactly reconciles sent invoices against Exact.
Two reads, both batched so a store with fifty open invoices does not spend its minute budget on
them:

1. **Each invoice's own status**, for every invoice not yet known to be processed — twenty to a
   call, with one `$filter` on their IDs. Every API-created invoice starts as a draft (status 10);
   this is how Exactly sees it become open (20) or processed (50). A processed invoice is never
   read again.
2. **The receivables list** — everything still owed — read once.

A **processed** invoice that is not on the list is recorded as *paid*; one on the list as
*outstanding* or *partial*. An invoice with a sent credit note that is not on the list is
*credited*, not paid — the credit note settled it, not the customer — and **Paid order status** is
not applied to it. An invoice Exact still has as a draft (or open but unprocessed) is
reported as *draft*: it is missing from the receivables list because it is not booked, not because
it is paid. If the status read fails, the stored status stands and nothing is reported paid on a
guess.

**Paid order status** moves the Commerce order to a status of your choice when its invoice is
reported paid. Blank records the payment on the document only.

Run it with **Check payments**, `exactly/sync/payments`, or `exactly/sync/maintenance` on a
schedule.

## Console

```sh
php craft exactly/connect/status              # region, identity, division, token, call budget left
php craft exactly/connect/divisions           # every administration this login can write to
php craft exactly/connect/check-vat NL802513146B01

php craft exactly/sync/status                 # connected, division, trigger, sent/queued/failed
php craft exactly/sync/preview 1234           # the payload for one order; sends nothing
php craft exactly/sync/order 1234             # send one order now
php craft exactly/sync/backfill --since=2026-01-01 --limit=250 --dry-run
php craft exactly/sync/retry --limit=50       # re-queue failures with attempts left
php craft exactly/sync/retry --limit=10 --now # retry them inline from the console instead
php craft exactly/sync/payments --limit=200   # reconcile against Exact (default 100 documents)
php craft exactly/sync/maintenance            # prune, retry, reconcile, enter waiting payments, check alerts — for cron

php craft exactly/payments/preview --transaction=5678   # the entry for one transaction; posts nothing
php craft exactly/payments/register --order=1234        # enter one order's payments now
php craft exactly/payments/retry                        # everything failed (attempts left) or waiting
php craft exactly/payments/reconcile --days=7           # Commerce vs Exact, per day

php craft exactly/alerts/check                # evaluate the failure alerts, send what is owed
php craft exactly/alerts/test                 # a sample through every configured channel

php craft exactly/log/tail 25
php craft exactly/log/prune --days=30
php craft exactly/log/clear
```

Order arguments are order **IDs**. `exactly/connect/status` exits non-zero when a connection exists
but a test call fails — expired, revoked, or Exact unreachable — which makes it the one to put in a
monitoring script. (It exits zero when the site has never been connected at all.)

## Twig

```twig
{% set invoice = craft.exactly.document(order) %}

{% if invoice %}
    <p>Invoice {{ invoice.invoiceNumber }} — {{ invoice.getStatusLabel() }}</p>

    {% if invoice.vatTreatment == 'reverse-charge' %}
        <p>Intra-community supply — VAT reverse-charged.</p>
    {% endif %}
{% endif %}
```

| | |
| --- | --- |
| `craft.exactly.document(order)` | The invoice recorded against an order, or `null` |
| `craft.exactly.documents(order)` | Every document for the order, invoices and credit notes |
| `craft.exactly.invoiceNumber(order)` | The Exact invoice number, or `null` |
| `craft.exactly.isPaid(order)` | Whether Exact reports it paid; `null` if unknown |
| `craft.exactly.vatTreatment(order)` | `domestic`, `reverse-charge`, `oss` or `export` |
| `craft.exactly.isConnected()` | Whether the Exact connection is live |

Each takes an order or an order ID. `craft.exactly` is read-only: a template render is not the place
to write to an accounting system, and a page that could would be one crawler away from a duplicate
invoice.

## Permissions

| Permission | Allows |
| --- | --- |
| View Exact Online documents | The order panel, the Orders index column, the Dashboard widget, and — with Commerce's *Manage orders* — the Documents and Payments screens |
| ↳ Send orders to Exact Online | Send, Send again, Queue it, Backfill, Retry failures, Check payments, Stop tracking, the Orders index's **Send to Exact Online** action, **Enter payments** and **Enter what is missing** |
| ↳ Issue credit notes | The Credit note button |
| View the connection log | The Log screen |

Exactly's permissions add to Commerce's own order access; they do not stand in for it. Everything
on the Documents screen, including its buttons, also needs Commerce's *Manage orders* permission.

The settings screen, connecting and disconnecting, **Send a test alert**, and pruning or clearing
the log are for admins only. Reading the log is a permission; destroying the record of what was sent is not.
