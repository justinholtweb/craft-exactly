<?php
/**
 * Shared bootstrap and fixtures for alerts.php, orders.php and payments.php.
 *
 * checks.php keeps its own (and its `FakeApi`); these suites instead put a Guzzle `MockHandler`
 * client into the real `services\Api` and `services\Oauth`, so the real request, retry, 401 and
 * logging code runs against a scripted Exact. Nothing here talks to a real Exact division.
 *
 * Settings are changed **in memory only** (project config is contended in the shared harness), the
 * queue is swapped for a recording fake wherever a job could be pushed (the harness's real queue
 * is drained by a shared runner, in another process, against the real Api), and every fixture —
 * orders, products, transactions, ledger and payment rows, alert latches, the connection, log
 * rows — is removed in a shutdown function, pass or fail.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use justinholtweb\exactly\db\Table;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\Plugin;

const TEST_DIVISION = 987654;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;
$suffix = substr(md5((string)microtime(true)), 0, 6);
$createdProducts = [];
$createdOrders = [];
$createdUsers = [];
$originalSettings = $plugin->getSettings()->toArray();
$logIdAtStart = (int)(new craft\db\Query())->from([Table::LOG])->max('id');
$alertRowsAtStart = (new craft\db\Query())->from([Table::ALERTS])->all();

// `craft-penny` (a sibling plugin in this shared harness) types its beforeSaveElement handler as
// `ModelEvent`, so saving any element fatals while it is enabled. Detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

// A connection key no other run (or plugin) can share: the client ID is per run.
$plugin->setSettings(array_merge($originalSettings, [
    'clientId' => 'exactly-test-' . $suffix,
    'clientSecret' => 'secret-' . $suffix . '-abcdef',
    'division' => TEST_DIVISION,
    'loggingEnabled' => true,
    // Off unless a check turns it on, so nothing a sibling suite does is entered by accident.
    'registerPayments' => false,
    'alertRecipients' => '',
    'alertWebhookUrl' => '',
]));

/**
 * Settings in memory, restored even when the check throws.
 */
function withSettings(array $overrides, callable $fn): mixed
{
    global $plugin;

    $original = $plugin->getSettings()->toArray();

    try {
        $plugin->setSettings(array_merge($original, $overrides));

        return $fn();
    } finally {
        $plugin->setSettings($original);
    }
}

/**
 * A queue that records what is pushed to it and runs nothing.
 */
class FakeQueue extends yii\queue\Queue
{
    /** @var array<int, array{job: mixed, delay: int}> */
    public array $pushed = [];

    protected function pushMessage($message, $ttr, $delay, $priority)
    {
        $this->pushed[] = ['job' => $this->serializer->unserialize($message), 'delay' => (int)$delay];

        return (string)count($this->pushed);
    }

    public function status($id)
    {
        return self::STATUS_WAITING;
    }

    /** Jobs of one class, so a sibling plugin's jobs never count. */
    public function jobsOf(string $class): array
    {
        return array_values(array_filter($this->pushed, static fn(array $p) => $p['job'] instanceof $class));
    }
}

function withFakeQueue(callable $fn): mixed
{
    $original = Craft::$app->getQueue();
    $fake = new FakeQueue();
    Craft::$app->set('queue', $fake);

    try {
        return $fn($fake);
    } finally {
        Craft::$app->set('queue', $original);
    }
}

/**
 * The scripted Exact. Every response a check expects is queued on a Guzzle `MockHandler`; every
 * request that went out is in `$exactHistory`. A queue that runs dry throws, which is the point: a
 * call nobody scripted is a call the code should not have made.
 */
$exactHistory = [];
$exactMock = new MockHandler();

function exact(array $responses): MockHandler
{
    global $exactHistory, $exactMock, $plugin;

    $exactHistory = [];
    $exactMock = new MockHandler($responses);
    $stack = HandlerStack::create($exactMock);
    $stack->push(Middleware::history($exactHistory));
    $client = new Client(['handler' => $stack]);

    $plugin->getApi()->client = $client;
    $plugin->getOauth()->client = $client;

    return $exactMock;
}

/** A JSON response in Exact's `d` envelope. */
function exactJson(array $body, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode(['d' => $body]));
}

/** A collection response (`d.results`). */
function exactList(array $rows): Response
{
    return exactJson(['results' => $rows]);
}

function exactError(int $status, string $message = 'Refused'): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode(['error' => ['message' => ['value' => $message]]]));
}

/**
 * @return array<int, array{method: string, path: string, query: string, body: mixed}>
 */
function exactCalls(): array
{
    global $exactHistory;

    return array_map(static fn(array $t) => [
        'method' => $t['request']->getMethod(),
        'path' => $t['request']->getUri()->getPath(),
        'query' => urldecode($t['request']->getUri()->getQuery()),
        'body' => json_decode((string)$t['request']->getBody(), true),
    ], $exactHistory);
}

/**
 * A stored, usable connection for this run's connection key.
 */
function makeConnection(array $overrides = []): void
{
    global $plugin;

    $settings = $plugin->getSettings();
    $security = Craft::$app->getSecurity();
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->delete(Table::CONNECTIONS, ['connectionKey' => $settings->getConnectionKey()])->execute();
    Craft::$app->getDb()->createCommand()->insert(Table::CONNECTIONS, array_merge([
        'connectionKey' => $settings->getConnectionKey(),
        'baseUrl' => $settings->getBaseUrl(),
        'accessToken' => base64_encode($security->encryptByKey('access-token-fixture')),
        'refreshToken' => base64_encode($security->encryptByKey('refresh-token-fixture')),
        'accessTokenExpires' => Db::prepareDateForDb((new DateTime())->modify('+9 minutes')),
        'refreshTokenExpires' => Db::prepareDateForDb((new DateTime())->modify('+30 days')),
        'division' => TEST_DIVISION,
        'divisionName' => 'Fixture BV',
        'userEmail' => 'fixture@example.com',
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ], $overrides))->execute();

    $plugin->getOauth()->getConnection(true);
}

function makeProduct(string $sku, float $price): Product
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Exactly fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->isDefault = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product;
}

/**
 * A completed order for one variant, no tax: the payment code reads the transaction, not the lines.
 */
function makeOrder(Variant $variant, int $qty = 1, bool $complete = true): Order
{
    global $createdOrders, $storeId, $suffix;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setEmail("exactly-$suffix@example.com");

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order: ' . json_encode($order->getErrors()));
    }

    $createdOrders[] = $order;

    $order->setLineItems([
        Commerce::getInstance()->getLineItems()->createLineItem($order, $variant->id, [], $qty),
    ]);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order lines: ' . json_encode($order->getErrors()));
    }

    if ($complete) {
        $order->markAsComplete();
    }

    return $order;
}

/**
 * A ledger row as `Documents::markSent()` would leave it — written straight to the table, because
 * the invoice push itself is checks.php's business.
 */
function makeDocument(Order $order, array $values = []): ?Document
{
    global $plugin;

    static $number = 30000;
    $number++;
    $now = Db::prepareDateForDb(new DateTime());

    Craft::$app->getDb()->createCommand()->insert(Table::DOCUMENTS, array_merge([
        'orderId' => $order->id,
        'division' => TEST_DIVISION,
        'kind' => Document::KIND_INVOICE,
        'status' => Document::STATUS_SENT,
        'orderNumber' => $order->reference ?: $order->getShortNumber(),
        'exactInvoiceId' => sprintf('11111111-2222-3333-4444-%012d', $number),
        'exactAccountId' => 'cccccccc-0000-0000-0000-000000000001',
        'invoiceNumber' => (string)$number,
        'exactStatus' => Document::EXACT_STATUS_PROCESSED,
        'currency' => strtoupper((string)$order->currency) ?: 'EUR',
        'amount' => (float)$order->getTotalPrice(),
        'attempts' => 1,
        'dateSent' => $now,
        'dateLastAttempt' => $now,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ], $values))->execute();

    return $plugin->getDocuments()->getDocument((int)$order->id, $values['division'] ?? TEST_DIVISION, $values['kind'] ?? Document::KIND_INVOICE);
}

/**
 * A saved Commerce transaction on the Dummy gateway. Saving fires Commerce's after-save event, so
 * callers that must not queue anything wrap it in withFakeQueue().
 */
function makeTransaction(Order $order, string $type, float $amount, string $status = TransactionRecord::STATUS_SUCCESS, mixed $response = null): Transaction
{
    $gateway = Commerce::getInstance()->getGateways()->getGatewayByHandle('dummy');

    $transaction = new Transaction();
    $transaction->orderId = $order->id;
    $transaction->setOrder($order);
    $transaction->gatewayId = $gateway?->id;
    $transaction->type = $type;
    $transaction->status = $status;
    $transaction->amount = $amount;
    $transaction->paymentAmount = $amount;
    $transaction->currency = $order->currency;
    $transaction->paymentCurrency = $order->currency;
    $transaction->paymentRate = 1;
    $transaction->reference = 'dummy-' . substr(md5(uniqid('', true)), 0, 8);
    $transaction->response = $response;

    if (!Commerce::getInstance()->getTransactions()->saveTransaction($transaction)) {
        throw new RuntimeException('Could not save transaction: ' . json_encode($transaction->getErrors()));
    }

    // Read back, so dateCreated is what Commerce stored.
    return Commerce::getInstance()->getTransactions()->getTransactionById((int)$transaction->id) ?? $transaction;
}

/**
 * Prime the GL account cache, so `Ledger::resolveAccountId()` answers without a call.
 */
function primeLedger(array $map = ['1300' => 'aa001300-0000-0000-0000-000000000000', '4900' => 'aa004900-0000-0000-0000-000000000000']): void
{
    Craft::$app->getCache()->set('exactly.glAccounts.' . TEST_DIVISION, $map, 3600);
}

function resetAlerts(): void
{
    Craft::$app->getDb()->createCommand()->delete(Table::ALERTS)->execute();
}

// ---------------------------------------------------------------------------------------------
// HTTP against the harness's own web server, as a real signed-in user. The web process has its own
// settings (project config), not this script's in-memory ones — checks over HTTP only assert on
// what does not depend on them: permissions, request methods, CSRF, rendering.

function makeUser(string $handle, array $permissions): array
{
    global $createdUsers, $suffix;

    $password = 'Exactly-' . bin2hex(random_bytes(6));
    $user = new User(['username' => "exactly-$handle-$suffix", 'email' => "exactly-$handle-$suffix@example.com", 'newPassword' => $password]);
    Craft::$app->getElements()->saveElement($user, false) or throw new RuntimeException('Could not save user');
    Craft::$app->getUsers()->activateUser($user);
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
    $createdUsers[] = $user;

    return [$user, $password];
}

/**
 * A signed-in client (or a guest, with a null username). Returns
 * `fn(action, params, method, withCsrf)` for action requests and `->cp(path)` via the second
 * element of the pair.
 *
 * @return array{0: Closure, 1: Closure}
 */
function client(?string $username, ?string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 60]);
    $accept = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=admin/actions/users/session-info', ['headers' => $accept])->getBody(), true)['csrfTokenValue'] ?? '');

    if ($username !== null) {
        $http->post('index.php?p=admin/actions/users/login', ['headers' => $accept, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode() === 200
            or throw new RuntimeException("Could not sign in as $username");
    }

    $action = static function(string $action, array $params = [], string $method = 'POST', bool $withCsrf = true) use ($http, $accept, $csrf) {
        $options = ['headers' => $accept];

        if ($method === 'POST') {
            $options['form_params'] = $params + ($withCsrf ? ['CRAFT_CSRF_TOKEN' => $csrf()] : []);
        } elseif ($params !== []) {
            $options['query'] = $params;
        }

        if ($method !== 'POST') {
            // Guzzle's `query` option replaces the URI's query string, `p` included.
            $options['query'] = ['p' => "admin/actions/$action"] + ($options['query'] ?? []);

            return $http->request($method, 'index.php', $options);
        }

        return $http->request($method, "index.php?p=admin/actions/$action", $options);
    };

    $cp = static fn(string $path, array $query = []) => $http->get('index.php', ['query' => ['p' => 'admin/' . $path] + $query]);

    return [$action, $cp];
}

register_shutdown_function(static function() {
    global $createdOrders, $createdProducts, $createdUsers, $plugin, $originalSettings, $logIdAtStart, $alertRowsAtStart, $passed, $failed;

    echo "\nCleanup\n";

    $db = Craft::$app->getDb();
    $elements = Craft::$app->getElements();

    foreach ($createdOrders as $fixture) {
        try {
            $db->createCommand()->delete(Table::PAYMENTS, ['orderId' => $fixture->id])->execute();
            $db->createCommand()->delete(Table::DOCUMENTS, ['orderId' => $fixture->id])->execute();
            $db->createCommand()->delete(craft\commerce\db\Table::TRANSACTIONS, ['orderId' => $fixture->id])->execute();
            $elements->deleteElement($fixture, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$fixture->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdUsers as $fixture) {
        try {
            $elements->deleteElement($fixture, true);
        } catch (Throwable $e) {
            echo "  ! could not delete user {$fixture->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdProducts as $fixture) {
        try {
            $elements->deleteElement($fixture, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$fixture->id}: {$e->getMessage()}\n";
        }
    }

    try {
        $db->createCommand()->delete(Table::CONNECTIONS, ['connectionKey' => $plugin->getSettings()->getConnectionKey()])->execute();
        $db->createCommand()->delete(Table::LOG, ['>', 'id', $logIdAtStart])->execute();
        $db->createCommand()->delete(Table::ACCOUNTS, ['division' => TEST_DIVISION])->execute();

        // The latch table is put back as it was: a real incident open before this run stays open.
        $db->createCommand()->delete(Table::ALERTS)->execute();

        foreach ($alertRowsAtStart as $row) {
            $db->createCommand()->insert(Table::ALERTS, $row)->execute();
        }
    } catch (Throwable $e) {
        echo "  ! could not clear fixtures: {$e->getMessage()}\n";
    }

    $plugin->setSettings($originalSettings);

    echo "  ✓ fixtures removed, settings restored\n";
    echo "\n" . str_repeat('-', 60) . "\n";
    echo "  $passed passed, $failed failed\n";
    echo str_repeat('-', 60) . "\n";

    exit($failed > 0 ? 1 : 0);
});
