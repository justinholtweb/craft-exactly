---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Dead connections, refused totals, missing items, drafts, rate limits, and what Exact's API does that surprises people.
---

## Start at the log

**Exactly → Log** holds every call Exactly makes to Exact — action, endpoint, status code, duration
and, with **Keep request and response bodies** on, both payloads. Filter it by action or level.
Every document's detail screen also lists the log entries for its order. From the console:

```sh
php craft exactly/log/tail 25
php craft exactly/connect/status
```

Exact's own error text is read out of its error envelope and its `Reason` header, so the message
you see is usually Exact's, verbatim.

## "Exact Online rejected the refresh token"

The full message is *It has either expired or already been used — reconnect Exactly in its
settings.* This is Exact's `invalid_grant`, and the connection is dead until an admin presses
**Reconnect** on the settings screen. Nothing Exactly can do on its own will bring it back.

Exact's access token lasts ten minutes and its refresh token is **single-use** — every refresh burns
the old one. Exactly refreshes under a lock and re-reads the stored token inside it, so its own
queue workers cannot race each other. The ways it still happens:

- **The refresh token expired.** It lasts 30 days from its last use. A store that takes no orders
  for a month has to reconnect. The settings screen shows *reconnect within N days* and warns when
  fewer than seven are left; `exactly/sync/status` and `exactly/connect/status` show it too.
- **Another copy of the site refreshed it.** A staging site restored from a production database,
  with the same security key, holds the same refresh token. The first one to refresh spends it and
  the other is dead. Disconnect on the copy, or give it its own Exact app.
- **Someone revoked the app** in Exact.

*The Exact Online connection has expired. Reconnect it in Exactly's settings.* is the same
situation, noticed before a call was made.

## "Timed out waiting for another process to refresh the Exact Online token"

Another worker held the refresh lock for ten seconds without producing a usable token. Exactly
refuses to refresh anyway, because that is exactly the race that kills a connection. The job fails
and can be retried. If it keeps happening, check that Craft's mutex works across your servers — on
more than one web or queue node, the default file mutex does not.

## The redirect URI does not match

Exact compares the redirect URI character for character. Copy it from the settings screen rather
than typing it, and register it again if your site URL, scheme or `omitScriptNameInUrls` setting
changes. It is a site URL, so it follows your primary site's base URL.

## "Exact Online would invoice X but the customer paid Y"

The full message ends *That is more than the rounding tolerance, so nothing was sent — check the VAT
code mapping for this order's treatment.* Exactly predicted Exact's total from your mapped VAT codes,
and it disagrees with what the customer was charged by more than **Rounding tolerance**. Open
**Preview payload** on the order: the treatment is at the top of the reconciliation table.

The usual causes:

- **The treatment's code has the wrong percentage** — a 21% code mapped to reverse charge, a 0% code
  mapped to domestic.
- **OSS with one code.** Commerce charged the destination country's rate, but the single OSS code
  has one percentage. Map each Commerce tax rate to its own code under **Per Commerce tax rate**.
- **A reduced rate.** The line carries a 9% or 7% Commerce rate but the treatment code is the
  standard rate. Map that Commerce rate.
- **Shipping or discount switched off** — the invoice is missing money the order has. The preview
  warns about this.

Raising the tolerance is not the fix. It exists for cents; a bigger gap is a mapping problem you
want to see before it is booked.

## "…the invoice total was not reconciled against the order total"

A mapped VAT code's percentage could not be read from Exact — the code does not exist in this
administration, it is blocked, or a treatment has no code at all and Exact will use the item's. The
invoice is still sent. Check the codes, then **Clear cached lookups** (VAT codes are cached for an
hour).

## An SKU "is not in Exact Online" when it is

Exact stores item and account codes right-aligned in an 18-character, space-padded column, and an
unpadded `Code eq '1024'` filter matches nothing *and reports no error*. Exactly pads every code
lookup, so if it still cannot find the item:

- the SKU and the Exact item code genuinely differ — a prefix, a suffix, different case;
- the item is in a different administration from the one in **Division**.

When an SKU does not match, the line goes on the fallback item and the preview says so. With no
fallback item configured, it fails: *No Exact Online item matches SKU "…", and no fallback item is
configured.*

## "The fallback item "…" does not exist in this Exact Online administration"

Item codes are per administration. Check the code exists in the division you selected, not just in
the one you usually look at in Exact. The same goes for the shipping, discount and rounding items.

A GL account code that cannot be found is quieter: the line goes without one and Exact uses the
item's own revenue account. If revenue lands on the wrong account, check the code in the log's
request body.

## The revenue report is empty

Every invoice Exactly creates is a **draft** until it is printed or sent, and a draft is not in the
general ledger. Either process the drafts in Exact, or set a delivery method so Exact processes each
one as it arrives. See [Usage](usage#drafts).

## Payment status says "credited"

The invoice has a sent credit note. Matching the two in Exact takes the invoice off the receivables
list, which is not the customer paying, so it is reported *credited* rather than *paid*, and
**Paid order status** is not applied.

## Payment status says "draft"

Every invoice created through the API starts as a draft, and **Check payments** re-reads each
unprocessed invoice's status from Exact on every run. So *draft* means Exact still has it as a
draft (or *open*, but not yet processed): it is not in the ledger, so it can be neither paid nor
outstanding. Process it in Exact — print or send it — or set a delivery method, and the next
payment check picks up the new status. If a status read fails, the error is reported and the
invoices in that batch stay *draft* rather than being guessed as paid; the other batches still
count.

## An order never reached Exact

In order of likelihood:

1. **When to invoice is *Only when I ask*.** That is the default.
2. **The trigger never fired** — *When the order reaches a status* with no **Trigger statuses**
   ticked, or a status the order never reached.
3. **The queue is not running.** Check Craft's queue.
4. **No division is set**, or Exactly is not connected — `exactly/sync/status`.
5. **It failed.** The order panel and the document's detail screen show the last error.

## "Another process is sending this order to Exact Online right now"

A push for this order is in flight. **Queue it**, a backfill, a refund and a rate-limit re-queue all
leave such an order alone and push no second job, so nothing can claim it out from under the worker
that is still waiting on Exact. A *sending* row is respected for 15 minutes so a slow Exact is
never overtaken; after that a worker that was killed mid-push no longer blocks it, and the next
attempt takes over.

## "This order is already invoice … in Exact Online"

Not an error. Every path checks the same record, so a second push is skipped. **Send again** on the
order panel is the deliberate way to create a second invoice.

The credit-note equivalent is *This order already has credit note … in Exact Online*: one credit
note per order per administration, however it was asked for.

## "No credit note was issued" after a refund

With **Credit notes on refund** on, only a **full** refund queues a credit note, because Exactly's
credit note reverses the whole invoice. "Full" allows the **Rounding tolerance**, so a refund
converted back from another currency a cent short still counts. A partial refund is written to the
log — *Order … was partly refunded (X of Y), so no credit note was issued* — for the bookkeeper to
credit by hand. If later refunds bring the total refunded up to the order total, that refund queues
the credit note. Nothing is queued for an order that has no sent invoice in the current
administration.

A refund the gateway reports as *processing* and settles later by webhook (Mollie, for one) queues
the credit note when the settlement arrives: Exactly listens for every transaction Commerce saves,
and the settlement is saved as a new successful refund transaction. If a gateway plugin settles
refunds some other way — without saving a new transaction — issue the credit note from the order
panel.

If the invoice stops being tracked between the refund and the job running, the credit note's row is
closed as *skipped* with *There is no Exact Online invoice to credit for this order.*

## Credit notes double the invoice

Switch **Credit note amounts** to the other sign. Exact's documentation does not say which one a
credit note (type 8021) expects, which is why it is a setting.

## The invoice is dated in the previous VAT period

Exact takes dates without a time zone; send it `Z` and it shifts the date, which is how an invoice
dated the 1st lands on the last day of the previous month. Exactly sends a bare calendar day, taken
in Craft's system time zone. If dates are a day out, check that time zone and **Invoice date**.

## Rate limits

Exact allows about 60 calls a minute per administration, plus a daily limit. Exactly reads the
limits from every response — `exactly/connect/status` shows what is left — and checks before each
call.

- In a **console** process, including a console queue worker, it waits for the minute to reset.
- In a **web request** it refuses rather than sleeping inside a page load: *The Exact Online
  per-minute call limit is spent; this resets in Ns.*
- When the **daily** limit is spent it refuses everywhere until Exact's reset time, which Exactly
  stores from the `X-RateLimit-Reset` header: *The Exact Online daily call limit for this
  administration is spent.* Once that time has passed the next call goes through and refreshes the
  count; nothing needs reconnecting.

A rate-limited push is not counted as a failed attempt and never leaves the order stuck in
*sending*:

- A **queue job** re-queues itself for when the budget refills and the job reports success.
- **Send to Exact Online** on the order panel says *Exact Online is rate limiting this
  administration, so nothing was sent. Try again in N seconds.*
- `exactly/sync/order` prints the same and exits with code 75 (temporary failure); `--now` runs of
  `backfill` and `retry` stop at the first rate limit rather than spending more calls on refusals.
- Anything not re-queued is left *failed*, so **Retry failures** or maintenance picks it up.

Run the queue from the console, and run large backfills in batches with `--limit`.

## If you are calling Exact yourself

Exactly handles each of these. If you write your own integration against the same administration,
they are the ones that cost an afternoon:

- Send `Accept: application/json`, or Exact answers XML — the most common reason a first call
  "returns nothing".
- Responses are wrapped in a `d` envelope; collections nest under `results` and page through
  `__next`.
- Dates come back as `/Date(ms)/` in UTC milliseconds and go in as zone-less ISO 8601.
- `X-RateLimit-Minutely-Reset` is epoch **milliseconds**, not seconds.
- Pad `Code` filters to 18 characters on the left.
- `Prefer: return=representation` makes a POST return what it created.
