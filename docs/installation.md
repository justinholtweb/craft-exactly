---
title: Installation
slug: installation
order: 10
summary: Requirements, install, registering the Exact Online app, connecting, and choosing an administration.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later
- An Exact Online login that can register an app and write to the administration you invoice from

Exactly has no runtime dependencies beyond Craft's own Guzzle. There is no build step and no asset
bundle.

One edition: **$99 per Craft installation, $79 a year** to keep receiving updates. Everything in
these docs is in it.

## Install

```sh
composer require justinholtweb/craft-exactly
php craft plugin/install exactly
```

Or find **Exactly** in the Craft Plugin Store and install it from there.

Nothing is sent anywhere on install. **When to invoice** starts at *Only when I ask*, so no order
reaches Exact until you push one by hand or change that setting.

## Register an Exact Online app

Exact Online only offers the OAuth2 authorization code grant, so the connection is approved once,
by a person, in a browser.

1. Open **Settings → Plugins → Exactly** and copy the **Redirect URI** at the top. It is a plain
   site path ending in `exactly/oauth/callback`.
2. Go to [apps.exactonline.com](https://apps.exactonline.com), register an app, and paste the
   Redirect URI in. Exact compares it character for character — scheme, host, trailing slash — so
   copy it rather than retyping it.
3. Copy the app's **Client ID** and **Client secret** back into Exactly's settings.
4. Set **Region** to the Exact site your account lives on. Exact runs a separate site per country
   (`start.exactonline.nl`, `.be`, `.de`, `.fr`, `.es`, `.co.uk`, `.com`), and an account created
   on one cannot be reached on another.
5. Save.

If the Redirect URI shown contains `?p=`, Craft is generating URLs with the script name in them,
and the settings screen shows a warning under it.
Turn on `omitScriptNameInUrls` (with the usual rewrite to `index.php`) so the URI is a clean path —
OAuth providers are fussy about query strings in redirect URIs.

Keep the credentials out of project config:

```sh
# .env
EXACT_CLIENT_ID="…"
EXACT_CLIENT_SECRET="…"
```

Then enter `$EXACT_CLIENT_ID` and `$EXACT_CLIENT_SECRET` in the two fields. A secret typed in
directly is saved to project config, and the settings screen warns you when it is.

**Custom base URL** overrides the region, for an Exact region newer than the release you are on. It
has to be an `https://` address on an Exact Online domain — every call carries the access token and
every refresh carries the client secret, so Exactly will not send them anywhere else.

## Connect

Press **Connect to Exact Online**, sign in to Exact and approve. You land back on the settings
screen with *Connected to Exact Online as …*. Only admins can connect, reconnect or disconnect.

The tokens are encrypted with your Craft security key and stored in the database — never in project
config, because they rotate on every refresh and project config is a file that gets committed.

The connection belongs to the region and client ID it was made with. Change either and Exactly
treats the site as not connected until you connect again.

## Choose the administration

Exact calls an administration a *division*. Invoices go into exactly one.

Press **List administrations** to see every administration the connected login can write to, as
`code — name`. Archived ones are left out. Put the code you want in **Division** and save.

Leaving **Division** at `0` uses whichever administration the connected Exact user was in when they
approved the connection. That works, but it is worth setting explicitly: a reconnect by a
colleague who was looking at a different administration would otherwise move your invoices with it.

The same list is on the console:

```sh
php craft exactly/connect/divisions
```

## Check it before you trust it

- **Test connection** makes a real call and reports who you are connected as.
- Map a VAT code to each treatment and set a **Fallback item code** — see
  [Configuration](configuration). Without a fallback item, any line whose SKU is not in Exact fails
  the push.
- Open any completed order, press **Preview payload** in the Exact Online panel, and read the
  reconciliation table. It is built by the same code that does the sending, and nothing is created
  in Exact by opening it.

Then send one order by hand, look at it in Exact, and only after that change **When to invoice**.

## Start the queue properly

Automatic invoicing, backfills and retries all run through Craft's queue. Run it from the console —
`php craft queue/listen` under a process manager, or `queue/run` from cron — rather than relying on
web requests to drain it. A console worker can wait out Exact's per-minute call limit; a web request
cannot, and will fail the job instead. See [Troubleshooting](troubleshooting#rate-limits).

Put the maintenance command on a schedule too:

```sh
*/15 * * * * php /path/to/craft exactly/sync/maintenance
```

It prunes the log, re-queues failed documents that still have attempts left, and — if payment
write-back is on — reconciles payment status.
