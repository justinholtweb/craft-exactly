# Exactly — build plan

Written before the build, kept as the record of what was decided and why.

## The problem

A Craft Commerce store selling in the EU has to get its orders into an accounting package, and for
Dutch and Belgian merchants that package is overwhelmingly **Exact Online**. The manual route is a
CSV export and a bookkeeper; the automated route is either a per-order Zapier bill or a bespoke
integration.

The interesting part is not the HTTP. It is that a naïve push produces books that are *wrong* in ways
nobody notices until a VAT return:

- Every cross-border order gets the domestic VAT code.
- Reverse charge is applied to anything with text in a VAT field.
- Exact recomputes VAT from the line codes, so its total drifts from what the customer paid.
- A retry, a double click or an overlapping queue job invoices the same order twice, and an issued
  invoice cannot simply be deleted.

Exactly is built around those four, not around the endpoint list.

## Scope

**In:**

- OAuth connection, all regions, division selection.
- Orders → sales invoices: lines, shipping, discounts, VAT codes, GL accounts, journal, dates,
  references, payment condition, cost centre/unit.
- Customers → Exact accounts (match, cache, create, optionally update).
- Purchasables → Exact items (match on SKU, cache, optionally create, fallback item).
- EU VAT determination + VIES.
- Total reconciliation with a rounding line.
- Refunds → credit notes.
- Delivery: email, postbox, **Peppol**.
- Payment status read back from the receivables list.
- Documents ledger, connection log, order-edit panel, console commands, read-only Twig API.

**Out**, deliberately: purchase invoices, general-journal entries, stock movements, subscriptions,
projects, payroll, and anything that writes to Craft from Exact other than payment status.

## Editions and price

**One paid edition, $99 with a $79 annual renewal.** Decided 2026-08-24, following Sevvies rather
than the family's usual Lite/Pro split.

The reasoning against a free tier here: everything Exactly does is load-bearing the moment a merchant
switches it on. A Lite tier that pushes invoices by hand but cannot reconcile payments, cannot issue
credit notes and cannot run automatically is not a smaller version of the product — it is a version
that leaves the books half-done, which is worse than not integrating at all.

## Architecture

Two invariants, following the family pattern (`Shipper`, `Freshh`, `Trackr`, `Abacus`):

1. `services\Invoices::buildPayload()` is the only place an order becomes a payload.
2. `services\Documents::claim()` is the only place a document row is created or advanced.

Services: `Oauth`, `Api`, `Divisions`, `Accounts`, `Items`, `Ledger`, `Vat`, `Invoices`,
`Documents`, `Payments`, `Sync`, `Log`. Helpers: `Odata`, `Vat`. One queue job, `PushOrder`.

### Idempotency

`{{%exactly_documents}}` unique on `(orderId, division, kind)`. `claim()` attempts the insert first
and treats the duplicate-key failure as the *expected* branch; taking over an existing row is an
`UPDATE … WHERE status = <what was read>` whose affected-row count decides the winner. A `sending`
row is honoured for 15 minutes, then reclaimable, so a worker killed mid-push cannot wedge an order.

Forcing a duplicate is possible and takes a confirmation, because occasionally it is what the
merchant genuinely wants.

### Token handling

Access token: 10 minutes. Refresh token: single-use, 30 days from last use. Both facts together are
what make this dangerous — two workers refreshing the same stored token kill the connection. So:
mutex, then re-read inside the lock, then refresh only if still needed.

Storage is `{{%exactly_connections}}`, encrypted with the Craft security key, base64 on the way in
because `encryptByKey()` returns raw binary. Not project config: tokens rotate constantly and project
config gets committed.

### VAT

`helpers\Vat` decides the treatment from seller country, buyer country and VAT number. Settings map
treatment → Exact VAT code; Pro adds a per-Commerce-tax-rate map that takes precedence, because a
merchant who has modelled reduced rates knows better than any inference.

Reverse charge fails closed at every step: malformed number → OSS; VIES unreachable → OSS; VIES says
no → OSS. Charging VAT is recoverable, not charging it is the merchant's liability.

### Money

Per line: `subtotal − taxIncluded`, from Commerce's own figures, so tax-inclusive pricing is handled
without a setting. Included tax belonging to no line comes off the shipping line. Discounts —
order-level and line-level — become one negative line; they are never inside `subtotal`, so nothing
doubles up.

Then predict Exact's VAT from the mapped codes and compare with `getTotalPrice()`. Within tolerance →
rounding line. Outside → refuse, and name both figures and the treatment.

## Testing

`tests/integration/checks.php`, run in the shared plugin-testing harness, with a `FakeApi` that
replaces only the HTTP layer. Everything below it is the real code. 158 checks.

CP screens, the settings round-trip (including nested arrays) and the OAuth redirect route are
smoke-tested over real HTTP; console commands by direct instantiation, because a sibling plugin
breaks Yii's controller discovery in that harness.

## Languages

Exact Online runs seven regional hosts, and the family precedent is unambiguous: every vendor-locale
accounting integration ships its market's languages (Sevvies `de`, Twinsies `nl`, Vismaz `sv`, Bird
`nl`/`nl-BE`/`fr-BE`/`de`). Exactly ships `nl`, `nl-BE`, `de`, `fr`, `fr-BE` and `es`.

Full catalogues for `nl`, `de`, `fr` and `es`; `nl-BE` and `fr-BE` are overlays, because Yii merges
them over their base language and only register and a handful of words actually differ. Terminology
follows each market's bookkeeping vocabulary, not a literal translation.

The Belgian overlays come with a caveat found while testing them: Craft validates a user's
preferred CP language against `getAppLocaleIds()` — the 31 locales it ships its own translations
for — and returns null for anything else. `nl-BE` and `fr-BE` are therefore not selectable in the
language menu, and only load when set as `defaultCpLanguage`, which Craft does *not* validate. They
ship anyway: a Belgian agency setting one line of config is a plausible install, and the files are
twenty lines each.

## Still to do

Marketing site, registry entry, GitHub repo, Packagist, Plugin Store submission. And the one
unverified protocol detail — the sign convention on credit-note lines — wants confirming against a
real Exact administration; it ships as a setting until then.
