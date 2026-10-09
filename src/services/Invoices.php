<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\helpers\App;
use DateTime;
use justinholtweb\exactly\errors\ApiException;
use justinholtweb\exactly\errors\RateLimitException;
use justinholtweb\exactly\helpers\Odata;
use justinholtweb\exactly\helpers\Vat as VatHelper;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\Plugin;

/**
 * Turning a Commerce order into an Exact Online sales invoice.
 *
 * **`buildPayload()` is the only place an order becomes an Exact payload.** The CP preview, the
 * console preview, the manual push and the queue job all go through it, so what a merchant sees on
 * the preview screen is byte-for-byte what Exact receives. That is not a nicety — the whole reason
 * anyone previews an accounting push is to check the numbers before they become permanent, and a
 * preview built by a second code path is worse than no preview at all.
 *
 * ## The numbers
 *
 * Exact wants amounts **excluding VAT** and computes the VAT itself from each line's VAT code.
 * Commerce may or may not have included VAT in its prices, which is the usual source of a ten-cent
 * discrepancy nobody can explain, so:
 *
 * - A line's ex-VAT amount is `subtotal − taxIncluded`, per line, from Commerce's own figures.
 * - Included tax that belongs to *no* line (shipping, order-level charges) is the difference
 *   between the order's included tax and the sum of the lines', and comes off the shipping line.
 * - Order-level and line-level discounts land on one discount line. They are never inside
 *   `subtotal`, so nothing is double-counted.
 *
 * Then the arithmetic is checked before anything is sent: the VAT Exact will compute is predicted
 * from the mapped codes' percentages and compared against what the customer was actually charged.
 * A difference inside `roundingTolerance` becomes a rounding line; a difference outside it stops
 * the push. Booking a total that does not match the payment is the one failure a merchant cannot
 * discover on their own.
 */
class Invoices extends Component
{
    public const ENDPOINT = 'salesinvoice/SalesInvoices';
    public const PRINT_ENDPOINT = 'salesinvoice/PrintedSalesInvoices';

    /**
     * Exact document types.
     */
    public const TYPE_SALES_INVOICE = 8020;
    public const TYPE_CREDIT_NOTE = 8021;

    // Building
    // -------------------------------------------------------------------------

    /**
     * Build the Exact payload for an order.
     *
     * @param array{
     *     kind?: string,
     *     accountId?: string|null,
     *     allowCreate?: bool,
     *     creditAmount?: float|null
     * } $options
     * @return array{
     *     payload: array,
     *     hash: string,
     *     treatment: array,
     *     totals: array,
     *     warnings: string[]
     * }
     * @throws ApiException
     */
    public function buildPayload(Order $order, array $options = []): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $kind = $options['kind'] ?? Document::KIND_INVOICE;
        $allowCreate = $options['allowCreate'] ?? true;
        $warnings = [];

        $treatment = $plugin->getVat()->getTreatmentForOrder($order);

        if ($treatment['note'] !== null) {
            $warnings[] = $treatment['note'];
        }

        // Two phases, and the order matters. First the arithmetic — amounts, VAT codes and the
        // reconciliation verdict — which reads from Exact but never writes. Only once the total is
        // known to be right are the customer's account and the items resolved, because resolving
        // them may *create* them in Exact: an order refused for a VAT gap must not leave a new
        // customer or item behind in the administration.
        $vatPercentages = $this->getVatPercentages();
        $specs = [];
        $lineExVatTotal = 0.0;
        $lineIncludedTax = 0.0;
        $expectedVat = 0.0;
        $vatUnknown = false;

        foreach ($order->getLineItems() as $lineItem) {
            $includedTax = (float)$lineItem->getTaxIncluded();
            $exVat = (float)$lineItem->getSubtotal() - $includedTax;

            $lineIncludedTax += $includedTax;
            $lineExVatTotal += $exVat;

            $vatCode = $plugin->getVat()->getVatCodeForLine($order, $treatment['treatment'], $lineItem);
            [$vat, $known] = $this->predictVat($exVat, $vatCode, $vatPercentages);
            $expectedVat += $vat;
            $vatUnknown = $vatUnknown || !$known;

            $specs[] = [
                'lineItem' => $lineItem,
                'description' => $this->lineDescription($order, $lineItem),
                'quantity' => (float)$lineItem->qty,
                'amountExVat' => $exVat,
                'vatCode' => $vatCode,
                'glAccountId' => $plugin->getItems()->resolveGlAccountId($lineItem),
            ];
        }

        // Included tax that belongs to no line — shipping, and any order-level charge Commerce
        // taxed inclusively. It has to come off the shipping line or the invoice over-states it.
        $orphanIncludedTax = round((float)$order->getTotalTaxIncluded() - $lineIncludedTax, 4);

        $shippingExVat = round((float)$order->getTotalShippingCost() - $orphanIncludedTax, 4);

        if ($settings->includeShippingLine && abs($shippingExVat) >= 0.005) {
            $vatCode = $plugin->getVat()->getVatCodeForLine($order, $treatment['treatment']);
            [$vat, $known] = $this->predictVat($shippingExVat, $vatCode, $vatPercentages);
            $expectedVat += $vat;
            $vatUnknown = $vatUnknown || !$known;

            $specs[] = [
                'configuredItem' => [$settings->shippingItemCode, Craft::t('exactly', 'shipping')],
                'description' => Craft::t('exactly', 'Shipping'),
                'quantity' => 1.0,
                'amountExVat' => $shippingExVat,
                'vatCode' => $vatCode,
                'glAccountId' => $plugin->getLedger()->resolveAccountId($settings->shippingGlAccountCode),
            ];
        } elseif (!$settings->includeShippingLine && abs($shippingExVat) >= 0.005) {
            $warnings[] = Craft::t('exactly', 'This order has {amount} of shipping, but shipping lines are switched off.', [
                'amount' => $this->money($shippingExVat, $order),
            ]);
        }

        // Commerce reports discounts as negative amounts, which is exactly what the invoice wants.
        $discount = round((float)$order->getTotalDiscount(), 4);

        if ($settings->includeDiscountLine && abs($discount) >= 0.005) {
            $vatCode = $plugin->getVat()->getVatCodeForLine($order, $treatment['treatment']);
            [$vat, $known] = $this->predictVat($discount, $vatCode, $vatPercentages);
            $expectedVat += $vat;
            $vatUnknown = $vatUnknown || !$known;

            $specs[] = [
                'configuredItem' => [$settings->discountItemCode, Craft::t('exactly', 'discount')],
                'description' => $this->discountDescription($order),
                'quantity' => 1.0,
                'amountExVat' => $discount,
                'vatCode' => $vatCode,
                'glAccountId' => $plugin->getLedger()->resolveAccountId($settings->discountGlAccountCode),
            ];
        } elseif (!$settings->includeDiscountLine && abs($discount) >= 0.005) {
            $warnings[] = Craft::t('exactly', 'This order has {amount} of discount, but discount lines are switched off.', [
                'amount' => $this->money($discount, $order),
            ]);
        }

        $subtotalExVat = round($lineExVatTotal + $shippingExVat + $discount, 2);
        $expectedTotal = round($subtotalExVat + $expectedVat, 2);
        $chargedTotal = round((float)$order->getTotalPrice(), 2);
        $delta = round($chargedTotal - $expectedTotal, 2);

        if ($vatUnknown) {
            $warnings[] = Craft::t('exactly', 'At least one VAT code’s rate could not be read from Exact Online, so the invoice total was not reconciled against the order total.');
        } elseif (abs($delta) >= 0.005) {
            if ($settings->roundingTolerance > 0 && abs($delta) <= $settings->roundingTolerance) {
                $specs[] = [
                    'configuredItem' => [$settings->roundingItemCode, Craft::t('exactly', 'rounding')],
                    'description' => Craft::t('exactly', 'Rounding difference'),
                    'quantity' => 1.0,
                    'amountExVat' => $delta,
                    // Must be a 0% code: a rounding line that attracts VAT moves the total by the
                    // delta *plus* VAT and never lands on the right number.
                    'vatCode' => trim($settings->roundingVatCode) !== '' ? trim($settings->roundingVatCode) : null,
                    'glAccountId' => $plugin->getLedger()->resolveAccountId($settings->roundingGlAccountCode),
                ];

                $warnings[] = Craft::t('exactly', 'A {amount} rounding line was added so the invoice totals {total}.', [
                    'amount' => $this->money($delta, $order),
                    'total' => $this->money($chargedTotal, $order),
                ]);
            } elseif ($settings->roundingTolerance > 0) {
                // Refused before anything below has had the chance to create an account or item.
                throw new ApiException(Craft::t('exactly', 'Exact Online would invoice {expected} but the customer paid {charged}. That is more than the rounding tolerance, so nothing was sent — check the VAT code mapping for this order’s treatment ({treatment}).', [
                    'expected' => $this->money($expectedTotal, $order),
                    'charged' => $this->money($chargedTotal, $order),
                    'treatment' => $treatment['treatment'],
                ]));
            } else {
                $warnings[] = Craft::t('exactly', 'Exact Online will invoice {expected} against an order total of {charged}. Reconciliation is switched off, so it was sent anyway.', [
                    'expected' => $this->money($expectedTotal, $order),
                    'charged' => $this->money($chargedTotal, $order),
                ]);
            }
        }

        if ($specs === []) {
            throw new ApiException(Craft::t('exactly', 'This order has nothing to invoice.'));
        }

        // Phase two: the total is right, so the items and the account may now be resolved — and,
        // when allowed, created. Cheapest-to-fail first: the configured shipping, discount and
        // rounding items are read-only lookups that throw on a code missing from Exact, so they
        // go before anything that can create; the account, the likeliest create, goes last.
        foreach ($specs as $index => $spec) {
            if (isset($spec['configuredItem'])) {
                $specs[$index]['itemId'] = $this->resolveConfiguredItem(...$spec['configuredItem']);
            }
        }

        $lines = [];

        foreach ($specs as $spec) {
            if (isset($spec['lineItem'])) {
                $item = $plugin->getItems()->resolveForLineItem($spec['lineItem'], $allowCreate);

                if (!empty($item['pending'])) {
                    $warnings[] = Craft::t('exactly', 'SKU “{sku}” is not in Exact Online yet. An item will be created for it when the invoice is sent, so this preview has no item on that line.', [
                        'sku' => $spec['lineItem']->getSku(),
                    ]);
                } elseif ($item['fallback'] && $settings->itemStrategy === 'sku') {
                    $warnings[] = Craft::t('exactly', 'SKU “{sku}” is not in Exact Online; it will be invoiced against the fallback item.', [
                        'sku' => $spec['lineItem']->getSku(),
                    ]);
                }

                $itemId = $item['id'];
            } else {
                $itemId = $spec['itemId'];
            }

            $lines[] = $this->buildLine(
                itemId: $itemId,
                description: $spec['description'],
                quantity: $spec['quantity'],
                amountExVat: $spec['amountExVat'],
                vatCode: $spec['vatCode'],
                glAccountId: $spec['glAccountId'],
                settings: $settings,
            );
        }

        $accountId = $options['accountId'] ?? null;

        if ($accountId === null) {
            $account = $plugin->getAccounts()->resolveForOrder($order, $allowCreate);
            $accountId = $account['id'] !== '' ? $account['id'] : null;

            if ($accountId === null) {
                // Only a preview gets here (a send creates the account or throws), so say which:
                // the payload above has no `OrderedBy` either way.
                $warnings[] = $settings->createMissingAccounts
                    ? Craft::t('exactly', 'No Exact Online account matches this customer yet; one will be created when the invoice is sent.')
                    : Craft::t('exactly', 'No Exact Online account matches {email}, and creating accounts is switched off.', [
                        'email' => (string)$order->getEmail() ?: Craft::t('exactly', 'this customer'),
                    ]);
            }
        }

        if ($kind === Document::KIND_CREDIT_NOTE && $settings->creditNoteSign === 'negative') {
            $lines = $this->negateLines($lines);
        }

        $payload = array_filter([
            'Journal' => trim($settings->journalCode),
            'OrderedBy' => $accountId,
            'Type' => $kind === Document::KIND_CREDIT_NOTE ? self::TYPE_CREDIT_NOTE : self::TYPE_SALES_INVOICE,
            'InvoiceDate' => Odata::formatDay($this->invoiceDate($order)),
            'OrderDate' => $order->dateOrdered ? Odata::formatDay($order->dateOrdered) : null,
            'Currency' => strtoupper((string)($order->currency ?: '')) ?: null,
            'Description' => $this->render($settings->descriptionTemplate, $order, 255),
            'Remarks' => $this->render($settings->remarksTemplate, $order, 4000),
            'YourRef' => mb_substr(Plugin::getInstance()->getDocuments()->orderNumber($order), 0, 50),
            'PaymentCondition' => trim($settings->paymentConditionCode) ?: null,
            'SalesInvoiceLines' => $lines,
        ], static fn($value) => $value !== null && $value !== '' && $value !== []);

        return [
            'payload' => $payload,
            'hash' => self::hashPayload($payload),
            'treatment' => $treatment,
            'totals' => [
                'subtotalExVat' => $subtotalExVat,
                'expectedVat' => round($expectedVat, 2),
                'expectedTotal' => $expectedTotal,
                'chargedTotal' => $chargedTotal,
                'delta' => $delta,
                'currency' => strtoupper((string)($order->currency ?: '')),
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * A preview: identical construction, but nothing is created in Exact along the way.
     *
     * @throws ApiException
     */
    public function preview(Order $order, string $kind = Document::KIND_INVOICE): array
    {
        return $this->buildPayload($order, ['kind' => $kind, 'allowCreate' => false]);
    }

    /**
     * A stable fingerprint of a payload, so the CP can say "this order changed after it was
     * invoiced" without storing and diffing two copies of it.
     */
    public static function hashPayload(array $payload): string
    {
        // Recursive key sort: PHP array order is stable but the *construction* order is not
        // guaranteed to stay the same across releases, and a hash that changes when nothing did
        // would flag every historical invoice as stale.
        $normalize = static function(array $data) use (&$normalize): array {
            ksort($data);

            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $data[$key] = $normalize($value);
                }
            }

            return $data;
        };

        return sha1((string)json_encode($normalize($payload), JSON_PRESERVE_ZERO_FRACTION));
    }

    // Pushing
    // -------------------------------------------------------------------------

    /**
     * Send an order to Exact Online.
     *
     * Every failure comes back in the result except a rate limit, which is thrown so the caller
     * can wait and come back: the queue job re-queues on it, and the CP and console say how long.
     * The claim is released first, so the row is `failed` (attempt not counted), never `sending`.
     *
     * @throws RateLimitException
     * @return array{
     *     success: bool,
     *     document: Document|null,
     *     message: string,
     *     warnings: string[],
     *     skipped: bool
     * }
     */
    public function push(Order $order, array $options = []): array
    {
        try {
            return $this->runPush($order, $options);
        } finally {
            // Every push is a chance to notice trouble without cron. Fail-open: see afterSync().
            Plugin::getInstance()->getAlerts()->afterSync();
        }
    }

    /**
     * The push itself; {@see push()} wraps it so every exit evaluates the alerts.
     */
    private function runPush(Order $order, array $options): array
    {
        $plugin = Plugin::getInstance();
        $kind = $options['kind'] ?? Document::KIND_INVOICE;
        $force = $options['force'] ?? false;

        $division = $plugin->getOauth()->getDivision();

        if ($division === null || $division <= 0) {
            return $this->failure(null, Craft::t('exactly', 'No Exact Online division is selected yet.'));
        }

        $claim = $plugin->getDocuments()->claim($order, $division, $kind, $force);
        $document = $claim['document'];

        if (!$claim['claimed']) {
            return [
                'success' => false,
                'document' => $document,
                'message' => (string)$claim['reason'],
                'warnings' => [],
                // "Already invoiced" is not a failure. A queue job that treats it as one retries
                // forever against a condition that will never change.
                'skipped' => true,
            ];
        }

        try {
            // The account is resolved inside buildPayload(), after the total has been reconciled,
            // so a refused order writes nothing to Exact — not even a new customer.
            $built = $this->buildPayload($order, ['kind' => $kind]);
            $accountId = $built['payload']['OrderedBy'] ?? null;

            $created = $plugin->getApi()->post(self::ENDPOINT, $built['payload'], [
                'action' => $kind === Document::KIND_CREDIT_NOTE ? 'invoices.credit' : 'invoices.push',
                'orderId' => $order->id,
                'summary' => Craft::t('exactly', 'Create {kind} for order {number}', [
                    'kind' => $kind === Document::KIND_CREDIT_NOTE ? 'credit note' : 'invoice',
                    'number' => $plugin->getDocuments()->orderNumber($order),
                ]),
            ]);

            if (!is_array($created) || !isset($created['InvoiceID'])) {
                throw new ApiException(Craft::t('exactly', 'Exact Online accepted the invoice but returned no invoice ID.'));
            }

            $document = $plugin->getDocuments()->markSent($document, $created, $built['payload'], $built['hash'], [
                'vatTreatment' => $built['treatment']['treatment'],
                'exactAccountId' => $accountId ?: null,
            ]);

            $warnings = $built['warnings'];

            // An Exact sales invoice created through the API is a *draft* until it is printed or
            // sent, and a draft is not in the general ledger — sync a year of orders with delivery
            // switched off and the revenue report is empty. Say so rather than let it be found in
            // a quarter's time.
            if ($document->isDraft() && $plugin->getSettings()->deliveryMode === 'none') {
                $warnings[] = Craft::t('exactly', 'Exact Online has this as a draft. A draft invoice is not in the ledger and not receivable until it is printed or sent — either process it in Exact, or set a delivery method in Exactly’s settings.');
            }

            // Delivery is deliberately after the invoice is recorded as sent. A failure to email a
            // PDF must never make a written invoice look unwritten.
            $deliveryWarning = $this->deliver($document);

            if ($deliveryWarning !== null) {
                $warnings[] = $deliveryWarning;
            }

            // Payments made before the invoice existed (the usual case: the payment is what
            // completed the order) waited for it; now they can be matched. Only queued, never
            // posted inline — and a failure to queue them must not make the invoice look unsent.
            try {
                $plugin->getPaymentEntries()->queueForOrder((int)$order->id);
            } catch (\Throwable $e) {
                Craft::warning('Exactly could not queue the payments for order ' . $order->id . ': ' . $e->getMessage(), __METHOD__);
            }

            return [
                'success' => true,
                'document' => $document,
                'message' => Craft::t('exactly', 'Sent to Exact Online as {label}.', ['label' => $document->getLabel()]),
                'warnings' => $warnings,
                'skipped' => false,
            ];
        } catch (RateLimitException $e) {
            // Not a failure of this order: Exact refused the call before anything was created
            // (delivery, the only call after the invoice exists, swallows its own errors). Give the
            // claim back without spending an attempt — leaving it `sending` would block every
            // retry for the staleness window — and let the caller decide when to come back: the
            // queue job re-queues itself, a person is told how long to wait.
            $plugin->getDocuments()->release($document, Document::STATUS_FAILED, $e->getMessage());

            throw $e;
        } catch (\Throwable $e) {
            $document = $plugin->getDocuments()->markFailed($document, $e->getMessage());

            return $this->failure($document, $e->getMessage());
        }
    }

    /**
     * What to tell a person whose push hit Exact's rate limit.
     */
    public static function rateLimitMessage(RateLimitException $e): string
    {
        return Craft::t('exactly', 'Exact Online is rate limiting this administration, so nothing was sent. Try again in {seconds} seconds.', [
            'seconds' => $e->retryAfter,
        ]);
    }

    // Delivery
    // -------------------------------------------------------------------------

    /**
     * Ask Exact to print, email, post or Peppol the invoice.
     *
     * Returns a warning string when delivery failed, and null when it succeeded or was not asked
     * for. Never throws: the invoice already exists, and a bounced email is a smaller problem than
     * a merchant believing it does not.
     */
    public function deliver(Document $document): ?string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if ($settings->deliveryMode === 'none') {
            return null;
        }

        if ($document->exactInvoiceId === null) {
            return null;
        }

        $payload = array_filter([
            'InvoiceID' => $document->exactInvoiceId,
            'DocumentLayout' => trim($settings->documentLayoutId) ?: null,
            'EmailLayout' => trim($settings->emailLayoutId) ?: null,
            'SenderEmailAddress' => trim((string)App::parseEnv($settings->senderEmailAddress)) ?: null,
            'ExtraText' => trim($settings->extraText) ?: null,
            'SendEmailToCustomer' => $settings->deliveryMode === 'email' ?: null,
            'SendInvoiceToCustomerPostbox' => $settings->deliveryMode === 'postbox' ?: null,
            'SendInvoiceViaPeppol' => $settings->deliveryMode === 'peppol' ?: null,
            'SendOutputBasedOnAccount' => $settings->deliveryMode === 'account' ?: null,
        ], static fn($value) => $value !== null && $value !== '');

        try {
            $result = $plugin->getApi()->post(self::PRINT_ENDPOINT, $payload, [
                'action' => 'invoices.deliver',
                'orderId' => $document->orderId,
                'summary' => Craft::t('exactly', 'Deliver invoice ({mode})', ['mode' => $settings->deliveryMode]),
            ]);

            $status = $this->describeDelivery(is_array($result) ? $result : []);
            $plugin->getDocuments()->recordDelivery($document, $status['status']);

            return $status['warning'];
        } catch (\Throwable $e) {
            $plugin->getDocuments()->recordDelivery($document, 'failed');

            return Craft::t('exactly', 'The invoice was created, but Exact Online could not send it: {error}', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The print endpoint answers 200 with per-channel success flags rather than an error, so a
     * failed email looks exactly like a successful one unless the body is actually read.
     *
     * @return array{status: string, warning: string|null}
     */
    private function describeDelivery(array $result): array
    {
        $failures = [];

        foreach ([
            'DocumentCreationError' => Craft::t('exactly', 'PDF'),
            'EmailCreationError' => Craft::t('exactly', 'email'),
            'PostboxMessageCreationError' => Craft::t('exactly', 'postbox'),
            'PeppolCreationError' => Craft::t('exactly', 'Peppol'),
        ] as $key => $label) {
            $error = trim((string)($result[$key] ?? ''));

            if ($error !== '') {
                $failures[] = $label . ': ' . $error;
            }
        }

        if ($failures === []) {
            return ['status' => 'sent', 'warning' => null];
        }

        return [
            'status' => 'partial',
            'warning' => Craft::t('exactly', 'The invoice was created, but delivery reported: {errors}', [
                'errors' => implode('; ', $failures),
            ]),
        ];
    }

    // Line construction
    // -------------------------------------------------------------------------

    /**
     * One invoice line.
     *
     * `Quantity` and `UnitPrice` are both sent alongside `AmountFC` on purpose. Exact honours
     * `AmountFC`, but the quantity and unit price are what a human reads on the printed invoice,
     * and a line showing "1 × €82.64" for a two-item order is the sort of thing that generates a
     * support ticket rather than a bug report.
     */
    private function buildLine(
        string $itemId,
        string $description,
        float $quantity,
        float $amountExVat,
        ?string $vatCode,
        ?string $glAccountId,
        \justinholtweb\exactly\models\Settings $settings,
    ): array {
        $amount = round($amountExVat, 2);
        $quantity = $quantity != 0.0 ? $quantity : 1.0;

        return array_filter([
            'Item' => $itemId,
            'Description' => mb_substr($description, 0, 255),
            'Quantity' => $quantity,
            // Six decimals: a €19.99 item split three ways rounds visibly at two, and Exact takes
            // a double.
            'UnitPrice' => round($amountExVat / $quantity, 6),
            'AmountFC' => $amount,
            'VATCode' => $vatCode,
            'GLAccount' => $glAccountId,
            'CostCenter' => trim($settings->costCenterCode) ?: null,
            'CostUnit' => trim($settings->costUnitCode) ?: null,
        ], static fn($value) => $value !== null && $value !== '');
    }

    /**
     * @param array<int, array> $lines
     * @return array<int, array>
     */
    private function negateLines(array $lines): array
    {
        foreach ($lines as $index => $line) {
            foreach (['Quantity', 'UnitPrice', 'AmountFC'] as $key) {
                if (isset($line[$key])) {
                    $lines[$index][$key] = -1 * $line[$key];
                }
            }
        }

        return $lines;
    }

    /**
     * An item GUID for one of the synthetic lines (shipping, discount, rounding), falling back to
     * the general fallback item when none is configured for it.
     *
     * @throws ApiException
     */
    private function resolveConfiguredItem(string $code, string $label): string
    {
        $code = trim($code);
        $items = Plugin::getInstance()->getItems();

        if ($code === '') {
            return $items->fallback()['id'];
        }

        if (Odata::isGuid($code)) {
            return $code;
        }

        $found = $items->findByCode($code);

        if ($found === null || !isset($found['ID'])) {
            throw new ApiException(Craft::t('exactly', 'The {label} item “{code}” does not exist in this Exact Online administration.', [
                'label' => $label,
                'code' => $code,
            ]));
        }

        return (string)$found['ID'];
    }

    // Small pieces
    // -------------------------------------------------------------------------

    /**
     * The VAT Exact will compute for a line, and whether the rate was actually known.
     *
     * @return array{0: float, 1: bool}
     */
    private function predictVat(float $amountExVat, ?string $vatCode, array $percentages): array
    {
        if ($vatCode === null) {
            // No code means Exact falls back to the item's own default, which cannot be predicted
            // from here.
            return [0.0, false];
        }

        $code = Odata::trimCode($vatCode);

        if (!array_key_exists($code, $percentages)) {
            return [0.0, false];
        }

        return [$amountExVat * ($percentages[$code] / 100), true];
    }

    /**
     * @return array<string, float> VAT code => percentage
     */
    private function getVatPercentages(): array
    {
        $percentages = [];

        foreach (Plugin::getInstance()->getVat()->getExactVatCodes() as $code) {
            $percentages[$code['code']] = (float)$code['percentage'];
        }

        return $percentages;
    }

    private function invoiceDate(Order $order): DateTime
    {
        return match (Plugin::getInstance()->getSettings()->invoiceDateSource) {
            'paidDate' => $order->datePaid ?? $order->dateOrdered ?? new DateTime(),
            'today' => new DateTime(),
            default => $order->dateOrdered ?? new DateTime(),
        };
    }

    private function lineDescription(Order $order, LineItem $lineItem): string
    {
        $template = trim(Plugin::getInstance()->getSettings()->lineDescriptionTemplate);

        if ($template === '') {
            return (string)$lineItem->getDescription();
        }

        try {
            return (string)Craft::$app->getView()->renderObjectTemplate($template, $lineItem, [
                'order' => $order,
                // `renderObjectTemplate()` calls its subject `object`, which is not what anybody
                // types first.
                'lineItem' => $lineItem,
            ]);
        } catch (\Throwable) {
            return (string)$lineItem->getDescription();
        }
    }

    private function discountDescription(Order $order): string
    {
        $coupon = trim((string)$order->couponCode);

        if ($coupon !== '') {
            return Craft::t('exactly', 'Discount ({code})', ['code' => $coupon]);
        }

        return Craft::t('exactly', 'Discount');
    }

    private function render(string $template, Order $order, int $maxLength): ?string
    {
        $template = trim($template);

        if ($template === '') {
            return null;
        }

        try {
            $rendered = (string)Craft::$app->getView()->renderObjectTemplate($template, $order, [
                'order' => $order,
            ]);
        } catch (\Throwable $e) {
            Craft::warning('Exactly could not render a template: ' . $e->getMessage(), __METHOD__);

            return null;
        }

        $rendered = trim($rendered);

        return $rendered !== '' ? mb_substr($rendered, 0, $maxLength) : null;
    }

    private function money(float $amount, Order $order): string
    {
        return Craft::$app->getFormatter()->asCurrency($amount, (string)($order->currency ?: 'EUR'));
    }

    private function failure(?Document $document, string $message): array
    {
        return [
            'success' => false,
            'document' => $document,
            'message' => $message,
            'warnings' => [],
            'skipped' => false,
        ];
    }

    /**
     * The VAT treatment labels, for templates.
     *
     * @return array<string, string>
     */
    public function getTreatmentLabels(): array
    {
        return VatHelper::treatments();
    }
}
