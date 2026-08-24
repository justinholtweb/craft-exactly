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
- **Money is verified, not assumed.** Exact computes VAT from the line codes, so `buildPayload()`
  predicts that total from the mapped codes' percentages and compares it against
  `Order::getTotalPrice()`. Cents get a rounding line; a real gap refuses to send.

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

**158 checks, 0 failures.** Self-cleaning, and it needs **no Exact Online account**: `FakeApi`
replaces only `services\Api`, so the account resolver, the item resolver, the VAT determination, the
payload builder, the ledger and the reconciliation arithmetic are all the real code running against a
fixture Exact. Stubbing `Invoices` instead would test nothing that matters.

Settings and editions are changed **in memory** (`Plugin::setSettings()`, `$plugin->edition = …`).
Project config is contended in that shared harness and a long console script that writes it gets
`StaleResourceException` from something else's queue runner.

**Harness notes, none of them this plugin's fault:**

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
