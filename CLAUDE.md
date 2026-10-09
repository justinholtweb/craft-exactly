# Exactly — Craft CMS 5 Plugin

## Project Overview

Exactly turns Craft Commerce orders into sales invoices in an **Exact Online** administration, works
out the EU VAT treatment, and reconciles the total before anything is sent. Distributed as
`justinholtweb/craft-exactly`. **One paid edition, $99 / $79 renewal** — no `editions()` override,
no feature gating anywhere in the code.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: no asset bundles, no JS beyond inline `{% js %}` blocks
- No runtime dependencies beyond Craft's own Guzzle

## Architecture

### Namespace & package

- Namespace: `justinholtweb\exactly`
- Package: `justinholtweb/craft-exactly`
- Handle: `exactly`

### The two invariants

1. **`services\Invoices::buildPayload()` is the only place an order becomes an Exact payload.** The
   CP preview, the console preview, the manual push and the queue job all go through it, so a
   preview is byte-identical to what Exact receives. That is the whole point of previewing an
   accounting push.
2. **`services\Documents::claim()` is the only place a document row is created or advanced.** Manual
   push, automatic push, queue retry, backfill and the credit-note flow all land there, so the "has
   this been invoiced?" decision is made once and cannot disagree with itself.

### Data model

- `{{%exactly_documents}}` — unique on `(orderId, division, kind)`; that index *is* the guarantee
  that an order cannot be invoiced twice into the same administration.
- `{{%exactly_connections}}` — the OAuth token store. Encrypted, in the database, **never** in
  project config: tokens rotate on every refresh and project config is a file that gets committed.
- `{{%exactly_accounts}}` / `{{%exactly_items}}` — resolution caches, unique per division, so a
  repeat customer costs no call out of a 60-per-minute budget.
- `{{%exactly_log}}` — the connection log.
- `{{%exactly_payments}}` — payment entries, unique on `(transactionId, division)`: one Commerce
  transaction is one bank/cash entry, the same guarantee `exactly_documents` gives invoices (keyed
  on the transaction because an order can be paid in several captures).
- `{{%exactly_alerts}}` — failure-alert latches, unique on `incident` (Erpy keys it on
  `(connectionId, incident)`; Exactly has one connection, so no column).

### The three things that carry the design

- **Refresh under a mutex.** Exact's access token lasts 10 minutes and its refresh token is
  single-use — every refresh burns the old one. Two workers refreshing the same stored token means
  the second presents a spent token, gets `invalid_grant`, and the connection is *dead* until a human
  re-authorises. `Oauth::refresh()` locks, then **re-reads the row inside the lock**, so whoever lost
  the race finds a good token and makes no request at all.
- **Idempotency is a unique index plus an atomic claim.** `claim()` attempts the insert first and
  treats the duplicate-key failure as the expected branch; taking over an existing row is an
  `UPDATE … WHERE status = <what was read>` whose affected-row count is the answer. A `sending` row
  is respected for 15 minutes so a killed worker cannot wedge an order forever.
  `markQueued()` follows the same rule atomically (never `sent`, never a fresh `sending`) and its
  callers push a job only when it returns true — a `queued` row is one a new job may claim.
- **Money is verified, not assumed.** Exact computes VAT from the line codes, so `buildPayload()`
  predicts that total from the mapped codes' percentages and compares it against
  `Order::getTotalPrice()`. Cents get a rounding line; a real gap refuses to send — and the refusal comes *before* the
  account and items are resolved, because resolving them can create them in Exact.

### Payment entries (theme 2, 2026-10-09)

`services\PaymentEntries` posts each successful capture/purchase (and refund) to
`financialtransaction/BankEntries` or `CashEntries` with its lines inline. It keeps both invariants
in its own shape: `buildPayload()` is the only place a transaction becomes a body (preview and post
share it), and `claim()` (insert-first, conditional UPDATE, 15-minute `sending` window — the
`Documents::claim()` shape on its own table, because the documents index is per order/kind) is the
only place a payment row is created or moved to `sending`. A payment *waits* (attempt given back)
until its invoice is sent **and processed** — the status is re-read via `Payments::refreshStatuses()`
— because a draft is not an open item. `Invoices::runPush()` calls `queueForOrder()` on success;
`Sync::runMaintenance()` reads statuses first, then `retryUnsent()`. Any attempt after the first
looks the line's `Description` (order ref + transaction hash, unique) up in `*EntryLines` before
posting: the guard for a POST whose answer was lost. Config gaps (`PaymentEntryException`) fail
without spending an attempt. `paymentAmountSign` is a switch like `creditNoteSign` (unverified).
Fees: Zo's reader, copied.

### Failure alerts (ported from Erpy via Zo, 2026-10-09)

`services\Alerts` is the family reference with the connection dimension dropped. Incidents:
**failures** (failed documents + failed payment entries by `dateUpdated` inside the window,
threshold to open, a quiet window to close), **stalled** (rows `queued`/`sending` past
`alertStallHours`, plus — only for the `completed`/`paid` triggers — orders from the last 7 days past
that age with no invoice row; measured, so it closes by itself), **auth** (signal from
`Oauth::requestToken()` on a 4xx refresh and from `Api::request()` on a 401 that survived the forced
refresh; cleared by `noteAuthSuccess()` on any 2xx; *also* measured open when the stored connection
is no longer usable). Hooks: `Invoices::push()` and `PaymentEntries::register()` wrap the real work
and call `afterSync()` in a `finally`; `exactly/sync/retry`, `/maintenance` and `exactly/alerts/check`
run `check()`. `check()` does nothing without credentials and a stored connection. Do not change: the
conditional-UPDATE claim/release, redaction before anything leaves, `webhookTarget()` (`helpers\Ip`
is the family copy — keep it identical), the HMAC header, every path fail-open.

### Order status (Orders index column and condition rule)

`Documents::orderSummaries()` (PHP, two queries per page) and `Documents::orderStatusCondition()`
(SQL, for `ExactStatusConditionRule::modifyQuery()`) define the same eight sets in the same
precedence — failed > credited > paid > invoiced > inFlight > pending/skipped > none — for the
*current division* only, and `tests/integration/orders.php` holds them to partitioning the fixtures
identically. Change one, change both. The column is prefetched from
`OrderQuery::EVENT_AFTER_POPULATE_ELEMENTS` on `element-indexes/*` requests; the rule is registered
unconditionally, and `setValues()` keeps stale values (only known ones reach SQL; chosen but none
known is `0=1` for *in*, no filter for `ni`), so a removed status can never widen a saved source.
`SendToExact` goes through `Sync::schedule(..., alwaysQueue: true)`, so an invoiced or in-flight
order pushes no job.

### Protocol notes (read, not guessed)

Read from Exact's own field reference (`start.exactonline.nl/docs/HlpRestAPIResourcesDetails.aspx`)
and cross-checked against `picqer/exact-php-client`, which is the de-facto PHP client and encodes the
behaviour rather than the documentation.

- **`Accept: application/json` is mandatory** or Exact answers XML. This is the single commonest
  reason a first integration "returns nothing".
- Responses are wrapped in a `d` envelope; collections nest again under `results` and page through
  `__next`, not `$skip`.
- Dates come back as `/Date(ms)/` (a UTC epoch in **milliseconds**) and go in as **zone-less ISO
  8601**. Sending `Z` is accepted and then shifted — which is how an invoice dated the 1st lands in
  the previous VAT period.
- `Prefer: return=representation` makes a POST hand back the created entity, invoice number included.
- Errors are `{"error":{"message":{"value":"…"}}}` plus a `Reason` response header.
- Rate limits arrive on six headers, daily and minutely. **`X-RateLimit-Minutely-Reset` is epoch
  milliseconds**, not seconds.
- `Code` columns are fixed-width, space-padded on the left to 18 characters, and Exact's own docs say
  a `$filter` has to match that. An unpadded `Code eq '1024'` matches nothing and reports no error —
  which reads exactly like "this SKU is not in Exact", and ends in a duplicate.
- `current/Me` is the **only** endpoint without a division segment. `hrm/Divisions` has one, so
  listing administrations needs a working division first.
- `Item` and `GLAccount` are **mandatory** on a sales invoice line. There is no description-only
  line, which is why the fallback item exists.
- A sales invoice created through the API is a **draft** (`Status` 10). A draft is not in the general
  ledger and not receivable — so "not on the receivables list" means "not booked", not "paid".
  Printing or sending it is what processes it.
- Bank and cash entries: `financialtransaction/BankEntries` (bank **and** payment-service journals)
  and `CashEntries`, lines posted inline as `BankEntryLines`/`CashEntryLines`. Header needs
  `JournalCode`; a line needs `GLAccount` and `AmountFC`; `OurRef` is an **Int32** documented as
  "Invoice number", and with `Account` it is what Exact matches to the open item. **Unverified**
  (no live division): the line sign for money received (`paymentAmountSign`, default positive — the
  docs only say opening balance + lines = closing balance) and whether Exact auto-matches on
  `OurRef` alone or needs the line's account to be the debtors control account.
- **Unverified:** whether credit-note (`Type` 8021) lines take positive or negative amounts. Exact's
  field reference does not say and no second implementation confirmed it, so it is a setting
  (`creditNoteSign`, default positive) rather than an assumption.

## Traps found while building this

- **`ElementQuery::$subQuery` does not exist before `prepare()`**, so an extra join cannot be hung on
  an element query up front — and a condition set on the element query itself lands on the *outer*
  query, which selects from that subquery and can only see `elements.id`. `Sync::getUninvoicedOrders()`
  does a plain anti-join for the IDs and hydrates elements afterwards.
- **`currentUser` is null outside a logged-in request, and devMode turns on Twig's
  `strict_variables`** — so a bare `currentUser.admin` throws instead of reading false. Guard it.
- **Craft's `Command::upsert()` derives the update half from the insert half**, so *everything* goes
  in `$insertColumns` and `$updateColumns` stays `true`. A key/value split leaves `NOT NULL` columns
  null on the insert path — i.e. on the first run, the one that matters.
- **A user's preferred CP language is validated against `getAppLocaleIds()`** — the 31 locales
  Craft ships its own translations for. `nl-BE` and `fr-BE` are not among them, so
  `User::getPreferredLanguage()` returns null for them and the overlay never loads from the
  language menu. `defaultCpLanguage` is returned *unvalidated*, so that is the way in. Verified
  both ways over HTTP, not reasoned from the source.
- **`Console::stdout()` writes to `\STDOUT` directly**, so `ob_start()` captures nothing from a
  console command. Assert on the exit code.
- **`stdout()` passes every extra argument to `Console::ansiFormat()`**, so a null colour is not "no
  colour" — it is an argument that throws in there.

- **`Oauth::refresh()` used to return the stored token whenever its timestamp said it was still
  good**, so the 401 retry re-sent the very token Exact had just refused. `refresh($rejectedToken)`
  now refreshes that token regardless of its stored expiry (another process's fresh token is still
  respected).
- **`$row['col'] ?? 'x'` is `'x'` when the column is NULL** — the wrong tool for asserting a latch
  column was released to null; use `array_key_exists`.

See `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps, and
`[[craft-freshh-gotchas]]` for the sibling accounting integration — `encryptByKey()` returning raw
binary and `OrderStatusEvent` carrying `$order` directly both came from there.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container. Use `docker exec`, not
`ddev exec` — `ddev exec` re-checks the project is running and the web container now takes longer to
pass its health check than ddev waits.

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-exactly/tests/integration/checks.php
docker exec -w /var/www/html ddev-plugin-testing-web bash -lc \
  'find /var/www/craft-exactly/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-exactly/tests/integration/payments.php  # payment entries, scripted Exact
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-exactly/tests/integration/orders.php    # Orders index column, condition rule, bulk action
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-exactly/tests/integration/alerts.php    # latch, mail, SSRF, webhook, auth signals, widget, console
```

`payments.php`, `orders.php` and `alerts.php` share `tests/integration/_support.php`: they put a
Guzzle `MockHandler` client on the real `services\Api`/`services\Oauth` (`$client` properties), so
the request, 401, rate-limit and logging code runs against a scripted Exact. Their HTTP checks hit
the harness's own web server, whose saved settings have no connection for the run's fixture
division, so they assert permissions, methods, CSRF and rendering only.

**225 checks, 0 failures** in `checks.php`. Self-cleaning (and scoped: cleanup deletes only the fixture division's
cache rows and log rows this run wrote, never a whole table), and it needs **no Exact Online account**: `FakeApi`
replaces only `services\Api`, so the account resolver, the item resolver, the VAT determination, the
payload builder, the ledger and the reconciliation arithmetic are all the real code running against a
fixture Exact. Stubbing `Invoices` instead would test nothing that matters.

Settings and editions are changed **in memory** (`Plugin::setSettings()`, `$plugin->edition = …`).
Project config is contended in that shared harness and a long console script that writes it gets
`StaleResourceException` from something else's queue runner.

Anything that queues a job runs under `withFakeQueue()`: the harness's real queue is drained by a
shared runner, which would execute the job in another process against the real `services\Api`.

**Harness notes, none of them this plugin's fault:**

- `craft-twinsies` also listens to Commerce's after-refund event and queues its own job, so refund
  checks count only Exactly's `PushOrder` jobs.
- The shared queue runner drains jobs mid-run, so a queue-count check can only assert "nothing was
  added" (`<=`), never equality.
- `craft-penny` types its `Elements::EVENT_BEFORE_SAVE_ELEMENT` handler as `ModelEvent` while Craft
  passes an `ElementEvent`, so every element save fatals while it is enabled. `checks.php` detaches
  that handler in-process.
- `craft-bird` declares `InspectController::table()` non-public while `craft\console\Controller::table()`
  is public, which is a compile error the moment Yii enumerates console controllers — so `php craft
  <anything>` fatals in that harness. Console commands were verified by instantiating the controllers
  directly.
- `craft-my`'s order-panel template has a Twig syntax error that 500s every Commerce order edit
  screen, so this plugin's panel was verified by rendering its template directly.

## Coding conventions

- `Craft::t('exactly', '…')` for user-facing strings; `src/translations/en/exactly.php` lists them
  all, and `nl`, `de`, `fr` and `es` are full catalogues beside it. `nl-BE` and `fr-BE` are
  *overlays* — Yii's `PhpMessageSource` merges them over `nl`/`fr`, so only strings that genuinely
  differ belong there. Regenerate `en` with the extractor after changing any copy, then reconcile
  the others.
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Anything on the checkout or order-save path fails **open**: an Exact outage must never be able to
  stop a customer paying or a merchant saving an order
