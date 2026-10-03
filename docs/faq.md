---
title: FAQ
slug: faq
order: 50
summary: Common questions about invoicing Craft Commerce orders into Exact Online.
---

## What does Exactly actually send?

A Commerce order becomes a sales invoice in one Exact Online administration: the customer's
account, a line per line item, shipping, discounts, VAT codes, GL accounts, the journal, the
invoice date and your order reference. It can also email, post or Peppol the invoice, issue a credit
note, and read payment status back.

## How much does it cost?

$99 per Craft installation, and $79 a year to keep receiving updates. There is one edition, and
everything in these docs is in it.

## Can it slow down or break checkout?

No. The automatic triggers only queue the order, and a failure to queue is logged rather than
thrown. An Exact outage cannot stop a customer paying or a merchant saving an order. Turning
**Push through the queue** off is the one way to put Exact inside the order save, which is why it
is on by default.

## Will I get duplicate invoices?

Not by accident. A unique index on order, administration and kind is the guarantee, and every path
— the button, the trigger, a retry, a backfill — goes through the same claim. A second push is
reported as skipped. **Send again** on the order panel creates a second invoice on purpose, behind
a confirmation.

## Can I see what will be sent before it is sent?

Yes. **Preview payload** on any order shows the exact JSON, the reconciliation table and every
warning, and `exactly/sync/preview` does the same on the console. It is built by the same code that
does the sending, and it creates nothing in Exact — not even the customer's account.

## How does it handle EU VAT?

It decides each sale's treatment — domestic, intra-community reverse charge, EU consumer (OSS) or
export — from the shipping country and the customer's VAT number, then uses the Exact VAT code you
mapped for that treatment. Codes mapped to individual Commerce tax rates win over the treatment,
which is how reduced rates and per-country OSS rates are handled.

## Where does it get the customer's VAT number?

From Craft 5's own **Organization Tax ID** on the address, with no configuration, or from a custom
field if you name one. It is normalised and checked against the member state's format. Optionally
it is checked against VIES too.

## What if VIES is down?

The sale is treated as a consumer sale and VAT is charged, and the preview says VIES could not be
reached. Reverse charge fails closed: over-charging VAT is recoverable, under-charging is your
liability.

## What if the invoice does not match what the customer paid?

Exact computes VAT itself, so Exactly predicts the total Exact will arrive at and compares it before
sending. A difference within the rounding tolerance (2 cents by default) gets a rounding line.
Anything larger is refused, with both figures and the treatment to check. An invoice that is a euro
out is worse than no invoice.

## Why is my invoice a draft?

Because every sales invoice created through Exact's API is. A draft is not in the general ledger and
not receivable until it is printed or sent. Set a delivery method and Exact processes each invoice
as it arrives, or process them in Exact yourself.

## Do I need an item in Exact for every product?

No. Exact needs an item on every line, but most stores use one generic fallback item and carry the
product name in the line description. Exactly matches SKUs to item codes first by default, and can
create missing items if you want the catalogue mirrored.

## Does it handle refunds?

You can issue a credit note from the order panel. It credits the whole order — there is no partial
credit note — and is one per order. Turn on **Credit notes on refund** and a **full** refund queues
one automatically; a partial refund is logged for you to credit by hand in Exact, since a whole-order
credit note would over-credit it. See [Usage](usage#on-refund).

## Does it tell me when an invoice is paid?

Yes, with **Read payment status back from Exact** on. Each check re-reads the status of invoices
Exact has not processed yet, then reads the receivables list: a processed invoice that is no longer
owed is *paid*. A draft is reported as *draft*, never as paid. See [Usage](usage#payment-status).

## What happens when Exact's rate limit is hit?

Nothing is lost. A queued push re-queues itself for when the budget refills; the order panel tells
you how many seconds to wait; and a spent daily limit lifts by itself at Exact's reset time. See
[Troubleshooting](troubleshooting#rate-limits).

## Does it handle multiple administrations?

One at a time. Invoices go into the administration set in **Division**. The invoiced-once guarantee
is per administration, so switching **Division** and backfilling would invoice the same orders into
the new one.

## What happens to orders placed before I installed it?

Nothing, until you ask. **Backfill** on the Documents screen queues up to 100 at a time, and
`exactly/sync/backfill` takes `--since`, `--limit`, `--dry-run` and `--now`.

## Why did my connection stop working after a quiet month?

Exact's refresh token expires 30 days after it was last used. Reconnect on the settings screen. The
settings screen warns when fewer than seven days are left.

## Where are my tokens stored?

Encrypted with your Craft security key, in the database — never in project config, which is
committed to version control. The connection log redacts client secrets, tokens and
`Authorization` headers before anything is written.

## Is the Twig API safe to use on the front end?

Yes. `craft.exactly` is read-only. Nothing a template does can create an invoice.

## Which Exact Online regions are supported?

Netherlands, Belgium, Germany, France, Spain, the United Kingdom and the international site, plus a
custom base URL for any region added later.

## Is the control panel translated?

Into Dutch, German, French and Spanish, with Flemish (`nl-BE`) and Belgian French (`fr-BE`)
overlays. Craft does not offer those two in a user's language menu, so set one as
`defaultCpLanguage` in `config/general.php` to use it.

## Which versions are supported?

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.
