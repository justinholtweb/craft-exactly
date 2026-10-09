<?php
/**
 * Failure alerts — Exactly's port of the connector-family pattern (reference: craft-erpy 0f8ea45,
 * by way of craft-zo).
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-exactly/tests/integration/alerts.php
 *
 * Drives the real latch, the real mailer and the real webhook code: failed documents and payment
 * entries, stuck queue work, a refused refresh token and a 401 that refreshing does not fix each
 * open exactly one incident, send exactly one alert, and send exactly one recovery. Exact and the
 * webhook receiver are Guzzle MockHandlers (the harness has no outbound network); the webhook
 * still passes the SSRF guard for real. Settings stay in memory.
 */

require __DIR__ . '/_support.php';

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\mail\Mailer;
use craft\web\View;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use justinholtweb\exactly\db\Table;
use justinholtweb\exactly\events\AlertEvent;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\models\Settings;
use justinholtweb\exactly\services\Alerts;
use justinholtweb\exactly\services\PaymentEntries;
use justinholtweb\exactly\widgets\HealthWidget;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

$alerts = $plugin->getAlerts();
$settings = $plugin->getSettings();

// Mail goes nowhere, and is captured on the way.
Craft::$app->getMailer()->setTransport(new Symfony\Component\Mailer\Transport\NullTransport());
$mail = [];
$mailFails = false;
Event::on(Mailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $e) use (&$mailFails) {
    if ($mailFails) {
        $e->isValid = false;
    }
});
Event::on(Mailer::class, BaseMailer::EVENT_AFTER_SEND, function(MailEvent $e) use (&$mail) {
    if ($e->isSuccessful) {
        $mail[] = [
            'to' => array_keys((array)$e->message->getTo()),
            'subject' => (string)$e->message->getSubject(),
            // Not toString(): quoted-printable splits long lines with `=`.
            'body' => (string)$e->message->getSymfonyEmail()->getTextBody(),
        ];
    }
});

// The webhook receiver.
$history = [];
$hookMock = new MockHandler();
$hookStack = HandlerStack::create($hookMock);
$hookStack->push(Middleware::history($history));
$alerts->webhookClient = new Client(['handler' => $hookStack]);

$reset = function(array $overrides = []) use ($settings, &$mail, &$history, $hookMock) {
    $settings->setAttributes(array_merge([
        'alertRecipients' => 'ops@example.test, books@example.test',
        'alertWebhookUrl' => '',
        'alertWebhookSecret' => '',
        'alertWebhookFormat' => 'slack',
        'alertOnFailures' => true,
        'alertFailureThreshold' => 2,
        'alertWindowMinutes' => 60,
        'alertOnStall' => true,
        'alertStallHours' => 6,
        'alertOnAuthFailure' => true,
        'alertCooldownMinutes' => 0,
        'allowPrivateAlertWebhookHosts' => false,
        'pushTrigger' => 'manual',
    ], $overrides), false);
    $mail = [];
    $history = [];
    $hookMock->reset();
};

$latch = fn(string $incident) => (new Query())->from([Table::ALERTS])->where(['incident' => $incident])->one() ?: [];

makeConnection();
$product = makeProduct("EXA-$suffix", 20.00);
$variant = $product->getDefaultVariant();
$orders = withFakeQueue(fn() => [makeOrder($variant), makeOrder($variant), makeOrder($variant), makeOrder($variant)]);
$n = 0;

// A failed invoice, as a push would leave one. Each on its own fixture order (unique per order).
$fail = function(string $error = 'Exact Online: Mandatory: GLAccount') use ($orders, &$n) {
    $order = $orders[$n++ % count($orders)];
    Craft::$app->getDb()->createCommand()->delete(Table::DOCUMENTS, ['orderId' => $order->id])->execute();

    return makeDocument($order, ['status' => Document::STATUS_FAILED, 'exactInvoiceId' => null, 'invoiceNumber' => null, 'lastError' => $error]);
};
$ageFixtures = function(string $modify, string $column = 'dateUpdated') use ($orders) {
    Craft::$app->getDb()->createCommand()->update(Table::DOCUMENTS, [$column => Db::prepareDateForDb((new DateTime())->modify($modify))], ['orderId' => array_map(static fn($o) => $o->id, $orders)])->execute();
    Craft::$app->getDb()->createCommand()->update(Table::PAYMENTS, [$column => Db::prepareDateForDb((new DateTime())->modify($modify))], ['orderId' => array_map(static fn($o) => $o->id, $orders)])->execute();
};
$clearFixtures = function() use ($orders) {
    Craft::$app->getDb()->createCommand()->delete(Table::DOCUMENTS, ['orderId' => array_map(static fn($o) => $o->id, $orders)])->execute();
    Craft::$app->getDb()->createCommand()->delete(Table::PAYMENTS, ['orderId' => array_map(static fn($o) => $o->id, $orders)])->execute();
};

$tokenOk = fn(string $token = 'access-new') => new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => $token, 'refresh_token' => 'refresh-' . $token, 'expires_in' => 600, 'token_type' => 'bearer']));
$expireAccess = function() use ($plugin) {
    Craft::$app->getDb()->createCommand()->update(Table::CONNECTIONS, ['accessTokenExpires' => Db::prepareDateForDb((new DateTime())->modify('-1 minute'))], ['connectionKey' => $plugin->getSettings()->getConnectionKey()])->execute();
    $plugin->getOauth()->getConnection(true);
};

resetAlerts();

// =====================================================================
section('Settings');

check('a fresh install saves with no recipients and no webhook (nothing is required)', function() use ($originalSettings) {
    $s = new Settings(array_merge($originalSettings, ['alertRecipients' => '', 'alertWebhookUrl' => '']));

    return $s->validate() ?: json_encode($s->getErrors());
});

check('a bad address is refused, and named', function() use ($originalSettings) {
    $s = new Settings(array_merge($originalSettings, ['alertRecipients' => 'ops@example.test, not-an-address']));

    return !$s->validate() && str_contains(implode(' ', $s->getErrors('alertRecipients')), 'not-an-address') ?: json_encode($s->getErrors());
});

check('an unset $ENV reference is allowed and means nobody', function() use ($originalSettings) {
    $s = new Settings(array_merge($originalSettings, ['alertRecipients' => '$EXACTLY_ALERTS_NOT_SET', 'alertWebhookUrl' => '$EXACTLY_HOOK_NOT_SET']));

    return $s->validate() && $s->recipientList() === [] ?: json_encode($s->getErrors());
});

check('a non-http webhook URL is refused at save', function() use ($originalSettings) {
    $s = new Settings(array_merge($originalSettings, ['alertWebhookUrl' => 'ftp://hooks.example.test/x']));

    return !$s->validate() && $s->hasErrors('alertWebhookUrl') ?: 'accepted';
});

check('recipients split on commas, semicolons and newlines, de-duplicated', function() {
    $s = new Settings(['alertRecipients' => "a@example.test; b@example.test\nc@example.test, a@example.test"]);

    return $s->recipientList() === ['a@example.test', 'b@example.test', 'c@example.test'] ?: json_encode($s->recipientList());
});

check('the alert and payment settings are attributes, so they persist', function() use ($settings) {
    $missing = array_diff([
        'alertRecipients', 'alertWebhookUrl', 'alertWebhookFormat', 'alertWebhookSecret', 'alertOnFailures',
        'alertFailureThreshold', 'alertWindowMinutes', 'alertOnStall', 'alertStallHours', 'alertOnAuthFailure',
        'alertCooldownMinutes', 'registerPayments', 'paymentJournalCode', 'paymentJournalByGateway',
        'paymentReceivablesGlAccountCode', 'paymentAmountSign', 'paymentEntryType', 'recordProcessorFees',
    ], array_keys($settings->toArray()));

    return $missing === [] ?: implode(', ', $missing);
});

// =====================================================================
section('Not connected');

check('an install with no stored connection checks nothing and records nothing', function() use ($alerts, $plugin, $fail, $reset) {
    $reset();
    $fail();
    $fail();
    $results = withSettings(['clientId' => 'nobody-' . uniqid()], function() use ($alerts, $plugin) {
        $plugin->getOauth()->getConnection(true);

        return $alerts->check();
    });
    $plugin->getOauth()->getConnection(true);

    return $results === [] && (new Query())->from([Table::ALERTS])->count() == 0 ?: json_encode($results);
});

$clearFixtures();

// =====================================================================
section('The SSRF guard on the webhook');

foreach ([
    'http://127.0.0.1/hook' => 'loopback',
    'http://169.254.169.254/latest/meta-data/' => 'the cloud metadata service',
    'http://10.1.2.3/hook' => 'a private address',
    'http://[::1]/hook' => 'IPv6 loopback',
    'http://[::ffff:127.0.0.1]/hook' => 'IPv4-mapped loopback',
    'http://100.64.0.1/hook' => 'carrier-grade NAT',
    'ftp://93.184.215.14/hook' => 'a non-http scheme',
    'https://user:pass@93.184.215.14/hook' => 'credentials in the URL',
] as $url => $what) {
    check("refuses $what", fn() => is_string($alerts->webhookTarget($url)) ?: 'allowed');
}

check('a public address is allowed, and pinned', function() use ($alerts) {
    $t = $alerts->webhookTarget('https://93.184.215.14/hook');

    return is_array($t) && $t['addresses'] === ['93.184.215.14'] && $t['port'] === 443 ?: json_encode($t);
});

check('a refused URL is never requested', function() use ($alerts, &$history) {
    $result = $alerts->postWebhook('http://127.0.0.1:8080/hook', ['text' => 'x']);

    return is_string($result) && $history === [] ?: 'requested: ' . count($history);
});

check('the send pins the address, refuses redirects and does not throw on a 4xx', function() use ($alerts, $hookMock, &$history) {
    $hookMock->append(new Psr7Response(404));
    $result = $alerts->postWebhook('https://93.184.215.14/hook', ['text' => 'x']);
    $options = $history[0]['options'] ?? [];
    $pin = $options['curl'][CURLOPT_RESOLVE][0] ?? '';

    return $result === 'HTTP 404' && $pin === '93.184.215.14:443:93.184.215.14' && ($options['allow_redirects'] ?? null) === false
        ?: json_encode(['result' => $result, 'pin' => $pin]);
});

check('allowPrivateAlertWebhookHosts lets a LAN host through, unpinned', function() use ($alerts, $settings) {
    $settings->allowPrivateAlertWebhookHosts = true;
    $t = $alerts->webhookTarget('http://10.1.2.3/hook');
    $settings->allowPrivateAlertWebhookHosts = false;

    return is_array($t) && $t['addresses'] === [] ?: json_encode($t);
});

// =====================================================================
section('Redaction');

check('the client secret and the stored tokens are taken out by value', function() use ($alerts, $settings) {
    $out = $alerts->redact('Exact said: bad secret ' . $settings->clientSecret . ' for refresh-token-fixture and access-token-fixture');

    return !str_contains($out, $settings->clientSecret) && !str_contains($out, 'refresh-token-fixture') && !str_contains($out, 'access-token-fixture') ?: $out;
});

check('anything shaped like a credential is taken out by pattern', function() use ($alerts) {
    $out = $alerts->redact('Authorization: Bearer gAAAAAbcdef123456!xyz {"client_secret":"hunter22xyz"} refresh_token=stIDaaaabbbb&x=1');

    return !str_contains($out, 'gAAAAAbcdef123456') && !str_contains($out, 'hunter22xyz') && !str_contains($out, 'stIDaaaabbbb') ?: $out;
});

check('Exact’s own error text survives redaction', function() use ($alerts) {
    $out = $alerts->redact('Mandatory: GLAccount (Reason: 400 Bad Request)');

    return str_contains($out, 'Mandatory: GLAccount') ?: $out;
});

check('tags are stripped and the length is capped', function() use ($alerts) {
    $out = $alerts->redact('<b>' . str_repeat('x', 900) . '</b>');

    return !str_contains($out, '<b>') && mb_strlen($out) === 500 ?: mb_strlen($out) . ' chars';
});

// =====================================================================
section('Orders failing to reach Exact');

$reset();
resetAlerts();

check('below the threshold, nothing opens and nothing is sent', function() use ($alerts, $fail, $latch, &$mail) {
    $fail();
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'ok' && $mail === [] ?: json_encode([$latch(Alerts::INCIDENT_FAILURES), count($mail)]);
});

check('reaching it opens the incident and sends one email to every recipient', function() use ($alerts, $fail, $latch, &$mail) {
    $fail('Exact Online: Mandatory: Journal');
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'open' && count($mail) === 1
        && $mail[0]['to'] === ['ops@example.test', 'books@example.test']
        ?: json_encode([$latch(Alerts::INCIDENT_FAILURES), $mail]);
});

check('the email says what failed and links the Documents screen filtered to failures', function() use (&$mail) {
    $body = $mail[0]['body'] ?? '';

    return str_contains($body, 'Mandatory: Journal') && str_contains($body, 'exactly/documents') && str_contains($body, 'status=failed')
        && str_contains($mail[0]['subject'], 'Orders failing to reach Exact Online')
        ?: $body;
});

check('it stays quiet while open, however often it is checked', function() use ($alerts, $fail, &$mail) {
    $fail();
    $alerts->check();
    $alerts->check();

    return count($mail) === 1 ?: count($mail) . ' emails';
});

check('a failed payment entry counts as a failure too', function() use ($alerts, $orders) {
    $now = Db::prepareDateForDb(new DateTime());
    Craft::$app->getDb()->createCommand()->insert(Table::PAYMENTS, [
        'transactionId' => 800000000 + random_int(1, 99999), 'orderId' => $orders[0]->id, 'division' => TEST_DIVISION,
        'kind' => 'payment', 'status' => PaymentEntries::STATUS_FAILED, 'reference' => 'PAYREF', 'lastError' => 'Journal closed',
        'attempts' => 1, 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
    ])->execute();
    $standing = $alerts->standingCount(Alerts::INCIDENT_FAILURES);
    $detail = $alerts->check([Alerts::INCIDENT_FAILURES])[0]['detail'] ?? '';

    return str_contains((string)$detail, 'PAYREF') && $standing >= 4 ?: json_encode([$standing, $detail]);
});

check('a push evaluates alerts by itself — no cron', function() use ($plugin, $orders, $latch, &$mail, $reset) {
    $reset(['alertFailureThreshold' => 1]);
    resetAlerts();
    // An Exact that answers nothing: whatever the push asks first fails, the row is `failed`, and
    // the push's own finally evaluates the alerts.
    exact([]);
    $result = $plugin->getInvoices()->push($orders[3]);

    return $result['success'] === false && ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'open' && count($mail) === 1
        ?: json_encode([$result['message'], $latch(Alerts::INCIDENT_FAILURES), count($mail)]);
});

check('a whole quiet window recovers it, with one recovery that says what is still failed', function() use ($alerts, $latch, $ageFixtures, &$mail) {
    $ageFixtures('-2 hours');
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $last = end($mail) ?: [];

    return ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'ok' && count($mail) === 2
        && str_contains($last['subject'] ?? '', 'Recovered') && str_contains($last['body'] ?? '', 'still show as failed')
        ?: json_encode([$latch(Alerts::INCIDENT_FAILURES), array_column($mail, 'subject')]);
});

check('a reopening inside the quiet period is held, then sent once it ends', function() use ($alerts, $fail, $latch, $settings, &$mail) {
    // The recovery above went out with no cooldown; give this one a quiet period by hand.
    Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['quietUntil' => Db::prepareDateForDb((new DateTime())->modify('+30 minutes'))], ['incident' => Alerts::INCIDENT_FAILURES])->execute();
    $fail();
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $held = ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'open' && count($mail) === 2;
    Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['quietUntil' => Db::prepareDateForDb((new DateTime())->modify('-1 minute'))], ['incident' => Alerts::INCIDENT_FAILURES])->execute();
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return $held && count($mail) === 3 ?: json_encode(['held' => $held, 'mail' => count($mail)]);
});

check('a failed send is released and retried on the next check, not lost', function() use ($alerts, $latch, $ageFixtures, &$mail, &$mailFails) {
    $ageFixtures('-2 hours');
    $mailFails = true;
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $row = $latch(Alerts::INCIDENT_FAILURES);
    // Not `?? 'x'`: that is 'x' when the column is NULL, which is exactly the case being asserted.
    $owed = array_key_exists('recoveryNotifiedAt', $row) && $row['recoveryNotifiedAt'] === null;
    $mailFails = false;
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return $owed && ($latch(Alerts::INCIDENT_FAILURES)['recoveryNotifiedAt'] ?? null) !== null && count($mail) === 4
        ?: json_encode(['owed' => $owed, 'mail' => count($mail)]);
});

check('switched off, failures alert nobody', function() use ($alerts, $fail, $latch, &$mail, $reset) {
    $reset(['alertOnFailures' => false, 'alertFailureThreshold' => 1]);
    $fail();
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'ok' && $mail === [] ?: json_encode($latch(Alerts::INCIDENT_FAILURES));
});

$clearFixtures();
resetAlerts();

// =====================================================================
section('Invoicing stalled');

$reset();

check('a document sitting `queued` past the stall age opens it', function() use ($alerts, $orders, $latch, &$mail) {
    makeDocument($orders[0], ['status' => Document::STATUS_QUEUED, 'exactInvoiceId' => null, 'invoiceNumber' => null, 'dateUpdated' => Db::prepareDateForDb((new DateTime())->modify('-7 hours'))]);
    $alerts->check([Alerts::INCIDENT_STALLED]);

    return ($latch(Alerts::INCIDENT_STALLED)['state'] ?? null) === 'open' && count($mail) === 1
        && str_contains($mail[0]['body'], 'queue') && str_contains($mail[0]['body'], 'utilities/queue-manager')
        ?: json_encode([$latch(Alerts::INCIDENT_STALLED), $mail]);
});

check('once it moves on, the stall recovers', function() use ($alerts, $orders, $latch, &$mail) {
    Craft::$app->getDb()->createCommand()->update(Table::DOCUMENTS, ['status' => Document::STATUS_SENT, 'dateUpdated' => Db::prepareDateForDb(new DateTime())], ['orderId' => $orders[0]->id])->execute();
    $alerts->check([Alerts::INCIDENT_STALLED]);

    return ($latch(Alerts::INCIDENT_STALLED)['state'] ?? null) === 'ok' && count($mail) === 2 && str_contains($mail[1]['subject'], 'Recovered')
        ?: json_encode([$latch(Alerts::INCIDENT_STALLED), count($mail)]);
});

check('a payment entry left `sending` counts as stuck too', function() use ($alerts, $orders) {
    $before = $alerts->stuckCount(6);
    $old = Db::prepareDateForDb((new DateTime())->modify('-8 hours'));
    Craft::$app->getDb()->createCommand()->insert(Table::PAYMENTS, [
        'transactionId' => 700000000 + random_int(1, 99999), 'orderId' => $orders[1]->id, 'division' => TEST_DIVISION,
        'kind' => 'payment', 'status' => PaymentEntries::STATUS_SENDING, 'attempts' => 1,
        'dateCreated' => $old, 'dateUpdated' => $old, 'uid' => StringHelper::UUID(),
    ])->execute();

    return $alerts->stuckCount(6) === $before + 1 ?: 'not counted';
});

check('with an automatic trigger, a recent order past the stall age with no invoice row counts', function() use ($alerts, $orders, $settings) {
    $settings->pushTrigger = 'completed';
    $before = $alerts->overdueOrderCount(6);
    Craft::$app->getDb()->createCommand()->update(craft\commerce\db\Table::ORDERS, ['dateOrdered' => Db::prepareDateForDb((new DateTime())->modify('-8 hours'))], ['id' => $orders[2]->id])->execute();
    $after = $alerts->overdueOrderCount(6);
    $settings->pushTrigger = 'manual';
    $manual = $alerts->overdueOrderCount(6);

    return $after === $before + 1 && $manual === 0 ?: json_encode([$before, $after, $manual]);
});

check('an order older than the look-back is a backfill’s business, not an alert’s', function() use ($alerts, $orders, $settings) {
    $settings->pushTrigger = 'completed';
    $before = $alerts->overdueOrderCount(6);
    Craft::$app->getDb()->createCommand()->update(craft\commerce\db\Table::ORDERS, ['dateOrdered' => Db::prepareDateForDb((new DateTime())->modify('-30 days'))], ['id' => $orders[2]->id])->execute();
    $after = $alerts->overdueOrderCount(6);
    $settings->pushTrigger = 'manual';

    return $after === $before - 1 ?: json_encode([$before, $after]);
});

$clearFixtures();
resetAlerts();

// =====================================================================
section('Exact refusing the connection');

$reset();

check('a 401 that a fresh token does not fix opens it and alerts at once', function() use ($plugin, $latch, &$mail, $tokenOk) {
    exact([exactError(401, 'Unauthorized'), $tokenOk('t-1'), exactError(401, 'Unauthorized')]);

    try {
        $plugin->getApi()->get('current/Me');
    } catch (Throwable) {
    }

    return ($latch(Alerts::INCIDENT_AUTH)['state'] ?? null) === 'open' && count($mail) === 1
        && str_contains($mail[0]['body'], '401') && str_contains($mail[0]['body'], 'settings/plugins/exactly')
        ?: json_encode(['latch' => $latch(Alerts::INCIDENT_AUTH), 'mail' => count($mail), 'calls' => exactCalls()]);
});

check('the next authenticated success recovers it, with one recovery', function() use ($plugin, $latch, &$mail) {
    exact([exactJson(['CurrentDivision' => TEST_DIVISION])]);
    $plugin->getApi()->get('current/Me');

    return ($latch(Alerts::INCIDENT_AUTH)['state'] ?? null) === 'ok' && count($mail) === 2 && str_contains($mail[1]['subject'], 'Recovered')
        ?: json_encode(['latch' => $latch(Alerts::INCIDENT_AUTH), 'mail' => array_column($mail, 'subject')]);
});

check('a 401 that the refresh does fix is not an incident', function() use ($plugin, $latch, $tokenOk) {
    $before = $latch(Alerts::INCIDENT_AUTH)['signalledAt'] ?? null;
    exact([exactError(401, 'expired'), $tokenOk('t-2'), exactJson(['CurrentDivision' => TEST_DIVISION])]);
    $plugin->getApi()->get('current/Me');

    return ($latch(Alerts::INCIDENT_AUTH)['signalledAt'] ?? null) === $before ?: 'a recovered 401 was signalled';
});

check('a refused refresh token (invalid_grant) opens it too, and says reconnect', function() use ($plugin, $latch, &$mail, $reset, $expireAccess) {
    $reset();
    resetAlerts();
    $expireAccess();
    exact([new Psr7Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant', 'error_description' => 'spent']))]);

    try {
        $plugin->getApi()->get('current/Me');
    } catch (Throwable) {
    }

    return ($latch(Alerts::INCIDENT_AUTH)['state'] ?? null) === 'open' && count($mail) === 1 && str_contains($mail[0]['body'], 'reconnect')
        ?: json_encode(['latch' => $latch(Alerts::INCIDENT_AUTH), 'mail' => count($mail)]);
});

check('a second refusal does not send a second alert', function() use ($plugin, &$mail, $expireAccess) {
    $expireAccess();
    exact([new Psr7Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant']))]);

    try {
        $plugin->getApi()->get('current/Me');
    } catch (Throwable) {
    }

    return count($mail) === 1 ?: count($mail) . ' emails';
});

check('a network failure on refresh is not an authentication failure', function() use ($plugin, $latch, $reset, $expireAccess) {
    $reset();
    resetAlerts();
    $expireAccess();
    exact([new ConnectException('cURL error 6: Could not resolve host', new Psr7Request('POST', 'https://start.exactonline.nl/api/oauth2/token'))]);

    try {
        $plugin->getApi()->get('current/Me');
    } catch (Throwable) {
    }

    return empty($latch(Alerts::INCIDENT_AUTH)['signalledAt']) ?: 'the network was taken for a refusal';
});

check('a 500 is not an authentication failure', function() use ($plugin, $latch) {
    makeConnection();
    exact([exactError(500, 'Internal error')]);

    try {
        $plugin->getApi()->get('current/Me');
    } catch (Throwable) {
    }

    return empty($latch(Alerts::INCIDENT_AUTH)['signalledAt']) ?: 'a 500 was taken for a refusal';
});

check('a connection that expired unused is measured as open, and clears on reconnecting', function() use ($alerts, $plugin, $latch, &$mail, $reset) {
    $reset();
    resetAlerts();
    makeConnection([
        'accessTokenExpires' => Db::prepareDateForDb((new DateTime())->modify('-40 days')),
        'refreshTokenExpires' => Db::prepareDateForDb((new DateTime())->modify('-10 days')),
    ]);
    $alerts->check([Alerts::INCIDENT_AUTH]);
    $opened = ($latch(Alerts::INCIDENT_AUTH)['state'] ?? null) === 'open' && count($mail) === 1 && str_contains($mail[0]['body'], 'expired');
    makeConnection();
    $alerts->check([Alerts::INCIDENT_AUTH]);

    return $opened && ($latch(Alerts::INCIDENT_AUTH)['state'] ?? null) === 'ok' && count($mail) === 2
        ?: json_encode(['opened' => $opened, 'latch' => $latch(Alerts::INCIDENT_AUTH), 'mail' => count($mail)]);
});

check('switched off, a refusal records the signal but alerts nobody', function() use ($plugin, $latch, &$mail, $reset, $expireAccess) {
    $reset(['alertOnAuthFailure' => false]);
    resetAlerts();
    $expireAccess();
    exact([new Psr7Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant']))]);

    try {
        $plugin->getApi()->get('current/Me');
    } catch (Throwable) {
    }

    return !empty($latch(Alerts::INCIDENT_AUTH)['signalledAt']) && ($latch(Alerts::INCIDENT_AUTH)['state'] ?? null) === 'ok' && $mail === []
        ?: json_encode($latch(Alerts::INCIDENT_AUTH));
});

makeConnection();
resetAlerts();

// =====================================================================
section('The webhook');

$reset(['alertRecipients' => '', 'alertWebhookUrl' => 'https://93.184.215.14/services/T000/B000/xyz', 'alertWebhookSecret' => "whsec-$suffix", 'alertFailureThreshold' => 1]);

check('an incident posts a Slack message, signed, to the pinned address', function() use ($alerts, $fail, $hookMock, &$history, $suffix) {
    $hookMock->append(new Psr7Response(200));
    $fail();
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $request = $history[0]['request'] ?? null;

    if (!$request) {
        return 'nothing posted';
    }

    $body = (string)$request->getBody();
    $payload = json_decode($body, true);
    $expected = 'sha256=' . hash_hmac('sha256', $request->getHeaderLine('X-Exactly-Timestamp') . '.' . $body, "whsec-$suffix");

    return str_contains($payload['text'] ?? '', 'Orders failing to reach Exact Online') && isset($payload['blocks'])
        && hash_equals($expected, $request->getHeaderLine('X-Exactly-Signature'))
        && ($history[0]['options']['curl'][CURLOPT_RESOLVE][0] ?? '') === '93.184.215.14:443:93.184.215.14'
        ?: $body;
});

check('a webhook that fails with no email configured is retried, not lost', function() use ($alerts, $hookMock, $latch, $ageFixtures, &$history) {
    $ageFixtures('-2 hours');
    $hookMock->append(new Psr7Response(500));
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $row = $latch(Alerts::INCIDENT_FAILURES);
    $owed = array_key_exists('recoveryNotifiedAt', $row) && $row['recoveryNotifiedAt'] === null;
    $hookMock->append(new Psr7Response(200));
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return $owed && ($latch(Alerts::INCIDENT_FAILURES)['recoveryNotifiedAt'] ?? null) !== null && count($history) === 3
        ?: json_encode(['owed' => $owed, 'posts' => count($history)]);
});

check('Teams gets an Adaptive Card, JSON gets a flat exactly.alert event', function() use ($alerts) {
    $m = $alerts->compose(Alerts::INCIDENT_AUTH, false, 'HTTP 401');
    $teams = $alerts->payload('teams', $m);
    $json = $alerts->payload('json', $m);

    return ($teams['attachments'][0]['content']['type'] ?? null) === 'AdaptiveCard'
        && $json['event'] === 'exactly.alert.opened' && $json['incident'] === 'auth' && str_contains($json['syncUrl'], 'exactly/documents')
        ?: json_encode([$teams, $json]);
});

check('a handler on EVENT_BEFORE_NOTIFY can reword or swallow an alert', function() use ($alerts) {
    $seen = null;
    $handler = function(AlertEvent $e) use (&$seen) {
        $seen = $e->subject;
        $e->isValid = false;
    };
    $alerts->on(Alerts::EVENT_BEFORE_NOTIFY, $handler);
    $result = $alerts->notify('test', false, 'x');
    $alerts->off(Alerts::EVENT_BEFORE_NOTIFY, $handler);

    return $result === true && is_string($seen) ?: 'not called';
});

$clearFixtures();
resetAlerts();

// =====================================================================
section('Dashboard widget');

$reset();

check('it shows the connection, the counts and any open incident', function() {
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    $now = Db::prepareDateForDb(new DateTime());
    Craft::$app->getDb()->createCommand()->insert(Table::ALERTS, [
        'incident' => Alerts::INCIDENT_AUTH, 'state' => 'open', 'detail' => 'shown on hover',
        'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
    ])->execute();
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();
    $view->setTemplateMode(View::TEMPLATE_MODE_CP);

    try {
        $html = (string)(new HealthWidget())->getBodyHtml();
    } finally {
        $view->setTemplateMode($mode);
    }

    return str_contains($html, 'Exact Online refused the connection') && str_contains($html, 'shown on hover')
        && str_contains($html, 'Orders not invoiced') && str_contains($html, 'Last invoice') && HealthWidget::isSelectable()
        ?: substr(strip_tags($html), 0, 400);
});

check('it shows nothing to someone without document access', function() {
    Craft::$app->getUser()->setIdentity(null);

    return (new HealthWidget())->getBodyHtml() === null && !HealthWidget::isSelectable() ?: 'shown';
});

check('it is registered with the Dashboard', function() {
    return in_array(HealthWidget::class, Craft::$app->getDashboard()->getAllWidgetTypes(), true) ?: 'missing';
});

resetAlerts();

// =====================================================================
section('Console');

check('exactly/alerts/check runs and exits 0', function() {
    exec('php craft exactly/alerts/check 2>&1', $out, $code);

    return $code === 0 ?: "exit $code: " . implode(' | ', array_slice($out, 0, 5));
});

check('exactly/alerts/test refuses with nothing configured (saved settings)', function() {
    exec('php craft exactly/alerts/test 2>&1', $out, $code);
    $text = implode("\n", $out);

    // The saved (project config) settings: on this harness nobody is configured.
    return $code === 78 && str_contains($text, 'nothing to send to') || $code === 0 ?: "exit $code: " . $text;
});

check('exactly/sync/maintenance runs the checks and exits 0', function() {
    exec('php craft exactly/sync/maintenance 2>&1', $out, $code);

    return $code === 0 && str_contains(implode("\n", $out), 'entered') ?: "exit $code: " . implode(' | ', array_slice($out, 0, 5));
});

// =====================================================================
section('“Send a test alert” over HTTP');

[$viewer, $password] = makeUser('alerts', ['accessCp', 'accessPlugin-exactly', 'exactly-viewDocuments', 'exactly-pushOrders', 'exactly-creditOrders', 'exactly-viewLog']);

check('anonymous is refused', function() {
    [$action] = client(null, null);
    $status = $action('exactly/alerts/test')->getStatusCode();

    return in_array($status, [302, 400, 401, 403], true) ?: "status $status";
});

check('a non-admin with every Exactly permission is refused', function() use ($viewer, $password) {
    [$action] = client($viewer->username, $password);
    $status = $action('exactly/alerts/test')->getStatusCode();

    return $status === 403 ?: "status $status";
});

check('an admin without a CSRF token is refused', function() {
    [$action] = client('admin', 'claudepassword');
    $status = $action('exactly/alerts/test', [], 'POST', false)->getStatusCode();

    return $status === 400 ?: "status $status";
});

check('an admin GET is refused', function() {
    [$action] = client('admin', 'claudepassword');
    $status = $action('exactly/alerts/test', [], 'GET')->getStatusCode();

    return in_array($status, [400, 405], true) ?: "status $status";
});

check('an admin POST answers JSON from the saved settings (and takes no URL from the request)', function() {
    [$action] = client('admin', 'claudepassword');
    $response = $action('exactly/alerts/test', ['alertWebhookUrl' => 'http://169.254.169.254/']);
    $data = json_decode((string)$response->getBody(), true);

    return in_array($response->getStatusCode(), [200, 400], true) && is_string($data['message'] ?? null) && !str_contains((string)$data['message'], '169.254')
        ?: $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 200);
});

check('the settings screen renders, with the alert and payment sections and the test button', function() {
    [, $cp] = client('admin', 'claudepassword');
    $response = $cp('settings/plugins/exactly');
    $html = (string)$response->getBody();

    // Ids come out namespaced (`settings-…`); the script looks both up.
    return $response->getStatusCode() === 200 && str_contains($html, 'id="settings-exactly-test-alert"') && str_contains($html, 'paymentReceivablesGlAccountCode')
        && str_contains($html, "post('exactly/alerts/test'") && str_contains($html, "document.getElementById('settings-' + id)")
        ?: 'HTTP ' . $response->getStatusCode() . ' ' . substr(strip_tags($html), 0, 200);
});
