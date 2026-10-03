# Changelog

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
