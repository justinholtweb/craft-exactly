<?php
/**
 * Exactly integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-exactly/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: fixture products, orders, ledger rows, cached lookups, log rows
 * and the plugin settings it overwrites are all restored in a `finally`, pass or fail.
 *
 * ## Why there is a fake API rather than mocked services
 *
 * Everything below the HTTP layer is the real code. `FakeApi` replaces only `services\Api`, so the
 * account resolver, the item resolver, the VAT determination, the payload builder, the ledger and
 * the reconciliation arithmetic all run exactly as they do in production — against an Exact Online
 * that answers from a fixture table. Stubbing `Invoices` instead would test nothing that matters.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use justinholtweb\exactly\db\Table;
use justinholtweb\exactly\errors\ApiException;
use justinholtweb\exactly\helpers\Odata;
use justinholtweb\exactly\helpers\Vat as VatHelper;
use justinholtweb\exactly\models\Connection;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\models\LogEntry;
use justinholtweb\exactly\Plugin;
use justinholtweb\exactly\services\Api;

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
$originalSettings = $plugin->getSettings()->toArray();
$originalEdition = Craft::$app->getPlugins()->getPluginInfo(Plugin::HANDLE)['edition'] ?? Plugin::EDITION_LITE;

const TEST_DIVISION = 987654;

/**
 * A fake Exact Online.
 *
 * Records everything posted to it so the checks can assert on the exact payload that would have
 * gone over the wire, and answers reads from small fixture tables.
 */
class FakeApi extends Api
{
    /** @var array<int, array{method: string, endpoint: string, body: array|null}> */
    public array $calls = [];

    /** Endpoints that should throw, keyed by endpoint substring. */
    public array $failures = [];

    public array $accounts = [];
    public array $items = [];
    public array $vatCodes = [];
    public array $glAccounts = [];
    public array $receivables = [];
    public int $nextInvoiceNumber = 20001;
    public array $printResult = [];

    public function get(string $endpoint, array $params = [], array $context = []): mixed
    {
        $this->calls[] = ['method' => 'GET', 'endpoint' => $endpoint, 'body' => null];
        $this->maybeFail($endpoint);

        if ($endpoint === 'current/Me') {
            return [
                'UserID' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                'FullName' => 'Fixture User',
                'Email' => 'fixture@example.com',
                'CurrentDivision' => TEST_DIVISION,
                'DivisionCustomerName' => 'Fixture BV',
            ];
        }

        return null;
    }

    public function getAll(string $endpoint, array $params = [], int $maxPages = 20, array $context = []): array
    {
        $this->calls[] = ['method' => 'GET*', 'endpoint' => $endpoint, 'body' => null];
        $this->maybeFail($endpoint);

        return match ($endpoint) {
            'vat/VATCodes' => $this->vatCodes,
            'financial/GLAccounts' => $this->glAccounts,
            'read/financial/ReceivablesList' => $this->receivables,
            'hrm/Divisions' => [[
                'Code' => TEST_DIVISION,
                'Description' => 'Fixture BV',
                'Currency' => 'EUR',
                'Country' => 'NL',
                'Main' => true,
                'Status' => 0,
            ]],
            default => [],
        };
    }

    public function findOne(string $endpoint, string $filter, array $select = [], array $context = []): ?array
    {
        $this->calls[] = ['method' => 'FIND', 'endpoint' => $endpoint . '?' . $filter, 'body' => null];
        $this->maybeFail($endpoint);

        $needle = null;

        if (preg_match("~'((?:[^']|'')*)'~", $filter, $match)) {
            $needle = str_replace("''", "'", $match[1]);
        }

        if ($needle === null) {
            return null;
        }

        if ($endpoint === 'crm/Accounts') {
            foreach ($this->accounts as $account) {
                if (
                    strcasecmp((string)($account['Email'] ?? ''), $needle) === 0
                    || strcasecmp((string)($account['VATNumber'] ?? ''), $needle) === 0
                    || (string)($account['Code'] ?? '') === $needle
                ) {
                    return $account;
                }
            }

            return null;
        }

        if ($endpoint === 'logistics/Items') {
            // The real caller pads the code to Exact's stored width; matching on the trimmed form
            // here is what proves the padding is applied rather than merely present.
            return $this->items[trim($needle)] ?? null;
        }

        return null;
    }

    public function post(string $endpoint, array $body, array $context = []): mixed
    {
        $this->calls[] = ['method' => 'POST', 'endpoint' => $endpoint, 'body' => $body];
        $this->maybeFail($endpoint);

        if ($endpoint === 'salesinvoice/SalesInvoices') {
            $exVat = 0.0;

            foreach ($body['SalesInvoiceLines'] ?? [] as $line) {
                $exVat += (float)($line['AmountFC'] ?? 0);
            }

            return array_merge($body, [
                'InvoiceID' => '11111111-2222-3333-4444-' . str_pad((string)$this->nextInvoiceNumber, 12, '0', STR_PAD_LEFT),
                'EntryID' => '99999999-2222-3333-4444-555555555555',
                'InvoiceNumber' => $this->nextInvoiceNumber++,
                'EntryNumber' => 5001,
                'AmountFCExclVat' => round($exVat, 2),
                'AmountFC' => round($exVat, 2),
                'InvoiceDate' => $body['InvoiceDate'] ?? null,
            ]);
        }

        if ($endpoint === 'salesinvoice/PrintedSalesInvoices') {
            return $this->printResult;
        }

        if ($endpoint === 'crm/Accounts') {
            $created = array_merge($body, [
                'ID' => 'cc' . substr(md5(serialize($body)), 0, 6) . '-0000-0000-0000-000000000000',
                'Code' => str_pad('9001', 18, ' ', STR_PAD_LEFT),
            ]);
            $this->accounts[] = $created;

            return $created;
        }

        if ($endpoint === 'logistics/Items') {
            $created = array_merge($body, [
                'ID' => 'bb' . substr(md5(serialize($body)), 0, 6) . '-0000-0000-0000-000000000000',
            ]);
            $this->items[trim((string)($body['Code'] ?? ''))] = $created;

            return $created;
        }

        return null;
    }

    public function put(string $endpoint, array $body, array $context = []): mixed
    {
        $this->calls[] = ['method' => 'PUT', 'endpoint' => $endpoint, 'body' => $body];
        $this->maybeFail($endpoint);

        return null;
    }

    public function reset(): void
    {
        $this->calls = [];
        $this->failures = [];
    }

    /** The body of the last POST to an endpoint, or null. */
    public function lastPost(string $endpoint): ?array
    {
        for ($i = count($this->calls) - 1; $i >= 0; $i--) {
            if ($this->calls[$i]['method'] === 'POST' && $this->calls[$i]['endpoint'] === $endpoint) {
                return $this->calls[$i]['body'];
            }
        }

        return null;
    }

    public function countCalls(string $endpointSubstring): int
    {
        return count(array_filter(
            $this->calls,
            static fn(array $call) => str_contains($call['endpoint'], $endpointSubstring),
        ));
    }

    private function maybeFail(string $endpoint): void
    {
        foreach ($this->failures as $needle => $exception) {
            if (str_contains($endpoint, (string)$needle)) {
                throw $exception;
            }
        }
    }
}

/**
 * Settings and editions are changed **in memory only**.
 *
 * Both live in project config, and project config in this shared harness is contended — the queue
 * runner and a dozen sibling plugins write it while a long console script runs, which produces
 * `StaleResourceException` halfway through a suite that has nothing to do with any of them.
 * Nothing here is testing that Craft can persist a setting, so nothing here persists one.
 */
function switchEdition(string $edition): void
{
    global $plugin;

    $plugin->edition = $edition;
}

function applySettings(array $values): void
{
    global $plugin;

    $plugin->setSettings($values);
}

/**
 * Run something with settings overridden, and put them back even if it throws.
 *
 * Without the `finally` a single failing check leaves the plugin on the wrong edition or the wrong
 * VAT map, and everything after it fails for a reason that has nothing to do with what it tests.
 */
function withSettings(array $overrides, callable $fn): mixed
{
    global $plugin;

    $original = $plugin->getSettings()->toArray();

    try {
        applySettings(array_merge($original, $overrides));

        return $fn();
    } finally {
        applySettings($original);
    }
}

function withEdition(string $edition, callable $fn): mixed
{
    global $plugin;

    $original = $plugin->edition;

    try {
        switchEdition($edition);

        return $fn();
    } finally {
        switchEdition($original);
    }
}

// `craft-penny` (a sibling plugin in this shared harness) registers an
// Elements::EVENT_BEFORE_SAVE_ELEMENT handler typed `ModelEvent` while Craft passes an
// `ElementEvent`, so saving *any* element fatals while it is enabled. Nothing to do with Exactly;
// detached in-process here (never persisted) so fixtures can be created.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
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
 * @param array<int, array{variant: Variant, qty: int}> $lines
 * @param array<int, OrderAdjustment> $adjustments
 */
function makeOrder(array $lines, array $address = [], array $adjustments = [], bool $complete = true, float $taxRate = 0.21): Order
{
    global $createdOrders, $storeId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    $order->setEmail($address['email'] ?? 'exactly-fixture@example.com');

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order: ' . json_encode($order->getErrors()));
    }

    $createdOrders[] = $order;

    $lineItems = [];

    foreach ($lines as $line) {
        $lineItems[] = Commerce::getInstance()->getLineItems()->createLineItem(
            $order,
            $line['variant']->id,
            [],
            $line['qty'],
        );
    }

    $order->setLineItems($lineItems);

    // Commerce refuses an address element it does not own, so the attributes go in as an array
    // and Commerce builds the owned element itself.
    $addressAttributes = array_merge([
        'fullName' => 'Dana Fixture',
        'organization' => 'Fixture Holdings',
        'addressLine1' => 'Keizersgracht 1',
        'locality' => 'Amsterdam',
        'postalCode' => '1015 CJ',
        'countryCode' => 'NL',
    ], array_diff_key($address, ['email' => true]));

    $order->setShippingAddress($addressAttributes);
    $order->setBillingAddress($addressAttributes);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save order lines: ' . json_encode($order->getErrors()));
    }

    if ($complete) {
        $order->markAsComplete();
    }

    if (!$adjustments && $taxRate > 0) {
        // A tax-exclusive store: VAT added on top, as Commerce's tax adjuster would. Leaving it
        // off would make Exact's total 21% higher than what Commerce charged, and the payload
        // builder would (rightly) refuse to send it.
        $adjustments = [
            adjustment('tax', 'BTW', round($order->getItemSubtotal() * $taxRate, 2), false, null, [
                'id' => 4242,
                'name' => 'BTW hoog',
                'rate' => $taxRate,
            ]),
        ];
    }

    if ($adjustments) {
        $order = makeOrderAdjustments($order, $adjustments);
    }

    return $order;
}

/**
 * Attach adjustments to an order that has already completed.
 *
 * Recalculation has to be off, or Commerce throws these away and re-derives them from tax rules
 * and shipping methods this harness does not have.
 *
 * @param array<int, OrderAdjustment> $adjustments
 */
function makeOrderAdjustments(Order $order, array $adjustments): Order
{
    $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

    foreach ($adjustments as $adjustment) {
        $adjustment->setOrder($order);
    }

    $order->setAdjustments($adjustments);

    if (!Craft::$app->getElements()->saveElement($order, false)) {
        throw new RuntimeException('Could not save adjustments: ' . json_encode($order->getErrors()));
    }

    return $order;
}

function adjustment(string $type, string $name, float $amount, bool $included = false, ?int $lineItemId = null, array $snapshot = []): OrderAdjustment
{
    $adjustment = new OrderAdjustment();
    $adjustment->type = $type;
    $adjustment->name = $name;
    $adjustment->description = $name;
    $adjustment->amount = $amount;
    $adjustment->included = $included;
    $adjustment->lineItemId = $lineItemId;
    $adjustment->sourceSnapshot = $snapshot;

    return $adjustment;
}

$fakeApi = new FakeApi();
$fakeApi->vatCodes = [
    ['Code' => '21', 'Description' => 'BTW hoog', 'Percentage' => 0.21, 'IsBlocked' => false],
    ['Code' => '9', 'Description' => 'BTW laag', 'Percentage' => 0.09, 'IsBlocked' => false],
    ['Code' => '0', 'Description' => 'Geen BTW', 'Percentage' => 0.0, 'IsBlocked' => false],
    ['Code' => 'IC', 'Description' => 'Intracommunautair', 'Percentage' => 0.0, 'IsBlocked' => false],
    ['Code' => 'EX', 'Description' => 'Export', 'Percentage' => 0.0, 'IsBlocked' => false],
];
$fakeApi->glAccounts = [
    ['ID' => 'aa000001-0000-0000-0000-000000000000', 'Code' => '8000', 'Description' => 'Omzet', 'Type' => 110],
    ['ID' => 'aa000002-0000-0000-0000-000000000000', 'Code' => '8100', 'Description' => 'Vrachtkosten', 'Type' => 110],
    ['ID' => 'aa000003-0000-0000-0000-000000000000', 'Code' => '8900', 'Description' => 'Kortingen', 'Type' => 110],
    ['ID' => 'aa000004-0000-0000-0000-000000000000', 'Code' => '9990', 'Description' => 'Afrondingsverschillen', 'Type' => 110],
];

// Swap the real HTTP layer out. `Plugin` is a Yii module, so `set()` replaces the component for
// the life of this process and every other service picks it up through `getApi()`.
$plugin->set('api', $fakeApi);

try {
    // =====================================================================
    section('OData helper');

    check('a collection is unwrapped out of the d/results envelope', function() {
        return Odata::unwrap(['d' => ['results' => [['A' => 1], ['A' => 2]]]]) === [['A' => 1], ['A' => 2]];
    });

    check('a single entity is unwrapped out of the d envelope', function() {
        return Odata::unwrap(['d' => ['ID' => 'x']]) === ['ID' => 'x'];
    });

    check('an unenveloped body is passed through untouched', function() {
        return Odata::unwrap(['ID' => 'x']) === ['ID' => 'x'];
    });

    check('the __next page URL is found', function() {
        return Odata::nextUrl(['d' => ['results' => [], '__next' => 'https://x/next']]) === 'https://x/next';
    });

    check('no __next means null, not an empty string', function() {
        return Odata::nextUrl(['d' => ['results' => []]]) === null;
    });

    check('a /Date(ms)/ value parses to the right instant', function() {
        $date = Odata::parseDate('/Date(1755648000000)/');

        return $date?->format('Y-m-d H:i:s') === '2025-08-20 00:00:00'
            ?: 'got ' . var_export($date?->format('c'), true);
    });

    check('a /Date(ms+offset)/ value is not shifted twice', function() {
        $plain = Odata::parseDate('/Date(1755648000000)/');
        $offset = Odata::parseDate('/Date(1755648000000+0120)/');

        return $plain?->getTimestamp() === $offset?->getTimestamp();
    });

    check('an ISO string still parses', function() {
        return Odata::parseDate('2026-08-20T09:30:00')?->format('H:i') === '09:30';
    });

    check('garbage parses to null rather than throwing', fn() => Odata::parseDate('not a date') === null);

    check('an outgoing date carries no timezone suffix', function() {
        $formatted = Odata::formatDay(new DateTime('2026-08-20 15:00:00', new DateTimeZone('UTC')));

        return $formatted === '2026-08-20T00:00:00' ?: "got $formatted";
    });

    check('a filter string doubles its single quotes', function() {
        return Odata::quote("O'Brien BV") === "'O''Brien BV'" ?: 'got ' . Odata::quote("O'Brien BV");
    });

    check('a GUID filter carries the guid prefix', function() {
        return Odata::guid('11111111-2222-3333-4444-555555555555') === "guid'11111111-2222-3333-4444-555555555555'";
    });

    check('a real GUID is recognised', fn() => Odata::isGuid('11111111-2222-3333-4444-555555555555'));
    check('a layout name is not a GUID', fn() => Odata::isGuid('Standard invoice') === false);
    check('a null is not a GUID', fn() => Odata::isGuid(null) === false);

    check('a code is padded to Exact’s stored width for filtering', function() {
        $padded = Odata::padCode('1024');

        return $padded === '              1024' && strlen($padded) === 18 ?: "got '" . $padded . "' (" . strlen($padded) . ')';
    });

    check('padding an empty code stays empty rather than becoming 18 spaces', function() {
        return Odata::padCode('   ') === '';
    });

    check('a padded code trims back to what a human typed', function() {
        return Odata::trimCode('              1024') === '1024';
    });

    check('an Exact error body yields its message value', function() {
        $body = json_encode(['error' => ['message' => ['value' => 'Journal is required']]]);

        return Odata::errorMessage($body) === 'Journal is required' ?: 'got ' . var_export(Odata::errorMessage($body), true);
    });

    check('a non-JSON error body is reported rather than swallowed', function() {
        return str_contains((string)Odata::errorMessage('<html><body>502 Bad Gateway</body></html>'), '502');
    });

    check('an empty error body is null', fn() => Odata::errorMessage('') === null);

    // =====================================================================
    section('EU VAT determination');

    check('the member state list is the 27', fn() => count(VatHelper::MEMBER_STATES) === 27);

    check('Greece files under EL, not GR', fn() => VatHelper::prefixForCountry('GR') === 'EL');

    check('Northern Ireland keeps the XI prefix', fn() => VatHelper::prefixForCountry('GB') === 'XI');

    check('a human-typed VAT number normalises', function() {
        return VatHelper::normalize('NL 8025.13.146.B.01') === 'NL802513146B01'
            ?: 'got ' . VatHelper::normalize('NL 8025.13.146.B.01');
    });

    check('a valid Dutch number is well formed', fn() => VatHelper::isWellFormed('NL802513146B01'));
    check('a Dutch number missing its B block is not', fn() => VatHelper::isWellFormed('NL802513146') === false);
    check('a valid Belgian number is well formed', fn() => VatHelper::isWellFormed('BE0123456789'));
    check('a valid German number is well formed', fn() => VatHelper::isWellFormed('DE123456789'));
    check('a valid French number is well formed', fn() => VatHelper::isWellFormed('FRAA123456789'));
    check('a Greek number validates under its EL prefix', fn() => VatHelper::isWellFormed('EL123456789'));

    check('a prefix-less number validates against the address country', function() {
        return VatHelper::isWellFormed('802513146B01', 'NL');
    });

    check('a German number on a French address is rejected', function() {
        // Not a formatting problem — either a typo or a different legal entity, and either way it
        // must not zero-rate the sale.
        return VatHelper::isWellFormed('DE123456789', 'FR') === false;
    });

    check('an empty number is not well formed', fn() => VatHelper::isWellFormed('') === false);

    check('canonicalising adds the country prefix', function() {
        return VatHelper::canonical('802513146B01', 'NL') === 'NL802513146B01';
    });

    check('same country is a domestic sale', function() {
        return VatHelper::treatment('NL', 'NL', 'NL802513146B01') === VatHelper::TREATMENT_DOMESTIC;
    });

    check('an EU business with a valid number is reverse charge', function() {
        return VatHelper::treatment('NL', 'BE', 'BE0123456789') === VatHelper::TREATMENT_REVERSE_CHARGE;
    });

    check('an EU consumer with no number is OSS', function() {
        return VatHelper::treatment('NL', 'BE', '') === VatHelper::TREATMENT_OSS;
    });

    check('an EU customer with a malformed number is OSS, not reverse charge', function() {
        // Fails closed: charging VAT is recoverable, not charging it is the merchant’s liability.
        return VatHelper::treatment('NL', 'DE', 'DE12') === VatHelper::TREATMENT_OSS;
    });

    check('a malformed number passes when validation is switched off', function() {
        return VatHelper::treatment('NL', 'DE', 'DE12', false) === VatHelper::TREATMENT_REVERSE_CHARGE;
    });

    check('outside the EU is an export', function() {
        return VatHelper::treatment('NL', 'US', '') === VatHelper::TREATMENT_EXPORT;
    });

    check('the UK is an export after Brexit', function() {
        return VatHelper::treatment('NL', 'GB', '') === VatHelper::TREATMENT_EXPORT;
    });

    check('no destination country falls back to domestic, which charges VAT', function() {
        return VatHelper::treatment('NL', '', '') === VatHelper::TREATMENT_DOMESTIC;
    });

    // =====================================================================
    section('Settings');

    switchEdition(Plugin::EDITION_PRO);

    applySettings(array_merge($originalSettings, [
        'region' => 'nl',
        'customBaseUrl' => '',
        'clientId' => 'fixture-client',
        'clientSecret' => 'fixture-secret',
        'division' => TEST_DIVISION,
        'sellerCountry' => 'NL',
        'journalCode' => '70',
        'fallbackItemCode' => 'WEBSHOP',
        'defaultGlAccountCode' => '8000',
        'shippingItemCode' => 'SHIPPING',
        'shippingGlAccountCode' => '8100',
        'discountItemCode' => 'DISCOUNT',
        'discountGlAccountCode' => '8900',
        'roundingItemCode' => 'ROUNDING',
        'roundingGlAccountCode' => '9990',
        'roundingVatCode' => '0',
        'vatCodeByTreatment' => [
            'domestic' => '21',
            'reverse-charge' => 'IC',
            'oss' => '21',
            'export' => 'EX',
        ],
        'vatCodeByTaxRate' => [],
        'itemStrategy' => 'sku',
        'createMissingItems' => false,
        'createMissingAccounts' => true,
        'includeShippingLine' => true,
        'includeDiscountLine' => true,
        'roundingTolerance' => 0.02,
        'pushTrigger' => 'manual',
        'useQueue' => false,
        'deliveryMode' => 'none',
        'paymentWriteback' => true,
        'viesValidation' => false,
        'loggingEnabled' => true,
        'logPayloads' => true,
    ]));

    $settings = $plugin->getSettings();

    check('the region picks the right Exact host', function() use ($settings) {
        return $settings->getBaseUrl() === 'https://start.exactonline.nl' ?: 'got ' . $settings->getBaseUrl();
    });

    check('a custom base URL overrides the region', function() {
        $probe = new justinholtweb\exactly\models\Settings(['region' => 'nl', 'customBaseUrl' => 'https://start.exactonline.be/']);

        return $probe->getBaseUrl() === 'https://start.exactonline.be' ?: 'got ' . $probe->getBaseUrl();
    });

    check('a custom base URL that is not a URL is rejected', function() {
        $probe = new justinholtweb\exactly\models\Settings(['customBaseUrl' => 'start.exactonline.be']);
        $probe->validate();

        return $probe->hasErrors('customBaseUrl');
    });

    check('nothing in the settings model is required', function() {
        // A `required` rule would make a fresh install unable to save *any* setting until the
        // Exact app exists — while the redirect URI needed to create that app is on this screen.
        $probe = new justinholtweb\exactly\models\Settings();

        return $probe->validate() ?: 'errors: ' . json_encode($probe->getErrors());
    });

    check('the redirect URI is generated, not typed by hand', function() use ($settings) {
        return str_contains($settings->getRedirectUri(), 'exactly/oauth/callback');
    });

    check('the redirect URI is a plain path, with no query string for a provider to choke on', function() use ($settings) {
        // Craft's action URLs are `?p=admin/actions/…` unless `omitScriptNameInUrls` is on, and a
        // provider that will not take a query string leaves the merchant with a redirect_uri
        // mismatch and nothing to debug. Exactly registers a site route instead.
        return $settings->redirectUriIsClean() ?: 'got ' . $settings->getRedirectUri();
    });

    check('the connection key changes when the client ID does', function() {
        $a = new justinholtweb\exactly\models\Settings(['clientId' => 'one', 'region' => 'nl']);
        $b = new justinholtweb\exactly\models\Settings(['clientId' => 'two', 'region' => 'nl']);

        return $a->getConnectionKey() !== $b->getConnectionKey();
    });

    check('the connection key changes when the region does', function() {
        $a = new justinholtweb\exactly\models\Settings(['clientId' => 'one', 'region' => 'nl']);
        $b = new justinholtweb\exactly\models\Settings(['clientId' => 'one', 'region' => 'be']);

        return $a->getConnectionKey() !== $b->getConnectionKey();
    });

    check('a layout name is rejected where a layout ID belongs', function() {
        $probe = new justinholtweb\exactly\models\Settings(['documentLayoutId' => 'Standard layout']);
        $probe->validate();

        return $probe->hasErrors('documentLayoutId');
    });

    check('a mapped treatment is not reported as missing', function() use ($settings) {
        return !in_array('domestic', $settings->getUnmappedTreatments(), true);
    });

    check('an unmapped treatment is reported', function() {
        $probe = new justinholtweb\exactly\models\Settings(['vatCodeByTreatment' => ['domestic' => '21']]);

        return in_array('export', $probe->getUnmappedTreatments(), true);
    });

    // =====================================================================
    section('Schema');

    foreach ([Table::CONNECTIONS, Table::DOCUMENTS, Table::ACCOUNTS, Table::ITEMS, Table::LOG] as $table) {
        check("$table exists", function() use ($table) {
            return Craft::$app->getDb()->tableExists($table);
        });
    }

    check('documents are unique on (orderId, division, kind)', function() {
        $raw = Craft::$app->getDb()->getSchema()->getRawTableName(Table::DOCUMENTS);
        $indexes = Craft::$app->getDb()->getSchema()->getTableSchema($raw, true)->getColumnNames();

        // The index itself is asserted behaviourally further down; here just confirm the columns
        // it is built on are present.
        return in_array('orderId', $indexes, true)
            && in_array('division', $indexes, true)
            && in_array('kind', $indexes, true);
    });

    // =====================================================================
    section('Log');

    check('a client secret is redacted out of a form body', function() {
        $redacted = justinholtweb\exactly\services\Log::redact('grant_type=refresh_token&client_secret=hunter2&x=1');

        return !str_contains($redacted, 'hunter2') && str_contains($redacted, 'grant_type=refresh_token')
            ?: "got $redacted";
    });

    check('a refresh token is redacted out of a JSON body', function() {
        $redacted = justinholtweb\exactly\services\Log::redact('{"access_token":"abc","refresh_token":"def"}');

        return !str_contains($redacted, 'abc') && !str_contains($redacted, 'def') ?: "got $redacted";
    });

    check('a Bearer header value is redacted wherever it appears', function() {
        $redacted = justinholtweb\exactly\services\Log::redact('Authorization: Bearer eyJhbGciOi.J9.abc-_123');

        return !str_contains($redacted, 'eyJhbGciOi') ?: "got $redacted";
    });

    check('redacting null stays null', fn() => justinholtweb\exactly\services\Log::redact(null) === null);

    check('an entry can be written and read back', function() use ($plugin) {
        $plugin->getLog()->write('checks.probe', [
            'summary' => 'probe',
            'method' => 'POST',
            'endpoint' => 'salesinvoice/SalesInvoices',
            'statusCode' => 201,
            'request' => '{"client_secret":"hunter2"}',
        ]);

        $entries = $plugin->getLog()->getEntries(['action' => 'checks.probe'], 5);

        if (!$entries) {
            return 'nothing was written';
        }

        $entry = $plugin->getLog()->getEntryById($entries[0]->id);

        return $entry !== null
            && $entry->statusCode === 201
            && !str_contains((string)$entry->request, 'hunter2')
            ?: 'stored request was ' . var_export($entry?->request, true);
    });

    check('Lite caps log retention at a week however it is configured', function() use ($plugin) {
        return withEdition(Plugin::EDITION_LITE, function() use ($plugin) {
            $lite = $plugin->getSettings()->getEffectiveLogRetentionDays();

            return $lite === 7 ?: "got $lite";
        });
    });

    // =====================================================================
    section('The connection model');

    check('an access token with 10 seconds left is not considered usable', function() {
        // A push that starts with seconds on the clock fails halfway through — after the account
        // lookup and before the invoice. The 30-second margin is the whole point.
        $connection = new Connection([
            'accessToken' => 'x',
            'accessTokenExpires' => (new DateTime())->setTimestamp(time() + 10),
        ]);

        return $connection->accessTokenIsUsable() === false;
    });

    check('an access token with five minutes left is usable', function() {
        $connection = new Connection([
            'accessToken' => 'x',
            'accessTokenExpires' => (new DateTime())->setTimestamp(time() + 300),
        ]);

        return $connection->accessTokenIsUsable();
    });

    check('a connection with no access token but a live refresh token is still usable', function() {
        $connection = new Connection([
            'refreshToken' => 'r',
            'refreshTokenExpires' => (new DateTime())->modify('+10 days'),
        ]);

        return $connection->isUsable() && $connection->canRefresh();
    });

    check('an expired refresh token cannot be refreshed', function() {
        $connection = new Connection([
            'refreshToken' => 'r',
            'refreshTokenExpires' => (new DateTime())->modify('-1 day'),
        ]);

        return $connection->canRefresh() === false && $connection->isUsable() === false;
    });

    check('the minutely reset header is read as milliseconds, not seconds', function() {
        $connection = new Connection([
            'minutelyRemaining' => 0,
            'minutelyReset' => (time() + 30) * 1000,
        ]);

        $wait = $connection->getSecondsUntilRateLimitReset();

        // Treating it as seconds would park the queue until 1970 and return 0 here.
        return $wait >= 29 && $wait <= 32 ?: "got $wait";
    });

    check('remaining budget means no wait at all', function() {
        $connection = new Connection(['minutelyRemaining' => 12, 'minutelyReset' => (time() + 30) * 1000]);

        return $connection->getSecondsUntilRateLimitReset() === 0;
    });

    check('a stored connection decrypts on the way back out', function() use ($plugin) {
        $settings = $plugin->getSettings();
        $security = Craft::$app->getSecurity();
        $now = new DateTime();

        Craft::$app->getDb()->createCommand()->delete(Table::CONNECTIONS, ['connectionKey' => $settings->getConnectionKey()])->execute();
        Craft::$app->getDb()->createCommand()->insert(Table::CONNECTIONS, [
            'connectionKey' => $settings->getConnectionKey(),
            'baseUrl' => $settings->getBaseUrl(),
            'accessToken' => base64_encode($security->encryptByKey('access-fixture')),
            'refreshToken' => base64_encode($security->encryptByKey('refresh-fixture')),
            'accessTokenExpires' => Db::prepareDateForDb((new DateTime())->modify('+9 minutes')),
            'refreshTokenExpires' => Db::prepareDateForDb((new DateTime())->modify('+30 days')),
            'division' => TEST_DIVISION,
            'divisionName' => 'Fixture BV',
            'userEmail' => 'fixture@example.com',
            'dateCreated' => Db::prepareDateForDb($now),
            'dateUpdated' => Db::prepareDateForDb($now),
            'uid' => StringHelper::UUID(),
        ])->execute();

        $connection = $plugin->getOauth()->getConnection(true);

        return $connection?->accessToken === 'access-fixture'
            && $connection->refreshToken === 'refresh-fixture'
            && $connection->accessTokenIsUsable()
            ?: 'got ' . var_export($connection?->accessToken, true);
    });

    check('tokens are never stored in the clear', function() use ($plugin) {
        $row = (new craft\db\Query())
            ->from([Table::CONNECTIONS])
            ->where(['connectionKey' => $plugin->getSettings()->getConnectionKey()])
            ->one();

        return $row && !str_contains((string)$row['accessToken'], 'access-fixture');
    });

    check('a token encrypted under a different key reads as “needs reconnecting”, not a fatal', function() use ($plugin) {
        Craft::$app->getDb()->createCommand()->update(Table::CONNECTIONS, [
            'accessToken' => base64_encode('not actually encrypted'),
            'refreshToken' => base64_encode('nor this'),
        ], ['connectionKey' => $plugin->getSettings()->getConnectionKey()])->execute();

        $connection = $plugin->getOauth()->getConnection(true);
        $usable = $connection !== null && $connection->isUsable();

        return $connection !== null && $usable === false ?: 'connection reported usable';
    });

    check('rate limits are recorded without touching the tokens', function() use ($plugin) {
        // Restore a real token first, then prove the partial update leaves it alone — a rate-limit
        // write racing a refresh must not be able to clobber the rotated token.
        $security = Craft::$app->getSecurity();
        Craft::$app->getDb()->createCommand()->update(Table::CONNECTIONS, [
            'accessToken' => base64_encode($security->encryptByKey('access-fixture')),
            'refreshToken' => base64_encode($security->encryptByKey('refresh-fixture')),
        ], ['connectionKey' => $plugin->getSettings()->getConnectionKey()])->execute();

        $plugin->getOauth()->getConnection(true);
        $plugin->getOauth()->recordRateLimits([
            'minutelyLimit' => 60,
            'minutelyRemaining' => 41,
            'minutelyReset' => (time() + 20) * 1000,
            'dailyLimit' => 5000,
            'dailyRemaining' => 4800,
        ]);

        $connection = $plugin->getOauth()->getConnection(true);

        return $connection?->minutelyRemaining === 41
            && $connection->dailyRemaining === 4800
            && $connection->accessToken === 'access-fixture'
            ?: 'got ' . var_export([$connection?->minutelyRemaining, $connection?->accessToken], true);
    });

    check('the division comes from the settings when one is chosen', function() use ($plugin) {
        return $plugin->getOauth()->getDivision() === TEST_DIVISION;
    });

    // =====================================================================
    section('Fixtures');

    $productA = makeProduct("EX-A-$suffix", 100.00);
    $productB = makeProduct("EX-B-$suffix", 50.00);
    $variantA = $productA->getDefaultVariant();
    $variantB = $productB->getDefaultVariant();

    $fakeApi->items = [
        "EX-A-$suffix" => ['ID' => 'bb000001-0000-0000-0000-000000000000', 'Code' => str_pad("EX-A-$suffix", 18, ' ', STR_PAD_LEFT), 'Description' => 'Fixture A'],
        'WEBSHOP' => ['ID' => 'bb000002-0000-0000-0000-000000000000', 'Code' => str_pad('WEBSHOP', 18, ' ', STR_PAD_LEFT), 'Description' => 'Webshop sale'],
        'SHIPPING' => ['ID' => 'bb000003-0000-0000-0000-000000000000', 'Code' => str_pad('SHIPPING', 18, ' ', STR_PAD_LEFT), 'Description' => 'Shipping'],
        'DISCOUNT' => ['ID' => 'bb000004-0000-0000-0000-000000000000', 'Code' => str_pad('DISCOUNT', 18, ' ', STR_PAD_LEFT), 'Description' => 'Discount'],
        'ROUNDING' => ['ID' => 'bb000005-0000-0000-0000-000000000000', 'Code' => str_pad('ROUNDING', 18, ' ', STR_PAD_LEFT), 'Description' => 'Rounding'],
    ];
    $fakeApi->accounts = [
        [
            'ID' => 'cc000001-0000-0000-0000-000000000000',
            'Code' => str_pad('1001', 18, ' ', STR_PAD_LEFT),
            'Name' => 'Fixture Holdings',
            'Email' => 'exactly-fixture@example.com',
            'VATNumber' => 'NL802513146B01',
            'Country' => 'NL',
        ],
    ];

    check('fixture products saved', fn() => $variantA?->id !== null && $variantB?->id !== null);

    // 250 of goods with 21% VAT *added* on top, as a store with tax-exclusive pricing charges it.
    // Without the tax adjustment Commerce's total is 250 while Exact would invoice 302.50, and
    // the reconciliation check would (correctly) refuse to send it.
    $domesticOrder = makeOrder(
        [
            ['variant' => $variantA, 'qty' => 2],
            ['variant' => $variantB, 'qty' => 1],
        ],
        [],
        [adjustment('tax', 'BTW 21%', 52.50, false, null, ['id' => 4242, 'name' => 'BTW hoog', 'rate' => 0.21])],
    );

    check('the fixture order completed with two lines', function() use ($domesticOrder) {
        return $domesticOrder->isCompleted && count($domesticOrder->getLineItems()) === 2
            ?: 'lines: ' . count($domesticOrder->getLineItems());
    });

    // =====================================================================
    section('Account resolution');

    $fakeApi->reset();

    check('an existing Exact account is matched by email', function() use ($plugin, $domesticOrder) {
        $account = $plugin->getAccounts()->resolveForOrder($domesticOrder);

        return $account['id'] === 'cc000001-0000-0000-0000-000000000000' && $account['created'] === false
            ?: 'got ' . json_encode($account);
    });

    check('the match is cached, so a second order costs no call', function() use ($plugin, $domesticOrder, $fakeApi) {
        $before = $fakeApi->countCalls('crm/Accounts');
        $plugin->getAccounts()->resolveForOrder($domesticOrder);
        $after = $fakeApi->countCalls('crm/Accounts');

        return $before === $after ?: "calls went from $before to $after";
    });

    check('an unknown customer gets an account created', function() use ($plugin, $variantA, $fakeApi, $suffix) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], [
            'email' => "brand-new-$suffix@example.com",
            'organization' => 'Brand New BV',
        ]);

        $account = $plugin->getAccounts()->resolveForOrder($order);

        $posted = $fakeApi->lastPost('crm/Accounts');

        return $account['created'] === true
            && ($posted['Name'] ?? null) === 'Brand New BV'
            && ($posted['Status'] ?? null) === 'C'
            && ($posted['IsSales'] ?? null) === true
            ?: 'posted ' . json_encode($posted);
    });

    check('a guest with no organisation still gets a usable account name', function() use ($plugin, $variantA, $fakeApi, $suffix) {
        // `Name` is Exact’s one mandatory field, so an empty one is a 400 that says nothing useful.
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], [
            'email' => "guest-$suffix@example.com",
            'organization' => '',
            'fullName' => '',
            'firstName' => '',
            'lastName' => '',
        ]);

        $plugin->getAccounts()->resolveForOrder($order);
        $posted = $fakeApi->lastPost('crm/Accounts');

        return !empty($posted['Name']) ?: 'posted ' . json_encode($posted);
    });

    check('creating accounts can be switched off, and then the push refuses rather than inventing one', function() use ($plugin, $variantA, $suffix) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], ['email' => "nobody-$suffix@example.com"]);

        return withSettings(['createMissingAccounts' => false], function() use ($plugin, $order) {
            try {
                $plugin->getAccounts()->resolveForOrder($order);

                return 'no exception was thrown';
            } catch (ApiException $e) {
                return str_contains($e->getMessage(), 'creating accounts is switched off') ?: 'got: ' . $e->getMessage();
            }
        });
    });

    // =====================================================================
    section('Item resolution');

    check('a SKU that exists in Exact resolves to its item', function() use ($plugin, $domesticOrder, $suffix) {
        $line = $domesticOrder->getLineItems()[0];
        $item = $plugin->getItems()->resolveForLineItem($line);

        return $item['id'] === 'bb000001-0000-0000-0000-000000000000' && $item['fallback'] === false
            ?: 'got ' . json_encode($item);
    });

    check('the code is padded to Exact’s stored width before it is filtered on', function() use ($fakeApi) {
        // Exact stores codes space-padded to 18 characters and its own docs are blunt that a
        // `$filter` has to match that. An unpadded filter matches nothing and reports no error,
        // which reads exactly like "this SKU is not in Exact" — and ends in a duplicate item.
        foreach ($fakeApi->calls as $call) {
            if (!str_contains($call['endpoint'], 'logistics/Items')) {
                continue;
            }

            if (preg_match("~Code eq '( +[^']+)'~", $call['endpoint'], $match)) {
                return strlen($match[1]) === 18 ?: 'filtered on ' . strlen($match[1]) . ' characters';
            }
        }

        return 'no padded item filter was seen';
    });

    check('an unmatched SKU falls back to the generic item', function() use ($plugin, $domesticOrder) {
        $line = $domesticOrder->getLineItems()[1];
        $item = $plugin->getItems()->resolveForLineItem($line);

        return $item['fallback'] === true && $item['id'] === 'bb000002-0000-0000-0000-000000000000'
            ?: 'got ' . json_encode($item);
    });

    check('with no fallback configured, an unmatched SKU fails loudly', function() use ($plugin, $domesticOrder) {
        return withSettings(['fallbackItemCode' => ''], function() use ($plugin, $domesticOrder) {
            $plugin->getItems()->clearCache();

            try {
                $plugin->getItems()->resolveForLineItem($domesticOrder->getLineItems()[1]);

                return 'no exception was thrown';
            } catch (ApiException $e) {
                return str_contains($e->getMessage(), 'no fallback item is configured') ?: 'got: ' . $e->getMessage();
            }
        });
    });

    check('a GL account code resolves to its Exact GUID', function() use ($plugin) {
        return $plugin->getLedger()->resolveAccountId('8000') === 'aa000001-0000-0000-0000-000000000000';
    });

    check('a GUID pasted where a code belongs is accepted as-is', function() use ($plugin) {
        return $plugin->getLedger()->resolveAccountId('aa000001-0000-0000-0000-000000000000') === 'aa000001-0000-0000-0000-000000000000';
    });

    check('an unknown ledger code resolves to null rather than a wrong account', function() use ($plugin) {
        return $plugin->getLedger()->resolveAccountId('1234') === null;
    });

    // =====================================================================
    section('Invoice payload');

    $fakeApi->reset();
    $built = $plugin->getInvoices()->buildPayload($domesticOrder);
    $payload = $built['payload'];

    check('the journal, type and account are set', function() use ($payload) {
        return ($payload['Journal'] ?? null) === '70'
            && ($payload['Type'] ?? null) === 8020
            && ($payload['OrderedBy'] ?? null) === 'cc000001-0000-0000-0000-000000000000'
            ?: 'got ' . json_encode(array_intersect_key($payload, array_flip(['Journal', 'Type', 'OrderedBy'])));
    });

    check('the invoice date has no timezone suffix', function() use ($payload) {
        // Sending `Z` or an offset is accepted and then shifted, which is how an invoice dated the
        // 1st lands in the previous VAT period.
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}T00:00:00$/', (string)($payload['InvoiceDate'] ?? ''))
            ?: 'got ' . var_export($payload['InvoiceDate'] ?? null, true);
    });

    check('the Craft order reference travels as YourRef', function() use ($payload, $domesticOrder) {
        return !empty($payload['YourRef']);
    });

    check('there is one invoice line per order line, plus nothing spurious', function() use ($payload) {
        return count($payload['SalesInvoiceLines'] ?? []) === 2
            ?: 'got ' . count($payload['SalesInvoiceLines'] ?? []) . ' lines';
    });

    check('every line carries an item, which Exact makes mandatory', function() use ($payload) {
        foreach ($payload['SalesInvoiceLines'] as $line) {
            if (empty($line['Item'])) {
                return 'a line has no Item: ' . json_encode($line);
            }
        }

        return true;
    });

    check('line amounts are excluding VAT', function() use ($payload) {
        $total = 0.0;

        foreach ($payload['SalesInvoiceLines'] as $line) {
            $total += $line['AmountFC'];
        }

        // 2 × 100 + 1 × 50, no tax adjustments on this fixture.
        return abs($total - 250.00) < 0.005 ?: "got $total";
    });

    check('quantity and unit price agree with the line amount', function() use ($payload) {
        foreach ($payload['SalesInvoiceLines'] as $line) {
            if (abs(($line['Quantity'] * $line['UnitPrice']) - $line['AmountFC']) > 0.01) {
                return 'mismatch on ' . json_encode($line);
            }
        }

        return true;
    });

    check('a domestic sale takes the domestic VAT code', function() use ($payload, $built) {
        return $built['treatment']['treatment'] === 'domestic'
            && $payload['SalesInvoiceLines'][0]['VATCode'] === '21'
            ?: 'got ' . json_encode([$built['treatment']['treatment'], $payload['SalesInvoiceLines'][0]['VATCode'] ?? null]);
    });

    check('the revenue GL account is stamped on each line', function() use ($payload) {
        return ($payload['SalesInvoiceLines'][0]['GLAccount'] ?? null) === 'aa000001-0000-0000-0000-000000000000';
    });

    check('the payload hash is stable across two builds of the same order', function() use ($plugin, $domesticOrder, $built) {
        $again = $plugin->getInvoices()->buildPayload($domesticOrder);

        return $again['hash'] === $built['hash'] ?: 'hashes differ';
    });

    check('the payload hash ignores key order', function() {
        $a = justinholtweb\exactly\services\Invoices::hashPayload(['B' => 1, 'A' => ['Y' => 2, 'X' => 3]]);
        $b = justinholtweb\exactly\services\Invoices::hashPayload(['A' => ['X' => 3, 'Y' => 2], 'B' => 1]);

        return $a === $b;
    });

    check('a preview creates nothing in Exact', function() use ($plugin, $variantA, $fakeApi, $suffix) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], [
            'email' => "preview-only-$suffix@example.com",
        ]);

        $before = count(array_filter($fakeApi->calls, static fn($c) => $c['method'] === 'POST' && $c['endpoint'] === 'crm/Accounts'));
        $plugin->getInvoices()->preview($order);
        $after = count(array_filter($fakeApi->calls, static fn($c) => $c['method'] === 'POST' && $c['endpoint'] === 'crm/Accounts'));

        return $before === $after ?: 'a preview created an account';
    });

    check('a preview warns that the customer will be created', function() use ($plugin, $variantA, $suffix) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], [
            'email' => "preview-warn-$suffix@example.com",
        ]);

        $preview = $plugin->getInvoices()->preview($order);

        foreach ($preview['warnings'] as $warning) {
            if (str_contains($warning, 'will be created')) {
                return true;
            }
        }

        return 'warnings were ' . json_encode($preview['warnings']);
    });

    // =====================================================================
    section('Shipping, discount and included VAT');

    $mixedOrder = makeOrder([['variant' => $variantA, 'qty' => 1]]);
    $mixedLineId = $mixedOrder->getLineItems()[0]->id;
    $mixedOrder = makeOrderAdjustments($mixedOrder, [
        adjustment('shipping', 'Standard shipping', 12.10),
        adjustment('discount', 'Coupon', -10.00),
        // Commerce runs its tax adjuster *after* discounts, so the VAT inside a 100 line that has
        // 10 off is 21% of 90 gross — 15.62, not 17.36. Getting this wrong in a fixture is how you
        // end up "fixing" a builder that was right.
        adjustment('tax', 'BTW 21%', 15.62, true, $mixedLineId, ['id' => 4242, 'name' => 'BTW hoog', 'rate' => 0.21]),
        // 12.10 of shipping with 21% inside: 2.10.
        adjustment('tax', 'BTW 21% shipping', 2.10, true, null, ['id' => 4242]),
    ]);

    $mixedBuilt = $plugin->getInvoices()->buildPayload($mixedOrder);
    $mixedLines = $mixedBuilt['payload']['SalesInvoiceLines'];

    check('included VAT is stripped out of the product line', function() use ($mixedLines) {
        // Commerce charged 100 inclusive of the VAT it computed post-discount; Exact must be told
        // 84.38 and left to add the VAT back itself.
        return abs($mixedLines[0]['AmountFC'] - 84.38) < 0.01 ?: 'got ' . $mixedLines[0]['AmountFC'];
    });

    check('included VAT that belongs to no line comes off the shipping line', function() use ($mixedLines) {
        $shipping = null;

        foreach ($mixedLines as $line) {
            if ($line['Item'] === 'bb000003-0000-0000-0000-000000000000') {
                $shipping = $line;
            }
        }

        return $shipping !== null && abs($shipping['AmountFC'] - 10.00) < 0.01
            ?: 'got ' . var_export($shipping['AmountFC'] ?? null, true);
    });

    check('the discount is a negative line, not a doubled deduction', function() use ($mixedLines) {
        $discount = null;

        foreach ($mixedLines as $line) {
            if ($line['Item'] === 'bb000004-0000-0000-0000-000000000000') {
                $discount = $line;
            }
        }

        return $discount !== null && abs($discount['AmountFC'] + 10.00) < 0.01
            ?: 'got ' . var_export($discount['AmountFC'] ?? null, true);
    });

    check('the reconciliation lands on what the customer actually paid', function() use ($mixedBuilt) {
        $totals = $mixedBuilt['totals'];

        return abs($totals['delta']) <= 0.02
            ?: sprintf('expected %s, charged %s, delta %s', $totals['expectedTotal'], $totals['chargedTotal'], $totals['delta']);
    });

    check('shipping can be switched off, and then it is reported rather than dropped silently', function() use ($plugin, $mixedOrder) {
        // Dropping 10 euros of shipping puts the invoice below what was charged, so the tolerance
        // has to come off too — the point here is the *warning*, not the reconciliation.
        return withSettings(['includeShippingLine' => false, 'roundingTolerance' => 0], function() use ($plugin, $mixedOrder) {
            $result = $plugin->getInvoices()->buildPayload($mixedOrder);

            foreach ($result['warnings'] as $warning) {
                if (str_contains($warning, 'shipping lines are switched off')) {
                    return true;
                }
            }

            return 'warnings were ' . json_encode($result['warnings']);
        });
    });

    // =====================================================================
    section('Reconciliation');

    check('a wrong VAT mapping stops the push instead of booking a wrong total', function() use ($plugin, $mixedOrder) {
        // The 9% code against 21%-inclusive prices leaves Exact several euros short of what was
        // charged. That is the failure a merchant cannot find on their own, so it has to be loud.
        $map = array_merge($plugin->getSettings()->vatCodeByTreatment, ['domestic' => '9']);

        return withSettings(['vatCodeByTreatment' => $map], function() use ($plugin, $mixedOrder) {
            try {
                $plugin->getInvoices()->buildPayload($mixedOrder);

                return 'no exception was thrown';
            } catch (ApiException $e) {
                return str_contains($e->getMessage(), 'more than the rounding tolerance') ?: 'got: ' . $e->getMessage();
            }
        });
    });

    check('with the tolerance at zero it is a warning, not a refusal', function() use ($plugin, $mixedOrder) {
        $map = array_merge($plugin->getSettings()->vatCodeByTreatment, ['domestic' => '9']);

        return withSettings(['roundingTolerance' => 0, 'vatCodeByTreatment' => $map], function() use ($plugin, $mixedOrder) {
            try {
                $result = $plugin->getInvoices()->buildPayload($mixedOrder);
            } catch (Throwable $e) {
                return 'threw anyway: ' . $e->getMessage();
            }

            foreach ($result['warnings'] as $warning) {
                if (str_contains($warning, 'Reconciliation is switched off')) {
                    return true;
                }
            }

            return 'warnings were ' . json_encode($result['warnings']);
        });
    });

    check('an unknown VAT code skips reconciliation rather than blocking every order', function() use ($plugin, $mixedOrder) {
        $map = array_merge($plugin->getSettings()->vatCodeByTreatment, ['domestic' => 'NOPE']);

        return withSettings(['vatCodeByTreatment' => $map], function() use ($plugin, $mixedOrder) {
            try {
                $result = $plugin->getInvoices()->buildPayload($mixedOrder);
            } catch (Throwable $e) {
                return 'threw: ' . $e->getMessage();
            }

            foreach ($result['warnings'] as $warning) {
                if (str_contains($warning, 'could not be read from Exact Online')) {
                    return true;
                }
            }

            return 'warnings were ' . json_encode($result['warnings']);
        });
    });

    // =====================================================================
    section('Cross-border VAT');

    $euBusinessOrder = makeOrder([['variant' => $variantA, 'qty' => 1]], [
        'email' => "eu-business-$suffix@example.com",
        'countryCode' => 'BE',
        'locality' => 'Antwerpen',
        'postalCode' => '2000',
        'organization' => 'Belgische BVBA',
        'organizationTaxId' => 'BE0123456789',
    ], [], true, 0.0);

    check('an EU business with a valid VAT number is reverse charged', function() use ($plugin, $euBusinessOrder) {
        $result = $plugin->getInvoices()->buildPayload($euBusinessOrder);

        return $result['treatment']['treatment'] === 'reverse-charge'
            && $result['payload']['SalesInvoiceLines'][0]['VATCode'] === 'IC'
            ?: 'got ' . json_encode([$result['treatment']['treatment'], $result['payload']['SalesInvoiceLines'][0]['VATCode'] ?? null]);
    });

    check('the VAT number is read off the address’s Organization Tax ID with no configuration', function() use ($plugin, $euBusinessOrder) {
        return $plugin->getVat()->getVatNumberForOrder($euBusinessOrder) === 'BE0123456789'
            ?: 'got ' . $plugin->getVat()->getVatNumberForOrder($euBusinessOrder);
    });

    $euConsumerOrder = makeOrder([['variant' => $variantA, 'qty' => 1]], [
        'email' => "eu-consumer-$suffix@example.com",
        'countryCode' => 'DE',
        'locality' => 'Berlin',
        'postalCode' => '10115',
        'organization' => '',
    ]);

    check('an EU consumer takes the OSS code, not reverse charge', function() use ($plugin, $euConsumerOrder) {
        $result = $plugin->getInvoices()->buildPayload($euConsumerOrder);

        return $result['treatment']['treatment'] === 'oss'
            && $result['payload']['SalesInvoiceLines'][0]['VATCode'] === '21'
            ?: 'got ' . $result['treatment']['treatment'];
    });

    $exportOrder = makeOrder([['variant' => $variantA, 'qty' => 1]], [
        'email' => "export-$suffix@example.com",
        'countryCode' => 'US',
        'locality' => 'Charlotte',
        'administrativeArea' => 'NC',
        'postalCode' => '28202',
        'organization' => '',
    ], [], true, 0.0);

    check('a sale outside the EU takes the export code', function() use ($plugin, $exportOrder) {
        $result = $plugin->getInvoices()->buildPayload($exportOrder);

        return $result['treatment']['treatment'] === 'export'
            && $result['payload']['SalesInvoiceLines'][0]['VATCode'] === 'EX'
            ?: 'got ' . $result['treatment']['treatment'];
    });

    check('a mapped Commerce tax rate overrides the treatment', function() use ($plugin, $mixedOrder) {
        return withSettings(['vatCodeByTaxRate' => ['4242' => '9'], 'roundingTolerance' => 0], function() use ($plugin, $mixedOrder) {
            $result = $plugin->getInvoices()->buildPayload($mixedOrder);
            $code = $result['payload']['SalesInvoiceLines'][0]['VATCode'] ?? null;

            return $code === '9' ?: 'got ' . var_export($code, true);
        });
    });

    check('Lite ignores the per-tax-rate map', function() use ($plugin, $mixedOrder) {
        return withSettings(['vatCodeByTaxRate' => ['4242' => '9']], fn() => withEdition(Plugin::EDITION_LITE, function() use ($plugin, $mixedOrder) {
            $result = $plugin->getInvoices()->buildPayload($mixedOrder);
            $code = $result['payload']['SalesInvoiceLines'][0]['VATCode'] ?? null;

            return $code === '21' ?: 'got ' . var_export($code, true);
        }));
    });

    // =====================================================================
    section('The ledger, and not invoicing an order twice');

    $fakeApi->reset();

    $pushOrder = makeOrder([['variant' => $variantA, 'qty' => 1]], ['email' => "push-$suffix@example.com"]);

    check('the first push writes an invoice and records it', function() use ($plugin, $pushOrder, $fakeApi) {
        $result = $plugin->getInvoices()->push($pushOrder);

        return $result['success']
            && $result['document']?->isSent()
            && $result['document']->invoiceNumber !== null
            && $fakeApi->countCalls('salesinvoice/SalesInvoices') === 1
            ?: 'got ' . json_encode([$result['success'], $result['message']]);
    });

    check('a second push is refused, and reported as skipped rather than failed', function() use ($plugin, $pushOrder, $fakeApi) {
        // A queue job that treated “already invoiced” as a failure would retry forever against a
        // condition that can never change.
        $before = $fakeApi->countCalls('salesinvoice/SalesInvoices');
        $result = $plugin->getInvoices()->push($pushOrder);
        $after = $fakeApi->countCalls('salesinvoice/SalesInvoices');

        return $result['success'] === false
            && $result['skipped'] === true
            && $before === $after
            && str_contains($result['message'], 'already invoice')
            ?: 'got ' . json_encode([$result['success'], $result['skipped'], $result['message']]);
    });

    check('the unique index refuses a second row at the database level', function() use ($pushOrder, $plugin) {
        // The claim logic is the polite version; this is the guarantee underneath it.
        try {
            Craft::$app->getDb()->createCommand()->insert(Table::DOCUMENTS, [
                'orderId' => $pushOrder->id,
                'division' => TEST_DIVISION,
                'kind' => Document::KIND_INVOICE,
                'status' => Document::STATUS_PENDING,
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])->execute();

            return 'a duplicate row was accepted';
        } catch (yii\db\IntegrityException) {
            return true;
        }
    });

    check('forcing a second push is allowed, because a duplicate has to be a deliberate act', function() use ($plugin, $pushOrder, $fakeApi) {
        $before = $fakeApi->countCalls('salesinvoice/SalesInvoices');
        $result = $plugin->getInvoices()->push($pushOrder, ['force' => true]);
        $after = $fakeApi->countCalls('salesinvoice/SalesInvoices');

        return $result['success'] && $after === $before + 1 ?: 'got ' . json_encode([$result['success'], $result['message']]);
    });

    check('a claim held by another process is not stolen', function() use ($plugin, $variantA, $suffix) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], ['email' => "inflight-$suffix@example.com"]);

        $first = $plugin->getDocuments()->claim($order, TEST_DIVISION);
        $second = $plugin->getDocuments()->claim($order, TEST_DIVISION);

        return $first['claimed'] === true
            && $second['claimed'] === false
            && str_contains((string)$second['reason'], 'right now')
            ?: 'got ' . json_encode([$first['claimed'], $second['claimed'], $second['reason']]);
    });

    check('a claim abandoned by a killed worker is reclaimable once it goes stale', function() use ($plugin, $variantA, $suffix) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], ['email' => "stale-$suffix@example.com"]);

        $first = $plugin->getDocuments()->claim($order, TEST_DIVISION);

        // Wind the attempt back past the staleness window, as a worker killed mid-push would leave it.
        Craft::$app->getDb()->createCommand()->update(Table::DOCUMENTS, [
            'dateLastAttempt' => Db::prepareDateForDb((new DateTime())->modify('-30 minutes')),
        ], ['id' => $first['document']->id])->execute();

        $second = $plugin->getDocuments()->claim($order, TEST_DIVISION);

        return $second['claimed'] === true ?: 'reason: ' . $second['reason'];
    });

    check('a failed push records the reason and can be retried', function() use ($plugin, $variantA, $fakeApi, $suffix) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], ['email' => "fail-$suffix@example.com"]);

        $fakeApi->failures['salesinvoice/SalesInvoices'] = new ApiException('Journal 70 is closed', 400);
        $result = $plugin->getInvoices()->push($order);
        $fakeApi->failures = [];

        $document = $plugin->getDocuments()->getDocument((int)$order->id, TEST_DIVISION);

        if ($result['success'] !== false || $document?->status !== Document::STATUS_FAILED) {
            return 'got ' . json_encode([$result['success'], $document?->status]);
        }

        $retry = $plugin->getInvoices()->push($order);

        return $retry['success'] === true && $retry['document']?->attempts === 2
            ?: 'retry: ' . json_encode([$retry['success'], $retry['document']?->attempts]);
    });

    check('a retryable API error is distinguished from a permanent one', function() {
        return (new ApiException('boom', 503))->isRetryable() === true
            && (new ApiException('bad journal', 400))->isRetryable() === false;
    });

    check('a rate limit is always retryable and carries a wait', function() {
        $error = (new justinholtweb\exactly\errors\RateLimitException('slow down', 429))->setRetryAfter(42);

        return $error->isRetryable() && $error->retryAfter === 42;
    });

    check('a sent document is flagged stale when the order changes underneath it', function() use ($plugin, $pushOrder) {
        $document = $plugin->getDocuments()->getDocument((int)$pushOrder->id, TEST_DIVISION);

        return $document !== null
            && $document->isStale('a-different-hash') === true
            && $document->isStale($document->payloadHash) === false;
    });

    // =====================================================================
    section('Credit notes');

    check('a credit note is a second document, not a replacement', function() use ($plugin, $pushOrder, $fakeApi) {
        $result = $plugin->getSync()->creditNote($pushOrder);
        $posted = $fakeApi->lastPost('salesinvoice/SalesInvoices');

        $documents = $plugin->getDocuments()->getDocumentsForOrder((int)$pushOrder->id);

        return $result['success']
            && ($posted['Type'] ?? null) === 8021
            && count($documents) === 2
            ?: 'got ' . json_encode([$result['success'], $result['message'], count($documents)]);
    });

    check('crediting an order that was never invoiced is refused', function() use ($plugin, $variantA, $suffix) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], ['email' => "uncredited-$suffix@example.com"]);
        $result = $plugin->getSync()->creditNote($order);

        return $result['success'] === false && str_contains($result['message'], 'no Exact Online invoice');
    });

    check('the negative sign setting flips the line amounts', function() use ($plugin, $variantA, $fakeApi, $suffix) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], ['email' => "negcredit-$suffix@example.com"]);

        return withSettings(['creditNoteSign' => 'negative'], function() use ($plugin, $order, $fakeApi) {
            $plugin->getInvoices()->push($order);
            $plugin->getInvoices()->push($order, ['kind' => Document::KIND_CREDIT_NOTE]);

            $posted = $fakeApi->lastPost('salesinvoice/SalesInvoices');
            $amount = $posted['SalesInvoiceLines'][0]['AmountFC'] ?? null;

            return $amount !== null && $amount < 0 ?: 'got ' . var_export($amount, true);
        });
    });

    check('Lite cannot issue credit notes', function() use ($plugin, $pushOrder) {
        return withEdition(Plugin::EDITION_LITE, function() use ($plugin, $pushOrder) {
            $result = $plugin->getSync()->creditNote($pushOrder);

            return $result['success'] === false && str_contains($result['message'], 'Pro');
        });
    });

    // =====================================================================
    section('Delivery');

    check('delivery is not attempted when it is switched off', function() use ($plugin, $pushOrder, $fakeApi) {
        $document = $plugin->getDocuments()->getDocument((int)$pushOrder->id, TEST_DIVISION);
        $before = $fakeApi->countCalls('PrintedSalesInvoices');
        $plugin->getInvoices()->deliver($document);

        return $fakeApi->countCalls('PrintedSalesInvoices') === $before;
    });

    check('Peppol delivery posts the Peppol flag', function() use ($plugin, $pushOrder, $fakeApi) {
        return withSettings(['deliveryMode' => 'peppol'], function() use ($plugin, $pushOrder, $fakeApi) {
            $document = $plugin->getDocuments()->getDocument((int)$pushOrder->id, TEST_DIVISION);
            $fakeApi->printResult = [];
            $plugin->getInvoices()->deliver($document);
            $posted = $fakeApi->lastPost('salesinvoice/PrintedSalesInvoices');

            return ($posted['SendInvoiceViaPeppol'] ?? null) === true
                && ($posted['InvoiceID'] ?? null) === $document->exactInvoiceId
                ?: 'posted ' . json_encode($posted);
        });
    });

    check('a failed email is surfaced even though the endpoint answered 200', function() use ($plugin, $pushOrder, $fakeApi) {
        // The print endpoint reports per-channel failures in the body, so a bounced email looks
        // exactly like a successful one unless it is actually read.
        return withSettings(['deliveryMode' => 'email'], function() use ($plugin, $pushOrder, $fakeApi) {
            $fakeApi->printResult = ['EmailCreationError' => 'No email address on the account'];
            $document = $plugin->getDocuments()->getDocument((int)$pushOrder->id, TEST_DIVISION);
            $warning = $plugin->getInvoices()->deliver($document);
            $fakeApi->printResult = [];

            return $warning !== null && str_contains($warning, 'No email address') ?: 'got ' . var_export($warning, true);
        });
    });

    check('a delivery failure never un-sends the invoice', function() use ($plugin, $pushOrder, $fakeApi) {
        return withSettings(['deliveryMode' => 'email'], function() use ($plugin, $pushOrder, $fakeApi) {
            $fakeApi->failures['PrintedSalesInvoices'] = new ApiException('mail server down', 500);
            $document = $plugin->getDocuments()->getDocument((int)$pushOrder->id, TEST_DIVISION);
            $warning = $plugin->getInvoices()->deliver($document);
            $fakeApi->failures = [];

            $after = $plugin->getDocuments()->getDocument((int)$pushOrder->id, TEST_DIVISION);

            return $warning !== null && $after?->isSent() === true && $after->deliveryStatus === 'failed'
                ?: 'got ' . json_encode([$warning, $after?->status, $after?->deliveryStatus]);
        });
    });

    // =====================================================================
    section('Payments');

    check('an invoice missing from the open items list is treated as paid', function() use ($plugin, $fakeApi, $pushOrder) {
        $fakeApi->receivables = [];
        $result = $plugin->getPayments()->sync();

        $document = $plugin->getDocuments()->getDocument((int)$pushOrder->id, TEST_DIVISION);

        return $result['checked'] > 0 && $document?->paymentStatus === 'paid'
            ?: 'got ' . json_encode([$result, $document?->paymentStatus]);
    });

    check('an invoice on the open items list is outstanding, with the amount recorded', function() use ($plugin, $fakeApi, $pushOrder) {
        $document = $plugin->getDocuments()->getDocument((int)$pushOrder->id, TEST_DIVISION);

        $fakeApi->receivables = [[
            'InvoiceNumber' => $document->invoiceNumber,
            'Amount' => 25.00,
            'CurrencyCode' => 'EUR',
        ]];

        $plugin->getPayments()->sync();
        $after = $plugin->getDocuments()->getDocument((int)$pushOrder->id, TEST_DIVISION);
        $fakeApi->receivables = [];

        return in_array($after?->paymentStatus, ['outstanding', 'partial'], true)
            ?: 'got ' . var_export($after?->paymentStatus, true);
    });

    check('Lite does not reconcile payments at all', function() use ($plugin) {
        return withEdition(Plugin::EDITION_LITE, function() use ($plugin) {
            return $plugin->getPayments()->sync()['checked'] === 0;
        });
    });

    // =====================================================================
    section('Triggers and scheduling');

    check('the manual trigger never makes an order eligible', function() use ($plugin, $domesticOrder) {
        return withSettings(['pushTrigger' => 'manual'], function() use ($plugin, $domesticOrder) {
            return $plugin->getSync()->evaluate($domesticOrder)['eligible'] === false;
        });
    });

    check('the “order completed” trigger makes a completed order eligible', function() use ($plugin, $variantA, $suffix) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], ['email' => "trigger-$suffix@example.com"]);

        return withSettings(['pushTrigger' => 'completed'], function() use ($plugin, $order) {
            $evaluation = $plugin->getSync()->evaluate($order);

            return $evaluation['eligible'] === true ?: 'reason: ' . $evaluation['reason'];
        });
    });

    check('an already-invoiced order is never eligible again', function() use ($plugin, $pushOrder) {
        return withSettings(['pushTrigger' => 'completed'], function() use ($plugin, $pushOrder) {
            $evaluation = $plugin->getSync()->evaluate($pushOrder);

            return $evaluation['eligible'] === false && str_contains((string)$evaluation['reason'], 'Already invoiced')
                ?: 'reason: ' . $evaluation['reason'];
        });
    });

    check('the “paid” trigger waits for payment', function() use ($plugin, $variantA, $suffix) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], ['email' => "unpaid-$suffix@example.com"]);

        return withSettings(['pushTrigger' => 'paid'], function() use ($plugin, $order) {
            $evaluation = $plugin->getSync()->evaluate($order);

            return $evaluation['eligible'] === false && str_contains((string)$evaluation['reason'], 'not paid')
                ?: 'reason: ' . $evaluation['reason'];
        });
    });

    check('Lite forces the manual trigger whatever the setting says', function() use ($plugin) {
        return withSettings(['pushTrigger' => 'completed'], fn() => withEdition(Plugin::EDITION_LITE, function() use ($plugin) {
            $effective = $plugin->getSettings()->getEffectivePushTrigger();

            return $effective === 'manual' ?: "got $effective";
        }));
    });

    check('an uninvoiced order shows up in the backfill list', function() use ($plugin, $variantA, $suffix) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], ['email' => "backfill-$suffix@example.com"]);
        $orders = $plugin->getSync()->getUninvoicedOrders(null, 500);
        $ids = array_map(static fn(Order $o) => $o->id, $orders);

        return in_array($order->id, $ids, true) ?: 'not found among ' . count($ids) . ' orders';
    });

    check('an invoiced order does not', function() use ($plugin, $pushOrder) {
        $orders = $plugin->getSync()->getUninvoicedOrders(null, 500);
        $ids = array_map(static fn(Order $o) => $o->id, $orders);

        return !in_array($pushOrder->id, $ids, true);
    });

    check('a dry-run backfill queues nothing', function() use ($plugin) {
        $before = Craft::$app->getQueue()->getTotalJobs();
        $result = $plugin->getSync()->backfill(null, 5, true);
        $after = Craft::$app->getQueue()->getTotalJobs();

        return $result['queued'] > 0 && $before === $after ?: "jobs went from $before to $after";
    });

    check('a real backfill queues jobs', function() use ($plugin) {
        return withSettings(['useQueue' => true], function() use ($plugin) {
            $before = Craft::$app->getQueue()->getTotalJobs();
            $result = $plugin->getSync()->backfill(null, 3, false);
            $after = Craft::$app->getQueue()->getTotalJobs();

            return $result['queued'] > 0 && $after > $before ?: "queued {$result['queued']}, jobs $before → $after";
        });
    });

    check('the summary reports what an operator needs', function() use ($plugin) {
        $summary = $plugin->getSync()->getSummary();

        return isset($summary['sent'], $summary['failed'], $summary['queued'], $summary['trigger'])
            && $summary['sent'] > 0;
    });

    // =====================================================================
    section('Twig API');

    check('craft.exactly exposes the invoice number for an order', function() use ($pushOrder) {
        $variable = new justinholtweb\exactly\twig\ExactlyVariable();

        return $variable->invoiceNumber($pushOrder) !== null;
    });

    check('craft.exactly reports the VAT treatment', function() use ($pushOrder) {
        $variable = new justinholtweb\exactly\twig\ExactlyVariable();

        return $variable->vatTreatment($pushOrder) === 'domestic'
            ?: 'got ' . var_export($variable->vatTreatment($pushOrder), true);
    });

    check('craft.exactly returns null for an order with no invoice, rather than throwing', function() use ($variantA, $suffix) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], ['email' => "twig-$suffix@example.com"]);
        $variable = new justinholtweb\exactly\twig\ExactlyVariable();

        return $variable->invoiceNumber($order) === null && $variable->document($order) === null;
    });

    check('craft.exactly copes with a null order', function() {
        $variable = new justinholtweb\exactly\twig\ExactlyVariable();

        return $variable->invoiceNumber(null) === null && $variable->documents(null) === [];
    });

    // =====================================================================
    section('Housekeeping');

    check('a ledger row cannot even be written for an order that does not exist', function() {
        // The foreign key is the real guarantee; garbage collection is only there for installs
        // where it has been lost (a partial restore, an import without constraints).
        try {
            Craft::$app->getDb()->createCommand()->insert(Table::DOCUMENTS, [
                'orderId' => 2147483600,
                'division' => TEST_DIVISION,
                'kind' => 'invoice',
                'status' => 'pending',
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])->execute();

            return 'the database accepted a row for a non-existent order';
        } catch (yii\db\IntegrityException) {
            return true;
        }
    });

    check('garbage collection leaves valid rows alone', function() use ($plugin, $pushOrder) {
        $before = $plugin->getDocuments()->count();
        $collected = $plugin->getSync()->garbageCollect();
        $after = $plugin->getDocuments()->count();

        return $collected === 0 && $before === $after ?: "collected $collected, $before → $after";
    });

    check('deleting an order takes its ledger rows with it', function() use ($plugin, $variantA, $suffix) {
        $order = makeOrder([['variant' => $variantA, 'qty' => 1]], ['email' => "cascade-$suffix@example.com"]);
        $plugin->getInvoices()->push($order);

        if ($plugin->getDocuments()->getDocument((int)$order->id, TEST_DIVISION) === null) {
            return 'no document was recorded to begin with';
        }

        Craft::$app->getElements()->deleteElement($order, true);

        return $plugin->getDocuments()->getDocument((int)$order->id, TEST_DIVISION) === null;
    });

    check('the log prunes to its retention window', function() use ($plugin) {
        Craft::$app->getDb()->createCommand()->insert(Table::LOG, [
            'action' => 'checks.old',
            'level' => LogEntry::LEVEL_INFO,
            'dateCreated' => Db::prepareDateForDb((new DateTime())->modify('-400 days')),
            'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            'uid' => StringHelper::UUID(),
        ])->execute();

        $plugin->getLog()->prune(30);

        return (int)(new craft\db\Query())->from([Table::LOG])->where(['action' => 'checks.old'])->count() === 0;
    });

    check('clearing the lookup caches empties the account and item tables', function() use ($plugin) {
        $plugin->getAccounts()->clearCache(TEST_DIVISION);
        $plugin->getItems()->clearCache(TEST_DIVISION);

        return $plugin->getAccounts()->countCached(TEST_DIVISION) === 0
            && $plugin->getItems()->countCached(TEST_DIVISION) === 0;
    });
} finally {
    section('Cleanup');

    $elements = Craft::$app->getElements();
    $db = Craft::$app->getDb();

    foreach ($createdOrders as $fixtureOrder) {
        try {
            $db->createCommand()->delete(Table::DOCUMENTS, ['orderId' => $fixtureOrder->id])->execute();
            $elements->deleteElement($fixtureOrder, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$fixtureOrder->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdProducts as $fixtureProduct) {
        try {
            $elements->deleteElement($fixtureProduct, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$fixtureProduct->id}: {$e->getMessage()}\n";
        }
    }

    foreach ([Table::LOG, Table::ACCOUNTS, Table::ITEMS] as $table) {
        try {
            $db->createCommand()->delete($table)->execute();
        } catch (Throwable $e) {
            echo "  ! could not clear $table: {$e->getMessage()}\n";
        }
    }

    try {
        $db->createCommand()->delete(Table::CONNECTIONS, ['division' => TEST_DIVISION])->execute();
    } catch (Throwable $e) {
        echo "  ! could not clear the connection: {$e->getMessage()}\n";
    }

    // Settings and the edition were only ever changed in memory, so there is nothing to undo on
    // disk — but restoring them keeps anything running after this in the same process honest.
    applySettings($originalSettings);
    switchEdition($originalEdition);

    echo "  ✓ fixtures removed, settings restored\n";

    echo "\n" . str_repeat('-', 60) . "\n";
    echo "  $passed passed, $failed failed\n";
    echo str_repeat('-', 60) . "\n";
}

exit($failed > 0 ? 1 : 0);
