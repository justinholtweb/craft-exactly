<?php
/**
 * The Orders index: the "Exact Online" column, the "Exact Online status" condition rule and the
 * "Send to Exact Online" bulk action.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-exactly/tests/integration/orders.php
 */

require __DIR__ . '/_support.php';

use craft\commerce\elements\conditions\orders\OrderCondition;
use craft\commerce\elements\Order;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\exactly\db\Table;
use justinholtweb\exactly\elements\actions\SendToExact;
use justinholtweb\exactly\elements\conditions\ExactStatusConditionRule;
use justinholtweb\exactly\jobs\PushOrder;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\services\Documents;
use justinholtweb\exactly\services\PaymentEntries;

makeConnection();
$documents = $plugin->getDocuments();
$product = makeProduct("EXO-$suffix", 50.00);
$variant = $product->getDefaultVariant();

// One fixture order per state, plus the ones that reach `failed` by another road.
$fixtures = withFakeQueue(function() use ($variant) {
    $f = [];
    $f[Document::ORDER_NONE] = makeOrder($variant);
    $f[Document::ORDER_INVOICED] = makeOrder($variant);
    makeDocument($f[Document::ORDER_INVOICED]);
    $f[Document::ORDER_PAID] = makeOrder($variant);
    makeDocument($f[Document::ORDER_PAID], ['paymentStatus' => 'paid']);
    $f[Document::ORDER_CREDITED] = makeOrder($variant);
    makeDocument($f[Document::ORDER_CREDITED], ['paymentStatus' => 'credited']);
    makeDocument($f[Document::ORDER_CREDITED], ['kind' => Document::KIND_CREDIT_NOTE]);
    $f[Document::ORDER_FAILED] = makeOrder($variant);
    makeDocument($f[Document::ORDER_FAILED], ['status' => Document::STATUS_FAILED, 'exactInvoiceId' => null, 'invoiceNumber' => null, 'lastError' => 'Refused']);
    $f[Document::ORDER_IN_FLIGHT] = makeOrder($variant);
    makeDocument($f[Document::ORDER_IN_FLIGHT], ['status' => Document::STATUS_QUEUED, 'exactInvoiceId' => null, 'invoiceNumber' => null]);
    $f[Document::ORDER_PENDING] = makeOrder($variant);
    makeDocument($f[Document::ORDER_PENDING], ['status' => Document::STATUS_PENDING, 'exactInvoiceId' => null, 'invoiceNumber' => null]);
    $f[Document::ORDER_SKIPPED] = makeOrder($variant);
    makeDocument($f[Document::ORDER_SKIPPED], ['status' => Document::STATUS_SKIPPED, 'exactInvoiceId' => null, 'invoiceNumber' => null]);

    // Invoiced fine, but a payment entry failed: still `failed`, the word that needs a person.
    $f['failedPayment'] = makeOrder($variant);
    makeDocument($f['failedPayment']);
    $now = Db::prepareDateForDb(new DateTime());
    Craft::$app->getDb()->createCommand()->insert(Table::PAYMENTS, [
        'transactionId' => 900000000 + random_int(1, 99999),
        'orderId' => $f['failedPayment']->id,
        'division' => TEST_DIVISION,
        'kind' => 'payment',
        'status' => PaymentEntries::STATUS_FAILED,
        'attempts' => 1,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();

    // A failed credit note beats the sent invoice.
    $f['failedCredit'] = makeOrder($variant);
    makeDocument($f['failedCredit']);
    makeDocument($f['failedCredit'], ['kind' => Document::KIND_CREDIT_NOTE, 'status' => Document::STATUS_FAILED, 'exactInvoiceId' => null, 'invoiceNumber' => null]);

    // An invoice in another administration is not this one's.
    $f['otherDivision'] = makeOrder($variant);
    makeDocument($f['otherDivision'], ['division' => TEST_DIVISION + 1]);

    return $f;
});

$expected = [
    Document::ORDER_NONE => Document::ORDER_NONE,
    Document::ORDER_INVOICED => Document::ORDER_INVOICED,
    Document::ORDER_PAID => Document::ORDER_PAID,
    Document::ORDER_CREDITED => Document::ORDER_CREDITED,
    Document::ORDER_FAILED => Document::ORDER_FAILED,
    Document::ORDER_IN_FLIGHT => Document::ORDER_IN_FLIGHT,
    Document::ORDER_PENDING => Document::ORDER_PENDING,
    Document::ORDER_SKIPPED => Document::ORDER_SKIPPED,
    'failedPayment' => Document::ORDER_FAILED,
    'failedCredit' => Document::ORDER_FAILED,
    'otherDivision' => Document::ORDER_NONE,
];
$ids = array_map(static fn(Order $o) => (int)$o->id, $fixtures);

// =====================================================================
section('Order status sets');

check('every fixture gets the status its rows say, in precedence order', function() use ($documents, $fixtures, $expected) {
    $statuses = $documents->orderStatuses(array_map(static fn(Order $o) => $o->id, $fixtures));
    $wrong = [];

    foreach ($fixtures as $name => $order) {
        if (($statuses[$order->id] ?? null) !== $expected[$name]) {
            $wrong[] = "$name: " . ($statuses[$order->id] ?? 'missing');
        }
    }

    return $wrong === [] ?: implode(', ', $wrong);
});

check('the SQL sets partition the fixtures exactly as the PHP does', function() use ($documents, $fixtures, $expected, $ids) {
    $wrong = [];
    $seen = [];

    foreach (array_keys(Documents::orderStatusOptions()) as $status) {
        $got = array_map('intval', Order::find()->id(array_values($ids))->status(null)->andWhere($documents->orderStatusCondition($status))->ids());
        sort($got);
        $want = [];

        foreach ($fixtures as $name => $order) {
            if ($expected[$name] === $status) {
                $want[] = (int)$order->id;
            }
        }

        sort($want);

        if ($got !== $want) {
            $wrong[] = "$status: got " . json_encode($got) . ' want ' . json_encode($want);
        }

        array_push($seen, ...$got);
    }

    // A partition: every order in exactly one set.
    sort($seen);
    $all = array_values($ids);
    sort($all);

    return $wrong === [] && $seen === $all ?: implode('; ', $wrong) . ' seen ' . json_encode($seen);
});

check('an unknown status matches nothing rather than everything', function() use ($documents, $ids) {
    return Order::find()->id(array_values($ids))->status(null)->andWhere($documents->orderStatusCondition('bogus'))->ids() === [];
});

check('the summary carries the invoice number once there is one', function() use ($documents, $fixtures) {
    $summary = $documents->orderSummaries([$fixtures[Document::ORDER_INVOICED]->id])[$fixtures[Document::ORDER_INVOICED]->id];

    return $summary['number'] !== null && ctype_digit($summary['number']) ?: json_encode($summary);
});

// =====================================================================
section('“Exact Online status” condition rule');

$makeRule = static function(array $values, string $operator = 'in'): ExactStatusConditionRule {
    /** @var ExactStatusConditionRule $rule */
    $rule = Craft::$app->getConditions()->createConditionRule([
        'class' => ExactStatusConditionRule::class,
        'operator' => $operator,
        'values' => $values,
    ]);

    return $rule;
};

check('the rule is offered on order conditions', function() {
    $condition = Craft::$app->getConditions()->createCondition(OrderCondition::class);
    $classes = array_map(static fn($rule) => get_class($rule), $condition->getSelectableConditionRules());

    return in_array(ExactStatusConditionRule::class, $classes, true) ?: json_encode(array_values($classes));
});

check('“is one of Not invoiced” is the not-yet-invoiced source', function() use ($makeRule, $ids, $fixtures) {
    $query = Order::find()->id(array_values($ids))->status(null);
    $makeRule([Document::ORDER_NONE])->modifyQuery($query);
    $got = array_map('intval', $query->ids());
    sort($got);
    $want = [(int)$fixtures[Document::ORDER_NONE]->id, (int)$fixtures['otherDivision']->id];
    sort($want);

    return $got === $want ?: json_encode($got);
});

check('several values are OR-ed', function() use ($makeRule, $ids, $fixtures) {
    $query = Order::find()->id(array_values($ids))->status(null);
    $makeRule([Document::ORDER_PAID, Document::ORDER_CREDITED])->modifyQuery($query);
    $got = array_map('intval', $query->ids());
    sort($got);
    $want = [(int)$fixtures[Document::ORDER_PAID]->id, (int)$fixtures[Document::ORDER_CREDITED]->id];
    sort($want);

    return $got === $want ?: json_encode($got);
});

check('“is not one of” (ni) keeps everything else', function() use ($makeRule, $ids, $fixtures) {
    $query = Order::find()->id(array_values($ids))->status(null);
    $makeRule([Document::ORDER_FAILED], 'ni')->modifyQuery($query);
    $got = array_map('intval', $query->ids());

    return count($got) === count($ids) - 3 && !in_array((int)$fixtures['failedPayment']->id, $got, true) && in_array((int)$fixtures[Document::ORDER_NONE]->id, $got, true)
        ?: json_encode($got);
});

check('matchElement agrees with the query for every fixture', function() use ($makeRule, $fixtures, $expected) {
    $rule = $makeRule([Document::ORDER_IN_FLIGHT, Document::ORDER_FAILED]);
    $wrong = [];

    foreach ($fixtures as $name => $order) {
        $want = in_array($expected[$name], [Document::ORDER_IN_FLIGHT, Document::ORDER_FAILED], true);

        if ($rule->matchElement($order) !== $want) {
            $wrong[] = $name;
        }
    }

    return $wrong === [] ?: implode(', ', $wrong);
});

check('unknown values are dropped on the way in, and the config round-trips', function() use ($makeRule) {
    $rule = $makeRule([Document::ORDER_FAILED, 'drop table', 'bogus']);
    $again = Craft::$app->getConditions()->createConditionRule($rule->getConfig());

    return $rule->getValues() === [Document::ORDER_FAILED] && $again->getValues() === [Document::ORDER_FAILED] && $rule->validate(['values'])
        ?: json_encode($rule->getConfig());
});

check('an empty rule leaves the query alone', function() use ($makeRule, $ids) {
    $query = Order::find()->id(array_values($ids))->status(null);
    $makeRule([])->modifyQuery($query);

    return count($query->ids()) === count($ids) ?: 'narrowed';
});

// =====================================================================
section('Orders index column');

check('“Exact Online” is an available column on orders only', function() {
    $orders = Craft::$app->getElementSources()->getAvailableTableAttributes(Order::class);
    $entries = Craft::$app->getElementSources()->getAvailableTableAttributes(craft\elements\Entry::class);

    return isset($orders['exactlyStatus']) && !isset($entries['exactlyStatus']) ?: 'not registered as expected';
});

check('the cell shows status and invoice number to a document viewer, nothing to anyone else', function() use ($plugin, $fixtures, $documents) {
    $documents->resetOrderSummaries();
    $order = $fixtures[Document::ORDER_PAID];
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    $admin = $plugin->orderStatusHtml($order);
    $none = $plugin->orderStatusHtml($fixtures[Document::ORDER_NONE]);
    Craft::$app->getUser()->setIdentity(null);
    $anonymous = $plugin->orderStatusHtml($order);
    $number = $documents->orderSummary((int)$order->id)['number'];

    return str_contains($admin, 'Paid in Exact') && str_contains($admin, 'status green') && str_contains($admin, (string)$number)
        && str_contains($none, 'Not invoiced') && $anonymous === ''
        ?: json_encode([$admin, $none, $anonymous]);
});

check('one prefetch answers a whole page: every row comes from the memo', function() use ($documents, $ids) {
    $documents->resetOrderSummaries();
    $documents->prefetchOrderSummaries(array_values($ids));
    $memo = (new ReflectionProperty($documents, '_orderSummaries'))->getValue($documents);

    return count(array_intersect_key($memo, array_flip(array_values($ids)))) === count($ids) ?: count($memo) . ' memoised';
});

check('over HTTP, the Orders index renders the column', function() use ($ids) {
    [$action] = client('admin', 'claudepassword');
    $response = $action('element-indexes/get-elements', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false, 'tableColumns' => ['exactlyStatus']],
        'criteria' => ['id' => array_values($ids), 'status' => null, 'isCompleted' => null],
    ]);
    $html = json_decode((string)$response->getBody(), true)['html'] ?? '';

    // The web process reads the saved settings, which have no connection for this run's fixture
    // division — so every row is "Not invoiced" there. What this proves is that the column
    // renders through Commerce's own index without breaking it.
    return $response->getStatusCode() === 200 && str_contains($html, 'Not invoiced')
        ?: $response->getStatusCode() . ': ' . substr(strip_tags((string)$response->getBody()), 0, 300);
});

// =====================================================================
section('“Send to Exact Online” element action');

check('it queues every selected completed order not yet invoiced, and skips invoiced orders and carts', function() use ($fixtures, $variant) {
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());

    try {
        return withFakeQueue(function(FakeQueue $queue) use ($fixtures, $variant) {
            $cart = makeOrder($variant, 1, false);
            $selected = [$fixtures[Document::ORDER_NONE]->id, $fixtures[Document::ORDER_FAILED]->id, $fixtures[Document::ORDER_INVOICED]->id, $cart->id];
            $action = new SendToExact();
            $ok = $action->performAction(Order::find()->id($selected)->status(null));
            $jobs = $queue->jobsOf(PushOrder::class);
            $orderIds = array_map(static fn($p) => $p['job']->orderId, $jobs);
            sort($orderIds);
            $want = [(int)$fixtures[Document::ORDER_NONE]->id, (int)$fixtures[Document::ORDER_FAILED]->id];
            sort($want);

            return $ok && $orderIds === $want && str_contains((string)$action->getMessage(), '2 skipped')
                ?: json_encode(['ok' => $ok, 'orders' => $orderIds, 'message' => $action->getMessage()]);
        });
    } finally {
        Craft::$app->getUser()->setIdentity(null);
    }
});

check('the failed order it queued is now `queued`, so a second run pushes no second job', function() use ($fixtures) {
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());

    try {
        return withFakeQueue(function(FakeQueue $queue) use ($fixtures) {
            (new SendToExact())->performAction(Order::find()->id($fixtures[Document::ORDER_FAILED]->id)->status(null));

            return $queue->jobsOf(PushOrder::class) === [] ?: count($queue->jobsOf(PushOrder::class)) . ' jobs';
        });
    } finally {
        Craft::$app->getUser()->setIdentity(null);
    }
});

check('it queues even with useQueue off — never a hundred pushes inline', function() use ($fixtures) {
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    exact([]);

    try {
        return withSettings(['useQueue' => false], fn() => withFakeQueue(function(FakeQueue $queue) use ($fixtures) {
            (new SendToExact())->performAction(Order::find()->id($fixtures[Document::ORDER_NONE]->id)->status(null));

            return count($queue->jobsOf(PushOrder::class)) === 1 && exactCalls() === [] ?: 'pushed inline';
        }));
    } finally {
        Craft::$app->getUser()->setIdentity(null);
    }
});

check('it refuses someone without “Send orders to Exact Online”, even if they reach it', function() use ($fixtures) {
    Craft::$app->getUser()->setIdentity(null);

    return withFakeQueue(function(FakeQueue $queue) use ($fixtures) {
        $action = new SendToExact();

        return $action->performAction(Order::find()->id($fixtures[Document::ORDER_NONE]->id)->status(null)) === false && $queue->pushed === [] ?: 'ran';
    });
});

check('it refuses when Exactly is not connected', function() use ($fixtures, $plugin) {
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());

    try {
        return withSettings(['clientId' => 'nobody-' . uniqid()], function() use ($fixtures, $plugin) {
            $plugin->getOauth()->getConnection(true);

            return withFakeQueue(function(FakeQueue $queue) use ($fixtures) {
                $action = new SendToExact();
                $ok = $action->performAction(Order::find()->id($fixtures[Document::ORDER_NONE]->id)->status(null));

                return $ok === false && $queue->pushed === [] && str_contains((string)$action->getMessage(), 'not connected') ?: (string)$action->getMessage();
            });
        });
    } finally {
        $plugin->getOauth()->getConnection(true);
        Craft::$app->getUser()->setIdentity(null);
    }
});

[$viewer, $viewerPassword] = makeUser('orders', ['accessCp', 'accessPlugin-commerce', 'commerce-manageOrders', 'commerce-editOrders', 'exactly-viewDocuments']);

check('over HTTP, a user without “Send orders to Exact Online” cannot run it', function() use ($viewer, $viewerPassword, $fixtures) {
    [$action] = client($viewer->username, $viewerPassword);
    $response = $action('element-indexes/perform-action', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false],
        'elementAction' => SendToExact::class,
        'elementIds' => [$fixtures[Document::ORDER_NONE]->id],
    ]);
    $data = json_decode((string)$response->getBody(), true);

    return $response->getStatusCode() >= 400 && empty($data['success']) ?: $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 300);
});

check('over HTTP, an admin reaches the action (and the saved config is not connected, so it says so)', function() use ($fixtures) {
    [$action] = client('admin', 'claudepassword');
    $response = $action('element-indexes/perform-action', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false],
        'elementAction' => SendToExact::class,
        'elementIds' => [$fixtures[Document::ORDER_NONE]->id],
    ]);
    $body = (string)$response->getBody();

    return str_contains($body, 'not connected') || str_contains($body, 'queued for Exact Online') ?: $response->getStatusCode() . ' ' . substr($body, 0, 300);
});
