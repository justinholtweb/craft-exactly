---
title: Configuration
slug: configuration
order: 20
summary: Triggers, the invoice header, items and GL accounts, VAT mapping, reconciliation, customers, delivery and logging.
---

Everything here is on **Settings → Plugins → Exactly**. No setting is required to save the screen —
you need the Redirect URI on it before you can create the Exact app, so it would be the wrong way
round.

## When to invoice

| Option | What sends the order |
| --- | --- |
| Only when I ask (default) | Nothing automatic — the order panel, the console, or a backfill |
| As soon as the order completes | Commerce completing the order |
| When the order is fully paid | Commerce marking the order paid |
| When the order reaches a status | A status change to one of the **Trigger statuses** you tick |

The automatic triggers only ever *queue* the order. Nothing about Exact happens during checkout,
and a failure to schedule is logged, never thrown — an Exact outage cannot stop a customer paying or
a merchant saving an order.

An order is skipped by the triggers when it is not completed, when Exactly is not connected, when no
division is set, or when it already has an invoice (or one in flight) in the current administration.

## The invoice header

**Sales journal** — the Exact journal code invoices are booked to. `70` is Exact's default sales
journal.

**Invoice date** — the date the order was placed, the date it was paid, or today. Dates go to Exact
as the calendar day, with no time zone, as Craft sees it in its system time zone.

**Your reference** — which Craft identifier goes on the invoice as the customer's reference: order
reference, order number, short order number or order ID. The same value is what the Documents
screen shows and searches.

**Invoice description**, **Line description** and **Remarks** are object templates. The first and
last render against the order; the line description renders against each line item, with `order`
and `lineItem` also available. A blank line description uses Commerce's own.

**Payment condition** — an Exact payment-terms code. Blank leaves the account's own terms.

## Lines

Every Commerce line item becomes an invoice line with its amount **excluding VAT**. Exact computes
the VAT itself from each line's VAT code.

**Add a shipping line** puts shipping on its own line. Tax that Commerce included in prices but
that belongs to no line item comes off this line, so it is not over-stated.

**Add a discount line** puts order-level and line-level discounts on one negative line. Line
prices never include them, so nothing is counted twice. With either switch off, the preview warns
you that the order had shipping or discount that was left out.

## Items

Exact requires an item on every sales invoice line. There is no description-only line.

| Item matching | Behaviour |
| --- | --- |
| Match the SKU, fall back to a generic item (default) | Look the SKU up as an Exact item code; use the fallback item when it is not there |
| Always use the generic item | Every line goes on the fallback item, with the product carried in the description |

**Fallback item code** — the Exact item code (or its ID) unmatched lines are invoiced against. Most
stores make one generic item, `WEBSHOP` or similar, and use it for everything: a catalogue of
thousands of variants does not belong in an accounting package's stock list.

**Create missing items** creates an Exact item (a non-stock sales item, coded by SKU) instead of
falling back. It is off by default.

Shipping, discount and rounding lines each have their own **item code**. Leave one blank and that
line uses the fallback item.

## GL accounts

**Default revenue GL account** — the ledger code for product lines. Blank lets Exact use each item's
own revenue account, which is where most administrations keep it.

**Shipping GL account**, **Discount GL account** and **Rounding GL account** do the same for their
lines. Every GL and item field takes either the code you see in Exact or the GUID.

Revenue accounts per Commerce product type have no field on the settings screen; set them in
`config/exactly.php`:

```php
<?php

return [
    'glAccountByProductType' => [
        'clothing' => '8000',
        'giftCards' => '8100',
    ],
];
```

A product type in this map wins over the default. Anything set in `config/exactly.php` overrides
the settings screen, so put only this key there unless you mean to.

**Cost centre** and **Cost unit** are stamped on every line when set.

## VAT

Exactly decides how each sale should be treated, then uses the Exact VAT code you mapped for that
treatment.

| Treatment | When |
| --- | --- |
| Domestic | The buyer is in the administration's country, or the order has no address country |
| Intra-community reverse charge | EU buyer in another member state with a VAT number |
| EU consumer (OSS) | EU buyer in another member state without a (valid) VAT number |
| Export | Outside the EU |

The country comes from the shipping address, falling back to billing. The VAT number comes from
Craft 5's own **Organization Tax ID** on the billing or shipping address, with no configuration.
If your store collects it into a custom field instead, put that field's handle in **VAT number
field** — it is looked for on the order and both addresses first.

**Seller country** — the ISO code you invoice from. Blank reads it from the administration in Exact.

Map **every** treatment. An unmapped one sends no VAT code, so Exact uses the item's own, which is
right for a domestic sale and wrong for everything else. The settings screen lists any that are not
mapped yet.

**Exempt** is not something Exactly infers from an address. It applies per line, through
**VAT-exempt tax categories**: tick the Commerce tax categories you sell VAT-exempt (medical,
education, financial services and the like), and a line in one of them **that Commerce charged no
tax on** takes the Exempt code, whatever the order's treatment. A line in an exempt category that
*was* charged tax keeps the code for what it was charged — otherwise Exact's total would not match
the payment, and reconciliation would refuse the invoice. Shipping and discount lines have no category of their own: on an order whose
every line is exempt and that carried no tax anywhere, they take the Exempt code too, so a coupon on
an exempt course reconciles. On a *mixed* order they keep the order's treatment, and a discount
there can be refused by reconciliation rather than booked wrong. Once any category is ticked, an
unmapped Exempt code is listed as unmapped. The order of precedence for a line is: **Per Commerce tax rate**,
then exempt category, then the order's treatment.

**Per Commerce tax rate** lists your Commerce tax rates. When a line actually carries one of them,
its code wins over the treatment. This is how reduced rates are handled, and it is the answer for
OSS sales if Commerce charges each destination country's own rate: one OSS code has one percentage,
so map each country's rate to its own code.

**Reverse charge needs a well-formed VAT number** — on by default. The number is normalised and
checked against each member state's format, including `EL` for Greece and `XI` for Northern Ireland.
Off, any non-empty value zero-rates the sale.

**Check VAT numbers against VIES** asks the EU's VIES service before applying reverse charge. It
fails closed: a number VIES will not confirm, or a VIES that does not answer, turns the sale into an
EU consumer sale, which charges VAT, and the preview says which happened. **Check with VIES** under
it tests a number by hand.

## Reconciliation and rounding

Exact computes VAT from the line codes, so before sending, Exactly predicts that total from the
mapped codes' percentages and compares it with what the customer was charged.

**Rounding tolerance** — default `0.02`.

- A difference within it gets a rounding line, so the invoice totals exactly what was paid.
- A difference larger than it **stops the push**. Nothing is sent.
- `0` switches the check off entirely: a mismatch is sent anyway, with a warning.

**Rounding VAT code** must be a 0% code. A rounding line that attracts VAT moves the total by the
difference plus VAT and never lands on the right number.

If a mapped VAT code's percentage cannot be read from Exact, the total cannot be predicted. The
invoice is sent without reconciliation and the warning says so.

## Customers

**Match customers by** — email then VAT number (default), VAT number then email, email only, or VAT
number only. Matches are cached per administration, so a repeat customer costs no API call.

A VAT number is only used to match a **registered** customer. A guest types theirs at checkout and
nothing checks that it is theirs, so matching on it would book a stranger's order to whichever
company really owns that number. A guest is matched by email, or gets an account of their own. The
cost is the occasional duplicate for a business that keeps checking out as a guest; merge those in
Exact, or ask repeat trade customers to register.

**Create missing accounts** — on by default. Off, an order whose customer is not in Exact fails.

**Update existing accounts** keeps a matched account's address in step with the order. Off by
default: a bookkeeper's corrections should outlive a checkout. Even when on, it only updates an
account matched by the email of a **registered** customer — never a guest checkout, never a match
on VAT number, and never from a preview. A VAT number typed at checkout is verified by nobody, and
letting it overwrite an account is how a stranger could take over a real company's account in your
books.

**Account status** for new accounts: customer, prospect, suspect or none. **Company name field**
is optional; without it the address's organisation is used, then the name.

## Delivery

An invoice created through the API is a **draft** in Exact. A draft is not in the general ledger and
not receivable. Printing or sending it is what processes it.

| Send the invoice | What Exact does after the invoice is created |
| --- | --- |
| Don't — leave it for me to print (default) | Nothing; the invoice stays a draft until someone processes it in Exact |
| However the Exact account is set up | Whatever output the customer's Exact account is configured for |
| Email the PDF to the customer | Emails it |
| Exact digital postbox | Sends it to the postbox (needs an Exact Mailbox licence) |
| Peppol network | Sends it over Peppol |

**Document layout ID** and **Email layout ID** are Exact layout GUIDs, not names — a pasted name is
refused when you save. **Sender email address** and **Extra text** are passed through.

Delivery happens after the invoice is recorded. A failure to email a PDF is reported as a warning
and never makes a written invoice look unwritten.

## Automation

**Push through the queue** — on by default, and leave it on. Off, the automatic triggers send inline
inside the order save, which a customer at checkout then waits for.

**Maximum attempts** (default 5) — how many times a failed document is re-queued by
`exactly/sync/retry`, the **Retry failures** button, and maintenance.

**Retry delay (minutes)** (default 15) — how long a failed document waits after its last attempt
before any of the above picks it up again, so a cron running maintenance every five minutes does not
retry a closed journal every five minutes. `0` retries on the next run. To send one order straight
away, use its **Send to Exact Online** button. A push refused by Exact's rate limit does not use up
an attempt; its queue job re-queues itself for Exact's own reset time instead.

**Credit notes on refund** (off by default) — when Commerce records a refund that brings the total
refunded up to the order total, queue a credit note for the order, if it has a sent invoice in the
current administration. Partial refunds issue nothing and are logged instead: Exactly's credit note
reverses the whole invoice. Always queued, never inline, and a refund can never fail because of it.
See [Usage](usage#on-refund).

**Credit note amounts** (`creditNoteSign`) — positive (default) or negative. Exact's field reference
does not say which sign credit-note lines take, and no second implementation confirmed it, so this
is a setting rather than an assumption. If your first credit note doubles the invoice instead of
cancelling it, switch it.

**Read payment status back from Exact** and **Paid order status** — see
[Usage](usage#payment-status).

## Logging

**Log every call** — on by default. **Keep request and response bodies** stores payloads too;
client secrets, tokens and `Authorization` headers are redacted before anything is written.
**Keep log entries for (days)** — default 30, `0` keeps everything.

**Clear cached lookups** forgets the cached accounts, items, VAT codes, GL accounts and payment
conditions for the current administration. Use it after changing things in Exact that Exactly has
already looked up.
