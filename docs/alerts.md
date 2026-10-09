---
title: Alerts
slug: alerts
order: 35
summary: One email (and optionally a Slack or Teams message) when orders fail to reach Exact, invoicing stalls or Exact refuses the connection — and one when it clears.
---

# Alerts

The Documents screen knows everything that has gone wrong, but nobody opens an integration's admin
screen on a day it seems to be working. Exactly tells you instead, when one of three things happens:

| Incident | Opens when | Clears when |
|---|---|---|
| **Orders failing to reach Exact Online** | `alertFailureThreshold` failures (default 1) inside `alertWindowMinutes` (default 60): an invoice, credit note or payment entry Exact refused — or that Exactly refused to send because its total did not reconcile | a whole window passes with no new failure |
| **Invoicing has stalled** | a document or payment entry has sat `queued`/`sending` for more than `alertStallHours` (default 6), or — with the *completed* or *paid* trigger — an order from the last seven days is older than that and has no invoice row at all | nothing is stuck and nothing is overdue |
| **Exact Online refused the connection** | Exact refuses the refresh token (`invalid_grant`, or any 4xx from the token endpoint), answers 401 to a token Exactly has just refreshed, or the stored connection expired unused | the next authenticated request succeeds, or you reconnect |

You get **one message when an incident starts and one when it clears**, never one per failure.
Each incident has a single latch row in the database: checking it a hundred times while it is
still open sends nothing new. If it reopens within `alertCooldownMinutes` (default 60) of its
recovery message, you hear about it when that quiet period ends, and only if it is still happening.

A recovery means nothing *new* has gone wrong for a whole window — not that everything is fixed.
It says how many documents and payments still show as failed.

A network failure or a 500 is not a refused connection: those retry, and if they keep failing they
become failures, which is the first incident. A stall almost always means the queue is not running
— the alert links Craft's queue manager.

## Setting it up

**Settings → Exactly → Alerts.**

| Setting | Default | |
|---|---|---|
| `alertRecipients` | empty | Addresses separated by commas, or an `$ENV` reference. Empty means no email |
| `alertWebhookUrl` | empty | A Slack or Teams incoming-webhook URL, or an `$ENV` reference. Keep it in an environment variable: the URL is the credential |
| `alertWebhookFormat` | `slack` | `slack`, `teams`, or `json` for your own receiver |
| `alertWebhookSecret` | empty | When set, each webhook carries `X-Exactly-Timestamp` and `X-Exactly-Signature: sha256=<hmac>` over `timestamp.body` |
| `alertOnFailures` | on | |
| `alertFailureThreshold` | 1 | |
| `alertWindowMinutes` | 60 | |
| `alertOnStall` | on | |
| `alertStallHours` | 6 | |
| `alertOnAuthFailure` | on | |
| `alertCooldownMinutes` | 60 | |
| `allowPrivateAlertWebhookHosts` | off | Config file only — see below |

Mail goes through Craft's own mailer, so it uses whatever **Settings → Email** is set to. Press
**Send a test alert** (admins only) or run `php craft exactly/alerts/test` after saving to check
that both channels arrive.

Nothing here is required. A site with no recipients and no webhook still records incidents and
shows them on the Dashboard widget. If you add a recipient later, any incident that is still open
is sent at the next check. A site that has never connected to Exact checks nothing.

## When alerts are checked

- **At the end of every push and payment entry** — queue, console, the order panel or the bulk
  action: failures. No cron needed.
- **The moment Exact refuses the connection**: authentication.
- **`php craft exactly/alerts/check`**, **`exactly/sync/retry`** and **`exactly/sync/maintenance`**:
  everything, including stalls.

An incident can only be seen to *clear*, and a stall can only be seen at all, when something
checks. `exactly/sync/maintenance` on cron — which you want anyway — does it.

## What an alert says

A plain-text email: the site, the incident, what was seen, and links straight to the right screen —
the Documents screen filtered to failures, the queue manager for a stall, or Exactly's settings for
a refused connection. The Slack message, Teams card and JSON event carry the same.

What was seen is redacted before it leaves the site: the client secret and the stored access and
refresh tokens are removed by value, anything shaped like a credential (`Bearer …`,
`refresh_token=…`, `"client_secret": …`) by pattern, markup is stripped and the line is capped at
500 characters. Alerts quote Exact's error and an order or invoice number; never a customer's
details.

## The webhook

Slack and Teams incoming webhooks work as they are. The `json` format posts:

```json
{
  "event": "exactly.alert.opened",
  "incident": "failures",
  "site": "My Store",
  "title": "Orders failing to reach Exact Online",
  "detail": "1 failures in the last 60 minutes; 1 documents and payments show as failed. Latest: invoice 1042: Mandatory: GLAccount",
  "url": "https://example.com/admin/exactly/documents?status=failed",
  "syncUrl": "https://example.com/admin/exactly/documents",
  "at": "2026-10-09T08:15:00+00:00"
}
```

`event` is `exactly.alert.recovered` when it clears; `incident` is `failures`, `stalled` or `auth`.

The URL is checked every time it is used, not only when it is saved. It must be `http` or `https`
with no username or password in it, every address the host resolves to must be public — not
private, loopback, link-local (the cloud metadata service) or carrier-grade NAT — and the request
is pinned to those addresses so DNS cannot be switched between the check and the send. Redirects
are never followed. For a self-hosted Mattermost on your own network, set
`allowPrivateAlertWebhookHosts` in `config/exactly.php`. The scheme and redirect rules still apply.

If every channel fails, the alert is not marked sent, and the next check tries again.

## The Dashboard widget

**Dashboard → New widget → Exact Online health** shows whether Exactly is connected (and warns when
the connection is within a week of expiring unused), how many documents are sent, failed and in
flight, how many completed orders have no invoice, payment entries entered and failed or waiting,
when the last invoice went over, and any open incident — hover it to see what was seen. It reads
the same latch rows the alerts come from, so the widget and your inbox cannot disagree. Only people
with *View Exact Online documents* can add it.

## Changing or suppressing an alert

```php
use justinholtweb\exactly\events\AlertEvent;
use justinholtweb\exactly\services\Alerts;

Event::on(Alerts::class, Alerts::EVENT_BEFORE_NOTIFY, function(AlertEvent $e) {
    // $e->incident, $e->recovered, $e->detail
    $e->subject = '[Books] ' . $e->subject;

    // Swallow it. The latch still counts it as sent.
    if ($e->incident === Alerts::INCIDENT_STALLED && !$e->recovered) {
        $e->isValid = false;
    }
});
```
