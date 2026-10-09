# Changelog

## Unreleased

### Added

- **Payment entries.** With **Enter payments in Exact** on, every successful capture or purchase is
  entered in Exact as a bank (or cash) entry line in the journal you choose — per gateway, or a
  default — carrying the customer's account and the invoice number (`OurRef`), so Exact matches it
  to the open invoice and shows it settled. Refunds are entered against the order's credit note.
  Queued from Commerce's own transaction event, never on the checkout.
- A payment waits, without spending an attempt, until its invoice is in Exact and **processed** (a
  draft is not an open item); the invoice push and `exactly/sync/maintenance` pick it up. A refund
  with no credit note to match is skipped with the reason.
- One entry per transaction per administration: a unique index and the same insert-first claim
  invoices use, plus a look-up of the line's unique description before any retry, so a POST whose
  answer was lost is found rather than entered twice.
- Optional **processor fees** as a second line on a fee account, read from the stored gateway
  response (Stripe balance transaction, PayPal Checkout, PayPal NVP) or supplied by
  `PaymentEntries::EVENT_DEFINE_PROCESSOR_FEE`. `PaymentEntries::EVENT_BEFORE_REGISTER` can change
  or veto an entry.
- A **Payments** screen comparing Commerce's captures and refunds with Exact's entries per day,
  with everything not yet entered and an **Enter what is missing** button; a dry-run **preview** of
  any transaction's entry; payments listed on the order panel with an **Enter payments** button;
  `exactly/payments/preview`, `/register`, `/retry` and `/reconcile`.
- **Failure alerts** (the family pattern, from Erpy): one email, and optionally a Slack, Teams or
  signed JSON webhook, when orders fail to reach Exact, when invoicing stalls, or when Exact refuses
  the connection — and one when it clears. Latched per incident with a quiet period; redacted;
  webhooks only to public hosts, pinned, no redirects. Checked after every push and payment entry,
  on every refused token, and by `exactly/alerts/check`, `exactly/sync/retry` and
  `exactly/sync/maintenance`. **Send a test alert** (admins) and `exactly/alerts/test`.
- An **Exact Online health** Dashboard widget.
- On Commerce's Orders index: an **Exact Online** column (status and invoice number), an **Exact
  Online status** condition rule for filters and custom sources (*Not invoiced*, *Failed*, *Paid in
  Exact*, …), and a **Send to Exact Online** bulk action that queues the selected orders through the
  usual claim.

### Changed

- `exactly/sync/maintenance` also enters payments that are waiting or failed, and checks the alerts.
- A 401 now refreshes the token Exact refused even when its stored expiry says it is still good
  (revoked early, or a clock that disagrees with Exact's); before, the retry reused the same token.

### Fixed

- **Queue it**, **Send to Exact Online** and a second refund now treat an order that is already
  `queued` the same every time: it already has its job, so it is skipped with "This order is
  already queued for Exact Online." Before, the answer depended on the clock — inside the same
  second it was refused as "Another process is sending this order", a second later a duplicate job
  was pushed. A `queued` row older than 15 minutes (its job was lost) can still be queued again.
- The settings screen's buttons — **Test connection**, **List administrations**, **Disconnect**,
  **Clear cached lookups** and the VIES check — did nothing: Craft prefixes every id on a plugin
  settings screen with `settings-`, and the script looked them up without it.
- An **Exact Online status** filter whose chosen statuses had all since been renamed or removed
  stopped filtering, so a saved custom source widened to every order — and re-saving it dropped
  the values for good. The rule now keeps what was chosen; only statuses Exactly knows reach the
  query, *is one of* nothing known matches no orders, and *is not one of* nothing known excludes
  none.

## 5.0.0 — 2026-08-20

Initial release.

### Added

- **OAuth 2.0 connection to Exact Online**, across all seven regional hosts plus a custom base URL.
  Tokens are encrypted with the Craft security key and stored in the database; refreshes run under a
  mutex and re-read the row inside the lock, because Exact's refresh tokens are single-use and two
  concurrent workers would otherwise kill the connection.
- **Orders become sales invoices.** Lines, shipping, discounts, item and GL-account resolution,
  journal, invoice date, description and remarks object templates, payment condition, cost centre
  and cost unit.
- **EU VAT determination** — domestic, intra-community reverse charge, EU consumer (OSS) and export,
  from the shipping country and the customer's VAT number. Structural validation for all 27 member
  states plus `XI`, reading Craft 5's own Organization Tax ID attribute by default. Reverse charge
  fails closed.
- **Total reconciliation.** The VAT Exact will compute is predicted from the mapped codes and
  compared against what the customer paid; a cent or two becomes a rounding line, anything more stops
  the push.
- **A ledger that cannot double-invoice.** Unique on `(order, administration, kind)`, with an atomic
  claim, a staleness window for workers killed mid-push, and a deliberate force path for the times a
  duplicate really is wanted.
- **Preview**, in the CP and on the console, built by the same code as the send.
- **Documents screen** with status, VAT treatment, amounts, payment state and the stored payload;
  backfill, retry and payment reconciliation from the same screen.
- **Order-edit panel** inside Commerce's own screen.
- **Connection log** with request and response bodies, redacted.
- **Console commands** for connection status, divisions, VIES checks, per-order push and preview,
  backfill, retry, payment reconciliation, maintenance and log housekeeping.
- **Twig API** (`craft.exactly`), read-only.
- **Automatic invoicing** on completion, payment or status change, through a rate-limit-aware queue.
- **Credit notes**, issued from the order panel, or queued automatically when a refund brings the
  total refunded up to the order total (within the rounding tolerance) — including a refund the
  gateway settles later by webhook. Partial refunds are logged and issue nothing, because a credit
  note reverses the whole invoice. An invoice with a credit note is reported *credited*, never paid.
- **VAT-exempt tax categories**: a line in a category marked exempt, and charged no tax, takes the
  Exempt VAT code; on an order that is wholly exempt and untaxed, so do shipping and discount.
- **Rate limits handled, not tripped over.** A rate-limited job re-queues for Exact's reset time
  without spending a retry; the daily limit lifts when Exact's reset time passes; failed documents
  wait the configured retry delay before any retry path picks them up.
- **Never writes before it refuses.** The reconciliation verdict, then the configured items, come
  before any account or item is created, so a refused order leaves nothing behind in Exact. A preview
  says when the send will create a customer or an item rather than showing something else.
- Per-Commerce-tax-rate VAT mapping,
  per-product-type GL accounts, VIES validation, email / postbox / **Peppol** delivery, and payment
  write-back that re-reads each invoice's status, so only a processed invoice off the
  receivables list is reported paid.
- **Dutch, Flemish, German, French, Belgian French and Spanish** control-panel translations,
  following each market's bookkeeping vocabulary.
