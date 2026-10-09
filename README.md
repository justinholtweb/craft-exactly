# Exactly

**Exact Online invoicing for Craft Commerce.** Orders become sales invoices in your Exact Online
administration, with the EU VAT worked out for you, and every call to Exact recorded so you can see
what happened.

Requires **Craft CMS 5.3+**, **Craft Commerce 5.0+** and **PHP 8.2+**.

**$99 per Craft installation, $79 a year to keep updating.** One edition — everything below is in
it. There is no feature you have to buy twice.

---

## What it does

### Turns an order into a sales invoice

Lines, shipping, discounts, VAT codes, GL accounts, the customer's account, the journal, the
invoice date, your order reference. One click on the order screen, one console command, or
automatically when an order completes, is paid, or reaches a status you choose.

### Works out the EU VAT

This is the part a naïve push gets wrong. Exactly decides how each sale should be treated from the
shipping country and the customer's VAT number, and uses the code you mapped for it:

| Treatment | When |
| --- | --- |
| **Domestic** | The buyer is in the same country as the administration |
| **Intra-community reverse charge** | EU business, other member state, valid VAT number |
| **EU consumer (OSS)** | EU consumer, other member state — destination VAT |
| **Export** | Outside the EU |

VAT numbers are read from Craft 5's own **Organization Tax ID** address attribute with no
configuration, normalised (`NL 8025.13.146.B.01` → `NL802513146B01`), and structurally validated per
member state — including the two prefixes that are not the country code, `EL` for Greece and `XI`
for Northern Ireland. It can also check the number against the EU's **VIES** service before applying
reverse charge.

Reverse charge **fails closed**: a VAT number that cannot be confirmed is treated as a consumer sale,
which charges VAT. Over-charging is recoverable; under-charging is your liability.

### Checks the arithmetic before it sends anything

Exact computes VAT itself from each line's VAT code. Exactly predicts what Exact will arrive at and
compares it with what the customer actually paid:

- Inside the rounding tolerance (2 cents by default) → a rounding line is added so the invoice totals
  exactly what was charged.
- Outside it → **nothing is sent**, and you are told both figures and which VAT mapping to look at.

Books that do not match the payments are the one failure a merchant cannot find on their own.

### Never invoices an order twice

An invoice in an accounting system is not a cache entry. A unique index on
`(order, administration, kind)` is the guarantee, and every path — the button, the queue, a retry, a
backfill — goes through the same claim. A second push is refused and reported as *skipped*, not
failed. Sending a duplicate is possible, but only as a deliberate act with a confirmation.

### Shows you what will be sent

**Preview payload** on any order renders the exact JSON Exact will receive, plus the reconciliation
table and any warnings. It is built by the same code that does the sending, so a preview is not an
approximation. Nothing is created in Exact by looking.

### Enters the payments too

Optionally, every successful Commerce payment becomes a bank (or cash) entry line in the journal you
choose — Stripe's clearing journal, PayPal's, the till — matched to its invoice by customer and
invoice number, so Exact shows the invoice settled and nobody matches a payout by hand. Refunds are
matched to the credit note; processor fees can go on their own line so the entry nets to what
reached the bank. A payment waits until its invoice is processed in Exact, is entered once per
transaction however many times it is retried, and the **Payments** screen compares Commerce's
captures with Exact's entries day by day. Payment status is still read back from Exact's
receivables list as well.

### Tells you when something is wrong

One email — and optionally a Slack or Teams message, or a signed JSON webhook — when orders start
failing to reach Exact, when invoicing stalls (the queue is not running), or when Exact refuses the
connection; and one more when it clears. Never one per failure. An **Exact Online health** Dashboard
widget shows the same at a glance.

### Works from the Orders index

An **Exact Online** column on Commerce's Orders index (invoice number and status), an **Exact Online
status** filter for building a *Not yet invoiced* or *Failed* source, and a **Send to Exact Online**
bulk action that queues the selected orders — through the same claim, so an invoiced order is never
invoiced twice.

---

## Setting it up

1. Create an app at [apps.exactonline.com](https://apps.exactonline.com).
2. In Craft: **Settings → Plugins → Exactly**. Copy the **Redirect URI** shown there and register it
   on the Exact app — character for character.
3. Paste the app's client ID and secret in, and save.
4. Click **Connect to Exact Online** and authorise.
5. Enter your administration's code in **Division** (**List administrations** shows them), set the
   sales journal and fallback item, and map a VAT code to each treatment.

### The fallback item

Exact requires an item on every sales invoice line — there is no description-only line. Most stores
put everything on one generic item (`WEBSHOP`, say) and carry the product name in the line
description: a Commerce catalogue of four thousand variants does not belong in an accounting
package's stock list. By default Exactly matches the SKU to an Exact item code first and falls
back to the generic item; it can also create missing items, if you want the catalogue mirrored.

---

## Templating

```twig
{% set invoice = craft.exactly.document(order) %}

{% if invoice %}
    <p>Invoice {{ invoice.invoiceNumber }} — {{ invoice.getStatusLabel() }}</p>

    {% if invoice.vatTreatment == 'reverse-charge' %}
        <p>Intra-community supply — VAT reverse-charged.</p>
    {% endif %}
{% endif %}
```

`craft.exactly` is read-only. A template render is not the place to write to an accounting system,
and a page that could would be one crawler away from a duplicate invoice.

| | |
| --- | --- |
| `craft.exactly.document(order)` | The invoice recorded against an order, or `null` |
| `craft.exactly.documents(order)` | Every document, invoices and credit notes |
| `craft.exactly.invoiceNumber(order)` | The Exact invoice number, or `null` |
| `craft.exactly.isPaid(order)` | Whether Exact reports it paid (`null` if unknown) |
| `craft.exactly.vatTreatment(order)` | `domestic`, `reverse-charge`, `oss`, `export` |
| `craft.exactly.isConnected()` | Whether the Exact connection is live |

---

## Languages

The control panel is translated into **Dutch**, **German**, **French** and **Spanish**, following
each market's own bookkeeping vocabulary rather than a literal rendering of the English —
*administratie*, *btw verlegd*, *Umsatzsteuer*, *autoliquidation*, *inversión del sujeto pasivo*.
France issues an *avoir*, Spain a *factura rectificativa*, Germany a *Gutschrift*; a literal
"credit note" would be wrong in all three. Those are Exact Online's regional markets; anywhere else
falls back to English.

**Flemish and Belgian French** ship too, as `nl-BE` and `fr-BE` overlays — Belgium says *note de
crédit* where France says *avoir*, and a Flemish bookkeeper *punt af* where a Dutch one *lettert
af*. One caveat: Craft validates a user's preferred control-panel language against the 31 locales
it ships its own translations for, and neither `nl-BE` nor `fr-BE` is among them, so they cannot be
picked from the language menu. To use them, set them as `defaultCpLanguage` in
`config/general.php` — that setting is not validated, and the overlay loads.

---

## Console

```sh
php craft exactly/connect/status              # what it is connected to, and the call budget left
php craft exactly/connect/divisions           # every administration this login can write to
php craft exactly/connect/check-vat NL802513146B01

php craft exactly/sync/status
php craft exactly/sync/preview 1234           # the payload for one order, sends nothing
php craft exactly/sync/order 1234             # send one order
php craft exactly/sync/backfill --since=2026-01-01 --limit=250 --dry-run
php craft exactly/sync/retry
php craft exactly/sync/payments               # reconcile against Exact's open items
php craft exactly/sync/maintenance            # prune, retry, reconcile, enter payments, check alerts — for cron

php craft exactly/payments/preview --transaction=5678
php craft exactly/payments/reconcile --days=7
php craft exactly/alerts/check
php craft exactly/alerts/test

php craft exactly/log/tail 25
php craft exactly/log/prune --days=30
```

`exactly/sync/maintenance` is the one to put on a schedule.

---

## Things worth knowing

**Exact's access token lasts ten minutes and its refresh token is single-use.** Every refresh
returns a new one and burns the old. Two queue workers refreshing at once would kill the connection
outright, so Exactly refreshes under a mutex and re-reads the stored token inside the lock. The
refresh token also expires **30 days after its last use** — a shop that goes quiet for a month has
to reconnect, and the settings screen warns before that happens.

**Exact allows about 60 calls a minute per administration.** A backfill is staggered, a console
queue worker waits for the minute to reset, and a rate limit inside a web request is refused rather
than slept through — nothing here is allowed to hold up a checkout. Run the queue from the console.

**A new sales invoice is a draft.** A draft is not in the general ledger and is not receivable, so
syncing a year of orders with delivery switched off leaves the revenue report empty. Exactly warns
when it creates one; set a delivery method, or process them in Exact.

**Nothing Exactly does can stop a customer paying.** The automatic triggers queue rather than push,
and a failure to email a PDF never makes a written invoice look unwritten.

**Tokens are encrypted with your Craft security key** and stored in the database, never in project
config — they rotate constantly, and project config is a file that gets committed. The connection
log redacts client secrets, tokens and `Authorization` headers before anything is written.

---

## Licence

[The Craft Licence](LICENSE.md). © Justinholt.com Incorporated.
