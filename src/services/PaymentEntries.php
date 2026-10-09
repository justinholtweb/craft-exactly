<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeZone;
use justinholtweb\exactly\db\Table;
use justinholtweb\exactly\errors\ApiException;
use justinholtweb\exactly\errors\PaymentEntryException;
use justinholtweb\exactly\errors\RateLimitException;
use justinholtweb\exactly\events\PaymentEntryEvent;
use justinholtweb\exactly\events\ProcessorFeeEvent;
use justinholtweb\exactly\helpers\Odata;
use justinholtweb\exactly\jobs\RegisterPayment;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\models\LogEntry;
use justinholtweb\exactly\models\PaymentEntry;
use justinholtweb\exactly\Plugin;
use Throwable;
use yii\db\IntegrityException;

/**
 * Entering Commerce payments in Exact Online, matched to their invoices.
 *
 * `services\Payments` *reads* whether Exact considers an invoice paid. This writes the payment
 * there in the first place: each successful capture or purchase becomes one line on a bank (or
 * cash) entry in the gateway's journal, carrying the customer `Account` and the invoice number as
 * `OurRef`, which is how Exact matches a line to an open item. A refund is the mirror image,
 * matched to the order's credit note. Without it a bookkeeper matches every Stripe or Mollie
 * settlement by hand.
 *
 * Endpoint and fields read from Exact's own reference, not guessed:
 * `financialtransaction/BankEntries` (bank *and* payment-service journals) and
 * `financialtransaction/CashEntries`, posted with their lines in one request (`BankEntryLines` /
 * `CashEntryLines`). `JournalCode` is mandatory on the header; `GLAccount`, `AmountFC` and the
 * header reference are mandatory on a line; `OurRef` is an Int32 described as "Invoice number".
 *
 * ## The same two invariants as invoices
 *
 * 1. **{@see buildPayload()} is the only place a transaction becomes an Exact body.** The CP
 *    preview, the console preview and the post all go through it.
 * 2. **{@see claim()} is the only place a payment row is created or advanced into `sending`.**
 *    `{{%exactly_payments}}` is unique on `(transactionId, division)` and the claim is the
 *    insert-first, conditional-UPDATE shape `Documents::claim()` uses, so one transaction is one
 *    entry however many queue retries, buttons and console runs ask. It is keyed on the
 *    transaction, not the order, because an order can be paid in several captures.
 *
 * One more guard, for the one case a claim cannot see: a POST that reached Exact but whose answer
 * never came back. Every line carries a description unique to the transaction, and any attempt
 * after the first looks that description up before posting again.
 *
 * Nothing here is on the checkout path: transactions are only ever *queued* from Commerce's
 * events, and everything that can fail does so into the row and the log.
 */
class PaymentEntries extends Component
{
    public const KIND_PAYMENT = 'payment';
    public const KIND_REFUND = 'refund';

    public const STATUS_PENDING = 'pending';
    /** Waiting for the invoice (or credit note) to exist and be processed in Exact. */
    public const STATUS_WAITING = 'waiting';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const ENDPOINT_BANK = 'financialtransaction/BankEntries';
    public const ENDPOINT_CASH = 'financialtransaction/CashEntries';
    public const LINES_ENDPOINT_BANK = 'financialtransaction/BankEntryLines';
    public const LINES_ENDPOINT_CASH = 'financialtransaction/CashEntryLines';

    /**
     * @event ProcessorFeeEvent when the fee for a transaction is worked out; set `fee` to supply
     * or override it.
     */
    public const EVENT_DEFINE_PROCESSOR_FEE = 'defineProcessorFee';

    /**
     * @event PaymentEntryEvent before an entry is posted; change `payload`, or set `isValid` to
     * false to keep the transaction out of Exact.
     */
    public const EVENT_BEFORE_REGISTER = 'beforeRegister';

    /** Exact's line descriptions are short; this keeps the reference whole. */
    public const MAX_REFERENCE = 60;

    /** How long a `sending` row is respected — the same window as an invoice's. */
    public const STALE_ATTEMPT_MINUTES = Documents::STALE_ATTEMPT_MINUTES;

    private const MUTEX_TIMEOUT = 15;

    /**
     * Stripe reports amounts in minor units except for these.
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv',
        'xaf', 'xof', 'xpf',
    ];

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => Craft::t('exactly', 'Pending'),
            self::STATUS_WAITING => Craft::t('exactly', 'Waiting for the invoice'),
            self::STATUS_SENDING => Craft::t('exactly', 'Sending'),
            self::STATUS_SENT => Craft::t('exactly', 'Entered'),
            self::STATUS_FAILED => Craft::t('exactly', 'Failed'),
            self::STATUS_SKIPPED => Craft::t('exactly', 'Skipped'),
        ];
    }

    // Which transactions
    // -------------------------------------------------------------------------

    /**
     * `payment` for a successful capture or purchase, `refund` for a successful refund, null for
     * anything else — an authorization is a promise, not money.
     */
    public function kindOf(Transaction $transaction): ?string
    {
        if ($transaction->status !== TransactionRecord::STATUS_SUCCESS) {
            return null;
        }

        return match ($transaction->type) {
            TransactionRecord::TYPE_CAPTURE, TransactionRecord::TYPE_PURCHASE => self::KIND_PAYMENT,
            TransactionRecord::TYPE_REFUND => self::KIND_REFUND,
            default => null,
        };
    }

    /**
     * Whether Exactly should enter this transaction at all, as configured.
     */
    public function wants(Transaction $transaction): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $kind = $this->kindOf($transaction);

        return $settings->registerPayments
            && $kind !== null
            && ($kind === self::KIND_PAYMENT || $settings->registerRefunds);
    }

    /**
     * An order's payments and refunds, oldest first.
     *
     * @return Transaction[]
     */
    public function getTransactions(int $orderId): array
    {
        $transactions = array_values(array_filter(
            Commerce::getInstance()->getTransactions()->getAllTransactionsByOrderId($orderId),
            fn(Transaction $transaction): bool => $this->kindOf($transaction) !== null,
        ));

        usort($transactions, static fn(Transaction $a, Transaction $b): int => $a->id <=> $b->id);

        return $transactions;
    }

    // Queueing
    // -------------------------------------------------------------------------

    /**
     * Queue a transaction's entry, if it is one Exactly should enter. Called for every transaction
     * Commerce saves, so it never touches the network and never throws: a checkout must not be
     * able to fail because Exact is down.
     */
    public function queueTransaction(Transaction $transaction): bool
    {
        try {
            $plugin = Plugin::getInstance();

            if (!$transaction->id || !$this->wants($transaction) || !$plugin->getOauth()->isConnected()) {
                return false;
            }

            Craft::$app->getQueue()->push(new RegisterPayment(['transactionId' => (int)$transaction->id]));

            return true;
        } catch (Throwable $e) {
            Craft::warning('Exactly could not queue transaction ' . $transaction->id . ': ' . $e->getMessage(), __METHOD__);

            return false;
        }
    }

    /**
     * Queue every payment and refund on an order that is not yet in Exact. The invoice and credit
     * note pushes call this once the document exists, which is what picks up payments made before
     * it did.
     */
    public function queueForOrder(int $orderId): int
    {
        $plugin = Plugin::getInstance();
        $division = $plugin->getOauth()->getDivision();

        if (!$plugin->getSettings()->registerPayments || $division === null || !Plugin::commerceIsReady()) {
            return 0;
        }

        $sent = $this->sentTransactionIds($orderId, $division);
        $queued = 0;

        foreach ($this->getTransactions($orderId) as $transaction) {
            if (in_array((int)$transaction->id, $sent, true) || !$this->wants($transaction)) {
                continue;
            }

            Craft::$app->getQueue()->push(new RegisterPayment(['transactionId' => (int)$transaction->id]));
            $queued++;
        }

        return $queued;
    }

    // Registering
    // -------------------------------------------------------------------------

    /**
     * Enter every payment and refund on an order now — the order panel's button and the console.
     * Entries already in Exact are reported as skipped, never posted again.
     *
     * @return array{sent: int, waiting: int, failed: int, skipped: int, messages: string[]}
     */
    public function registerForOrder(Order $order): array
    {
        $summary = ['sent' => 0, 'waiting' => 0, 'failed' => 0, 'skipped' => 0, 'messages' => []];

        foreach ($this->getTransactions((int)$order->id) as $transaction) {
            if (!$this->wants($transaction)) {
                continue;
            }

            try {
                $result = $this->register($transaction);
            } catch (RateLimitException $e) {
                $summary['failed']++;
                $summary['messages'][] = Invoices::rateLimitMessage($e);
                break;
            }

            $key = match ($result['status']) {
                self::STATUS_SENT => 'sent',
                self::STATUS_WAITING => 'waiting',
                self::STATUS_FAILED => 'failed',
                default => 'skipped',
            };
            $summary[$key]++;

            if (in_array($result['status'], [self::STATUS_FAILED, self::STATUS_WAITING], true) && $result['message']) {
                $summary['messages'][] = $result['message'];
            }
        }

        return $summary;
    }

    /**
     * Enter one Commerce transaction in Exact, matched to its invoice or credit note.
     *
     * `retryable` says whether trying again later could go differently (Exact unreachable, a 5xx)
     * as opposed to a refusal or a configuration gap that answers the same way every time. A rate
     * limit is thrown, like an invoice push's, after the claim is released without spending an
     * attempt.
     *
     * @return array{status: string, entry: ?PaymentEntry, message: ?string, retryable: bool}
     * @throws RateLimitException
     */
    public function register(Transaction $transaction): array
    {
        try {
            return $this->runRegister($transaction);
        } finally {
            Plugin::getInstance()->getAlerts()->afterSync();
        }
    }

    /**
     * @return array{status: string, entry: ?PaymentEntry, message: ?string, retryable: bool}
     * @throws RateLimitException
     */
    private function runRegister(Transaction $transaction): array
    {
        $plugin = Plugin::getInstance();
        $kind = $this->kindOf($transaction);

        if (!$transaction->id || $kind === null) {
            return self::result(self::STATUS_SKIPPED, null, Craft::t('exactly', 'Only a successful capture, purchase or refund is entered in Exact Online.'));
        }

        if (!$this->wants($transaction)) {
            return self::result(self::STATUS_SKIPPED, null, Craft::t('exactly', 'Entering payments in Exact Online is switched off.'));
        }

        $order = $transaction->getOrder();
        $division = $plugin->getOauth()->getDivision();

        if (!$order instanceof Order || !$order->id) {
            return self::result(self::STATUS_SKIPPED, null, Craft::t('exactly', 'The transaction has no order.'));
        }

        if ($division === null || $division <= 0) {
            return self::result(self::STATUS_SKIPPED, null, Craft::t('exactly', 'No Exact Online division is selected yet.'));
        }

        $mutex = Craft::$app->getMutex();
        $lock = 'exactly.payment.' . $transaction->id . '.' . $division;

        if (!$mutex->acquire($lock, self::MUTEX_TIMEOUT)) {
            return self::result(self::STATUS_SKIPPED, null, Craft::t('exactly', 'Another process is entering this payment right now.'));
        }

        try {
            $claim = $this->claim($transaction, $order, $division, $kind);
            $entry = $claim['entry'];

            if (!$claim['claimed']) {
                return self::result(self::STATUS_SKIPPED, $entry, $claim['reason']);
            }

            $document = $this->matchDocument($order, $division, $kind);

            if ($document === null) {
                return $this->park($entry, $kind);
            }

            try {
                $built = $this->buildPayload($transaction, $document);
            } catch (PaymentEntryException $e) {
                // Nothing reached Exact, and it will answer the same way until a setting changes —
                // so it is not retried, and it does not spend an attempt either: once the setting
                // is fixed, the retry paths must still pick it up.
                return $this->fail($entry, $e->getMessage(), false, false);
            }

            $payload = $built['payload'];

            if ($this->hasEventHandlers(self::EVENT_BEFORE_REGISTER)) {
                $event = new PaymentEntryEvent(['transaction' => $transaction, 'kind' => $kind, 'payload' => $payload]);
                $this->trigger(self::EVENT_BEFORE_REGISTER, $event);

                if (!$event->isValid) {
                    $reason = $event->message !== '' ? $event->message : Craft::t('exactly', 'A plugin or module chose not to enter this transaction.');
                    $entry = $this->update($entry, ['status' => self::STATUS_SKIPPED, 'lastError' => mb_substr($reason, 0, 2000)]);

                    return self::result(self::STATUS_SKIPPED, $entry, $reason);
                }

                $payload = $event->payload;
            }

            $entry = $this->update($entry, [
                'documentId' => $document->id,
                'invoiceNumber' => $document->invoiceNumber,
                'entryType' => $built['entryType'],
                'journal' => $built['journal'],
                'fee' => $built['fee'],
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);

            $api = $plugin->getApi();
            $context = [
                'action' => $kind === self::KIND_REFUND ? 'payments.refund' : 'payments.register',
                'orderId' => $order->id,
            ];

            try {
                // A previous attempt may have reached Exact and lost its answer on the way back.
                // The description is unique to the transaction, so look before posting again.
                if ($entry->attempts > 1) {
                    $existing = $api->findOne(
                        $built['linesEndpoint'],
                        'Description eq ' . Odata::quote((string)$built['reference']),
                        ['ID', 'EntryID', 'EntryNumber', 'AmountFC'],
                        $context + ['summary' => Craft::t('exactly', 'Look for an earlier entry of {reference}', ['reference' => $built['reference']])],
                    );

                    if ($existing !== null && Odata::isGuid($existing['EntryID'] ?? null)) {
                        return $this->succeed($entry, $existing, $built, $order, $document, true);
                    }
                }

                $created = $api->post($built['endpoint'], $payload, $context + [
                    'summary' => Craft::t('exactly', 'Enter {kind} {amount} for order {number}', [
                        'kind' => $kind,
                        'amount' => number_format((float)$built['amount'], 2),
                        'number' => $plugin->getDocuments()->orderNumber($order),
                    ]),
                ]);
            } catch (RateLimitException $e) {
                // Nothing was entered. Give the claim back without spending an attempt.
                $this->update($entry, [
                    'status' => self::STATUS_FAILED,
                    'attempts' => max(0, $entry->attempts - 1),
                    'lastError' => mb_substr($e->getMessage(), 0, 2000),
                ]);

                throw $e;
            } catch (ApiException $e) {
                return $this->fail($entry, $e->getMessage(), $e->isRetryable());
            } catch (Throwable $e) {
                return $this->fail($entry, $e->getMessage(), true);
            }

            if (!is_array($created) || !Odata::isGuid($created['EntryID'] ?? null)) {
                // Exact took it (a 2xx) without saying what it made. Recorded as failed so a person
                // looks, and the next attempt's lookup finds it rather than posting it twice.
                return $this->fail($entry, Craft::t('exactly', 'Exact Online accepted the entry but returned no entry ID.'), true);
            }

            return $this->succeed($entry, $created, $built, $order, $document, false);
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * The invoice a payment is matched to, or the credit note a refund is — or null when it is not
     * in Exact, processed, yet.
     *
     * A sales invoice created through the API is a *draft* until it is printed or sent, and a
     * draft is not an open item: a payment line pointing at it has nothing to match. So the
     * document has to be processed, and its stored status is re-read from Exact when it still says
     * otherwise (the status stored at creation is always a draft).
     */
    private function matchDocument(Order $order, int $division, string $kind): ?Document
    {
        $plugin = Plugin::getInstance();
        $document = $plugin->getDocuments()->getDocument(
            (int)$order->id,
            $division,
            $kind === self::KIND_REFUND ? Document::KIND_CREDIT_NOTE : Document::KIND_INVOICE,
        );

        if ($document === null || !$document->isSent()) {
            return null;
        }

        if (!$document->isProcessed()) {
            $document = $plugin->getPayments()->refreshStatuses([$document])['documents'][0] ?? $document;
        }

        return $document->isProcessed() ? $document : null;
    }

    /**
     * Park an entry whose invoice or credit note is not ready. Not a failure, and not an attempt:
     * the invoice push queues it again once there is something to match.
     *
     * A refund with no credit note on its way at all is different — a partial refund, or credit
     * notes switched off — and there is nothing to wait for, so it is skipped with the reason.
     *
     * @return array{status: string, entry: ?PaymentEntry, message: ?string, retryable: bool}
     */
    private function park(PaymentEntry $entry, string $kind): array
    {
        $plugin = Plugin::getInstance();

        if ($kind === self::KIND_REFUND) {
            $credit = $plugin->getDocuments()->getDocument((int)$entry->orderId, (int)$entry->division, Document::KIND_CREDIT_NOTE);

            if ($credit === null || $credit->status === Document::STATUS_SKIPPED) {
                $message = Craft::t('exactly', 'There is no Exact Online credit note for this order to match the refund to, so it was not entered. Book it by hand, or issue a credit note and run it again.');
                $entry = $this->update($entry, [
                    'status' => self::STATUS_SKIPPED,
                    'attempts' => max(0, $entry->attempts - 1),
                    'lastError' => $message,
                ]);

                return self::result(self::STATUS_SKIPPED, $entry, $message);
            }
        }

        $message = $kind === self::KIND_REFUND
            ? Craft::t('exactly', 'Waiting for the credit note to be processed in Exact Online; the refund is entered once it is.')
            : Craft::t('exactly', 'Waiting for the invoice to be processed in Exact Online; the payment is entered once it is. A draft invoice is not an open item, so there is nothing to match yet.');

        $entry = $this->update($entry, [
            'status' => self::STATUS_WAITING,
            'attempts' => max(0, $entry->attempts - 1),
            'lastError' => $message,
        ]);

        return self::result(self::STATUS_WAITING, $entry, $message);
    }

    /**
     * @param array<string, mixed> $created what Exact answered (or the line an earlier attempt left)
     * @return array{status: string, entry: ?PaymentEntry, message: ?string, retryable: bool}
     */
    private function succeed(PaymentEntry $entry, array $created, array $built, Order $order, Document $document, bool $recovered): array
    {
        $entry = $this->update($entry, [
            'status' => self::STATUS_SENT,
            'exactEntryId' => (string)$created['EntryID'],
            'entryNumber' => isset($created['EntryNumber']) && is_scalar($created['EntryNumber']) ? mb_substr((string)$created['EntryNumber'], 0, 64) : null,
            'lastError' => null,
            'dateSent' => Db::prepareDateForDb(new DateTime()),
        ]);

        Plugin::getInstance()->getLog()->write('payments.register', [
            'orderId' => $order->id,
            'division' => $entry->division,
            'summary' => mb_substr($recovered
                ? Craft::t('exactly', 'Found {reference} already entered in Exact Online (entry {entry}); not posted again.', [
                    'reference' => $built['reference'],
                    'entry' => $entry->entryNumber ?? $entry->exactEntryId,
                ])
                : Craft::t('exactly', 'Entered {amount} against {number} (entry {entry}).', [
                    'amount' => number_format((float)$built['amount'], 2),
                    'number' => $document->invoiceNumber,
                    'entry' => $entry->entryNumber ?? $entry->exactEntryId,
                ]), 0, 255),
        ]);

        return self::result(self::STATUS_SENT, $entry, null);
    }

    /**
     * @return array{status: string, entry: ?PaymentEntry, message: ?string, retryable: bool}
     */
    private function fail(PaymentEntry $entry, string $message, bool $retryable, bool $countAttempt = true): array
    {
        $values = [
            'status' => self::STATUS_FAILED,
            'lastError' => mb_substr($message, 0, 2000),
        ];

        if (!$countAttempt) {
            $values['attempts'] = max(0, $entry->attempts - 1);
        }

        $entry = $this->update($entry, $values);

        Plugin::getInstance()->getLog()->write('payments.register', [
            'level' => LogEntry::LEVEL_ERROR,
            'orderId' => $entry->orderId,
            'division' => $entry->division,
            'summary' => mb_substr($message, 0, 255),
            'message' => $message,
        ]);

        return self::result(self::STATUS_FAILED, $entry, $message, $retryable);
    }

    /**
     * @return array{status: string, entry: ?PaymentEntry, message: ?string, retryable: bool}
     */
    private static function result(string $status, ?PaymentEntry $entry, ?string $message, bool $retryable = false): array
    {
        return ['status' => $status, 'entry' => $entry, 'message' => $message, 'retryable' => $retryable];
    }

    // Claiming
    // -------------------------------------------------------------------------

    /**
     * Take ownership of a transaction's row, or find out that somebody else has it — the same
     * shape as `Documents::claim()`: insert first and treat the duplicate key as the expected
     * branch; take over an existing row with an `UPDATE … WHERE status = <what was read>` whose
     * affected-row count is the answer.
     *
     * @return array{entry: PaymentEntry|null, claimed: bool, reason: string|null}
     */
    public function claim(Transaction $transaction, Order $order, int $division, string $kind): array
    {
        $now = Db::prepareDateForDb(new DateTime());
        $facts = $this->facts($transaction, $kind);

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::PAYMENTS, $facts + [
                'transactionId' => (int)$transaction->id,
                'orderId' => (int)$order->id,
                'division' => $division,
                'kind' => $kind,
                'status' => self::STATUS_SENDING,
                'attempts' => 1,
                'dateLastAttempt' => $now,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            return ['entry' => $this->getEntry((int)$transaction->id, $division), 'claimed' => true, 'reason' => null];
        } catch (IntegrityException) {
            // Entered before, or somebody got there first. Both are handled below.
        }

        $entry = $this->getEntry((int)$transaction->id, $division);

        if ($entry === null) {
            return ['entry' => null, 'claimed' => false, 'reason' => Craft::t('exactly', 'Could not record the payment.')];
        }

        if ($entry->isSent()) {
            return [
                'entry' => $entry,
                'claimed' => false,
                'reason' => Craft::t('exactly', 'This payment is already entry {entry} in Exact Online.', ['entry' => $entry->entryNumber ?? $entry->exactEntryId]),
            ];
        }

        if ($entry->status === self::STATUS_SENDING && !$this->isStale($entry)) {
            return ['entry' => $entry, 'claimed' => false, 'reason' => Craft::t('exactly', 'Another process is entering this payment right now.')];
        }

        $affected = Craft::$app->getDb()->createCommand()->update(Table::PAYMENTS, $facts + [
            'status' => self::STATUS_SENDING,
            'attempts' => $entry->attempts + 1,
            'dateLastAttempt' => $now,
            'dateUpdated' => $now,
        ], ['and', ['id' => $entry->id], ['status' => $entry->status]])->execute();

        if ($affected === 0) {
            return ['entry' => $this->getEntry((int)$transaction->id, $division), 'claimed' => false, 'reason' => Craft::t('exactly', 'Another process claimed this payment first.')];
        }

        return ['entry' => $this->getEntry((int)$transaction->id, $division), 'claimed' => true, 'reason' => null];
    }

    private function isStale(PaymentEntry $entry): bool
    {
        return $entry->dateLastAttempt === null
            || $entry->dateLastAttempt->getTimestamp() < time() - (self::STALE_ATTEMPT_MINUTES * 60);
    }

    /**
     * What the row records about the transaction itself.
     *
     * @return array<string, mixed>
     */
    private function facts(Transaction $transaction, string $kind): array
    {
        try {
            $gateway = $transaction->getGateway()?->handle;
        } catch (Throwable) {
            $gateway = null;
        }

        return [
            'gatewayHandle' => $gateway,
            'currency' => strtoupper((string)($transaction->currency ?: $transaction->paymentCurrency)) ?: null,
            'amount' => round(abs((float)$transaction->amount), 2),
            'reference' => self::referenceFor($transaction, $kind),
            'dateTransaction' => Db::prepareDateForDb($transaction->dateCreated ?? new DateTime()),
        ];
    }

    // The payload
    // -------------------------------------------------------------------------

    /**
     * What a transaction looks like as an Exact entry, without sending anything — the preview and
     * the post both come through here.
     *
     * @param Document|null $document the invoice (or credit note) to match; looked up when null.
     *                                With none in Exact yet, the line is built without `OurRef`
     *                                and a warning says so — fine for a preview, never posted.
     * @return array{
     *     payload: array<string, mixed>,
     *     endpoint: string,
     *     linesEndpoint: string,
     *     entryType: string,
     *     journal: string,
     *     kind: string,
     *     amount: float,
     *     fee: float|null,
     *     reference: string,
     *     document: Document|null,
     *     warnings: string[],
     * }
     * @throws PaymentEntryException when the settings cannot produce an entry
     */
    public function buildPayload(Transaction $transaction, ?Document $document = null): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $kind = $this->kindOf($transaction);
        $warnings = [];

        if ($kind === null) {
            throw new PaymentEntryException(Craft::t('exactly', 'Only a successful capture, purchase or refund is entered in Exact Online.'));
        }

        $order = $transaction->getOrder();

        if (!$order instanceof Order) {
            throw new PaymentEntryException(Craft::t('exactly', 'The transaction has no order.'));
        }

        if ($document === null) {
            $division = $plugin->getOauth()->getDivision();
            $document = $division !== null
                ? $plugin->getDocuments()->getDocument((int)$order->id, $division, $kind === self::KIND_REFUND ? Document::KIND_CREDIT_NOTE : Document::KIND_INVOICE)
                : null;

            if ($document !== null && !$document->isSent()) {
                $document = null;
            }
        }

        try {
            $gateway = $transaction->getGateway()?->handle;
        } catch (Throwable) {
            $gateway = null;
        }

        $journal = $settings->getPaymentJournalFor($gateway);

        if ($journal === '') {
            throw new PaymentEntryException(Craft::t('exactly', 'No Exact Online journal is set for the “{gateway}” gateway, and there is no default payment journal. Set one under Payment entries in Exactly’s settings.', [
                'gateway' => $gateway ?: '?',
            ]));
        }

        $receivables = $plugin->getLedger()->resolveAccountId($settings->paymentReceivablesGlAccountCode);

        if ($receivables === null) {
            throw new PaymentEntryException(trim($settings->paymentReceivablesGlAccountCode) === ''
                ? Craft::t('exactly', 'Set the receivables GL account under Payment entries in Exactly’s settings. Exact needs a ledger account on every entry line.')
                : Craft::t('exactly', 'GL account “{code}” is not in this Exact Online administration’s chart of accounts.', ['code' => $settings->paymentReceivablesGlAccountCode]));
        }

        $currency = strtoupper((string)($transaction->currency ?: $order->currency));

        if ($document !== null && $document->currency !== null && $document->currency !== '' && strtoupper($document->currency) !== $currency) {
            throw new PaymentEntryException(Craft::t('exactly', 'The transaction is in {paid} but {number} is in {invoiced}; Exactly enters a payment in the invoice’s currency only.', [
                'paid' => $currency,
                'number' => $document->getLabel(),
                'invoiced' => $document->currency,
            ]));
        }

        $amount = round(abs((float)$transaction->amount), 2);
        // Money in is `+1` by default; see `Settings::$paymentAmountSign`.
        $in = $settings->paymentAmountSign === 'negative' ? -1 : 1;
        $sign = $kind === self::KIND_REFUND ? -$in : $in;
        $reference = self::referenceFor($transaction, $kind);
        $date = Odata::formatDay(self::localDate($transaction));

        $line = [
            'GLAccount' => $receivables,
            'AmountFC' => round($sign * $amount, 2),
            'Date' => $date,
            'Description' => $reference,
        ];

        if ($document !== null && Odata::isGuid($document->exactAccountId)) {
            $line['Account'] = $document->exactAccountId;
        }

        $number = $document !== null ? trim((string)$document->invoiceNumber) : '';

        if ($number !== '' && ctype_digit($number) && (int)$number <= 2147483647) {
            // Exact matches a line to an open item by account and invoice number. `OurRef` is an
            // Int32, so it goes as a number, not the string the invoice number is stored as.
            $line['OurRef'] = (int)$number;
        } elseif ($document === null) {
            $warnings[] = $kind === self::KIND_REFUND
                ? Craft::t('exactly', 'The order has no credit note in Exact Online yet, so the refund line has nothing to match. It waits until there is one.')
                : Craft::t('exactly', 'The order has no invoice in Exact Online yet, so the payment line has nothing to match. It waits until there is one.');
        } else {
            $warnings[] = Craft::t('exactly', 'The invoice number “{number}” is not numeric, so the line carries no OurRef and Exact will not match it automatically.', ['number' => $number]);
        }

        $lines = [$line];
        $fee = null;

        if ($kind === self::KIND_PAYMENT && $settings->recordProcessorFees) {
            $fee = $this->processorFee($transaction);

            if ($fee !== null) {
                $feeAccount = $plugin->getLedger()->resolveAccountId($settings->paymentFeeGlAccountCode);

                if ($feeAccount === null) {
                    $warnings[] = Craft::t('exactly', 'A processor fee of {fee} was found, but no fee GL account is set, so it was left off the entry.', ['fee' => number_format($fee, 2)]);
                    $fee = null;
                } else {
                    $lines[] = [
                        'GLAccount' => $feeAccount,
                        'AmountFC' => round(-$in * $fee, 2),
                        'Date' => $date,
                        'Description' => mb_substr(Craft::t('exactly', 'Fee {reference}', ['reference' => $reference]), 0, self::MAX_REFERENCE),
                    ];
                }
            }
        }

        $entryType = $settings->paymentEntryType === 'cash' ? 'cash' : 'bank';

        return [
            'payload' => [
                'JournalCode' => $journal,
                'Currency' => $currency !== '' ? $currency : null,
                $entryType === 'cash' ? 'CashEntryLines' : 'BankEntryLines' => $lines,
            ],
            'endpoint' => $entryType === 'cash' ? self::ENDPOINT_CASH : self::ENDPOINT_BANK,
            'linesEndpoint' => $entryType === 'cash' ? self::LINES_ENDPOINT_CASH : self::LINES_ENDPOINT_BANK,
            'entryType' => $entryType,
            'journal' => $journal,
            'kind' => $kind,
            'amount' => $amount,
            'fee' => $fee,
            'reference' => $reference,
            'document' => $document,
            'warnings' => $warnings,
        ];
    }

    /**
     * The dry run: what would be posted for a transaction, or why nothing would be.
     *
     * @return array{payload: ?array, endpoint: ?string, warnings: string[], error: ?string, kind: ?string, entry: ?PaymentEntry}
     */
    public function preview(Transaction $transaction): array
    {
        $division = Plugin::getInstance()->getOauth()->getDivision();
        $entry = $division !== null && $transaction->id ? $this->getEntry((int)$transaction->id, $division) : null;

        try {
            $built = $this->buildPayload($transaction);

            return [
                'payload' => $built['payload'],
                'endpoint' => $built['endpoint'],
                'warnings' => $built['warnings'],
                'error' => null,
                'kind' => $built['kind'],
                'entry' => $entry,
            ];
        } catch (Throwable $e) {
            return ['payload' => null, 'endpoint' => null, 'warnings' => [], 'error' => $e->getMessage(), 'kind' => $this->kindOf($transaction), 'entry' => $entry];
        }
    }

    /**
     * The line description: unique to the transaction, short enough for Exact, and readable by a
     * bookkeeper looking for it from either side — the order and the gateway's own reference.
     */
    public static function referenceFor(Transaction $transaction, string $kind): string
    {
        $order = $transaction->getOrder();
        $orderRef = $order instanceof Order
            ? Plugin::getInstance()->getDocuments()->orderNumber($order)
            : (string)$transaction->orderId;
        // The hash is Commerce's own and unique per transaction; the gateway reference is not
        // always present, and is not always unique.
        $hash = substr((string)$transaction->hash, 0, 10);

        // Not translated: it is data in the merchant's books, and must not change language with
        // whichever user ran the queue — and it is what the duplicate lookup searches for.
        $prefix = $kind === self::KIND_REFUND ? 'Refund ' : '';

        return mb_substr($prefix . mb_substr($orderRef, 0, 32) . ' ' . $hash, 0, self::MAX_REFERENCE);
    }

    /**
     * The transaction's calendar date in the site's time zone. A payment at 23:30 in Amsterdam is
     * that day's payment, not the next day's in UTC.
     */
    public static function localDate(Transaction $transaction): DateTime
    {
        $date = $transaction->dateCreated ? clone $transaction->dateCreated : new DateTime();

        return $date->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
    }

    // Processor fees (the same reader as Zo's)
    // -------------------------------------------------------------------------

    /**
     * The fee the payment processor kept, in the transaction's currency, or null.
     *
     * Read from the stored gateway response only — never a second call to the gateway:
     *
     * - **Stripe** stores a balance transaction (minor units, except zero-decimal currencies) when
     *   the charge was expanded. Commerce Stripe usually stores the payment intent with an
     *   unexpanded charge, so most Stripe stores read no fee here; a handler on
     *   `EVENT_DEFINE_PROCESSOR_FEE` can supply it.
     * - **PayPal Checkout** (Orders v2) carries `seller_receivable_breakdown.paypal_fee`.
     * - **PayPal Express** (NVP) sends `PAYMENTINFO_0_FEEAMT` or `FEEAMT`.
     *
     * A fee in another currency than the transaction's is ignored rather than converted, and so is
     * one that is not less than the payment: that is a misread, not a fee.
     */
    public function processorFee(Transaction $transaction): ?float
    {
        $fee = $this->feeFromResponse($transaction->response, (string)($transaction->currency ?: $transaction->paymentCurrency));
        $amount = abs((float)$transaction->amount);

        if ($fee !== null && ($fee < 0 || ($amount > 0 && $fee >= $amount))) {
            $fee = null;
        }

        if ($this->hasEventHandlers(self::EVENT_DEFINE_PROCESSOR_FEE)) {
            $event = new ProcessorFeeEvent(['transaction' => $transaction, 'fee' => $fee]);
            $this->trigger(self::EVENT_DEFINE_PROCESSOR_FEE, $event);
            $fee = $event->fee;
        }

        return $fee !== null && $fee > 0 ? round($fee, 2) : null;
    }

    /**
     * @param mixed $response the transaction's stored response — JSON, an array, or nothing
     */
    public function feeFromResponse(mixed $response, string $currency): ?float
    {
        if (is_string($response)) {
            $response = Json::decodeIfJson($response);
        }

        if (!is_array($response)) {
            return null;
        }

        $currency = strtolower($currency);

        // Stripe: a balance transaction, wherever the charge happens to sit.
        $charges = [$response, $response['latest_charge'] ?? null];

        foreach ((array)($response['charges']['data'] ?? []) as $charge) {
            $charges[] = $charge;
        }

        foreach ($charges as $charge) {
            $balance = is_array($charge) ? ($charge['balance_transaction'] ?? null) : null;

            if (!is_array($balance) || !isset($balance['fee']) || !is_numeric($balance['fee'])) {
                continue;
            }

            $feeCurrency = strtolower((string)($balance['currency'] ?? ''));

            if ($currency !== '' && $feeCurrency !== '' && $feeCurrency !== $currency) {
                return null;
            }

            $divisor = in_array($feeCurrency ?: $currency, self::ZERO_DECIMAL_CURRENCIES, true) ? 1 : 100;

            return (int)$balance['fee'] / $divisor;
        }

        // PayPal Checkout (Orders v2): the capture's breakdown.
        $captures = [$response];

        foreach ((array)($response['purchase_units'] ?? []) as $unit) {
            foreach ((array)($unit['payments']['captures'] ?? []) as $capture) {
                $captures[] = $capture;
            }
        }

        foreach ($captures as $capture) {
            $paypalFee = is_array($capture) ? ($capture['seller_receivable_breakdown']['paypal_fee'] ?? null) : null;

            if (!is_array($paypalFee) || !isset($paypalFee['value']) || !is_numeric($paypalFee['value'])) {
                continue;
            }

            $feeCurrency = strtolower((string)($paypalFee['currency_code'] ?? ''));

            if ($currency !== '' && $feeCurrency !== '' && $feeCurrency !== $currency) {
                return null;
            }

            return (float)$paypalFee['value'];
        }

        // PayPal Express (NVP).
        foreach (['PAYMENTINFO_0_FEEAMT' => 'PAYMENTINFO_0_CURRENCYCODE', 'FEEAMT' => 'CURRENCYCODE'] as $feeKey => $currencyKey) {
            if (isset($response[$feeKey]) && is_numeric($response[$feeKey])) {
                $feeCurrency = strtolower((string)($response[$currencyKey] ?? ''));

                if ($currency !== '' && $feeCurrency !== '' && $feeCurrency !== $currency) {
                    return null;
                }

                return (float)$response[$feeKey];
            }
        }

        return null;
    }

    // Retries and reading
    // -------------------------------------------------------------------------

    /**
     * Run again whatever is not in Exact yet: failed rows with attempts left (after
     * `retryDelayMinutes`), rows waiting for their invoice, and rows a killed worker left
     * `sending`. Skipped rows stay skipped — they need a person.
     *
     * @return array{attempted: int, sent: int, waiting: int, failed: int}
     */
    public function retryUnsent(int $limit = 100): array
    {
        $result = ['attempted' => 0, 'sent' => 0, 'waiting' => 0, 'failed' => 0];
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->registerPayments || !Plugin::commerceIsReady()) {
            return $result;
        }

        $now = new DateTime();
        $staleCutoff = Db::prepareDateForDb((clone $now)->modify('-' . self::STALE_ATTEMPT_MINUTES . ' minutes'));
        $failed = ['and', ['status' => self::STATUS_FAILED], ['<', 'attempts', $settings->maxAttempts]];

        if ($settings->retryDelayMinutes > 0) {
            $failed[] = ['or', ['dateLastAttempt' => null], ['<=', 'dateLastAttempt', Db::prepareDateForDb((clone $now)->modify('-' . $settings->retryDelayMinutes . ' minutes'))]];
        }

        $ids = (new Query())
            ->select(['transactionId'])
            ->from([Table::PAYMENTS])
            ->where(['division' => $plugin->getOauth()->getDivision()])
            ->andWhere([
                'or',
                $failed,
                ['status' => [self::STATUS_WAITING, self::STATUS_PENDING]],
                ['and', ['status' => self::STATUS_SENDING], ['<', 'dateLastAttempt', $staleCutoff]],
            ])
            ->orderBy(['dateLastAttempt' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($limit)
            ->column();

        $transactions = Commerce::getInstance()->getTransactions();

        foreach ($ids as $id) {
            $transaction = $transactions->getTransactionById((int)$id);

            if ($transaction === null) {
                continue;
            }

            $result['attempted']++;

            try {
                $outcome = $this->register($transaction);
            } catch (RateLimitException) {
                $result['failed']++;
                break;
            }

            match ($outcome['status']) {
                self::STATUS_SENT, self::STATUS_SKIPPED => $result['sent']++,
                self::STATUS_WAITING => $result['waiting']++,
                default => $result['failed']++,
            };
        }

        return $result;
    }

    public function getEntry(int $transactionId, int $division): ?PaymentEntry
    {
        $row = (new Query())
            ->from([Table::PAYMENTS])
            ->where(['transactionId' => $transactionId, 'division' => $division])
            ->one();

        return $row ? new PaymentEntry($row) : null;
    }

    public function getEntryById(int $id): ?PaymentEntry
    {
        $row = (new Query())->from([Table::PAYMENTS])->where(['id' => $id])->one();

        return $row ? new PaymentEntry($row) : null;
    }

    /**
     * @return PaymentEntry[]
     */
    public function getEntriesForOrder(int $orderId): array
    {
        $rows = (new Query())
            ->from([Table::PAYMENTS])
            ->where(['orderId' => $orderId])
            ->orderBy(['transactionId' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map(static fn(array $row) => new PaymentEntry($row), $rows);
    }

    /**
     * Rows that need a person or are still on their way, newest first.
     *
     * @return PaymentEntry[]
     */
    public function getUnsent(int $limit = 100): array
    {
        $rows = (new Query())
            ->from([Table::PAYMENTS])
            ->where(['not', ['status' => self::STATUS_SENT]])
            ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map(static fn(array $row) => new PaymentEntry($row), $rows);
    }

    /**
     * Commerce's money against Exact's entries, one row per day, newest first.
     *
     * For each day (in the site's time zone): what Commerce captured and refunded, what Exactly
     * entered in Exact, and what is still missing — a capture with no entered row is a payment a
     * bookkeeper will otherwise have to match by hand. `orders` and `settled` say how many of that
     * day's paid orders Exact now reports as paid (from the payment-status read-back).
     *
     * @return array<int, array{date: string, captured: float, capturedCount: int, refunded: float, refundedCount: int, entered: float, enteredCount: int, refundsEntered: float, refundsEnteredCount: int, missing: int, failed: int, waiting: int, orders: int, settled: int, currencies: string[]}>
     */
    public function reconciliation(int $days = 30): array
    {
        $plugin = Plugin::getInstance();
        $division = $plugin->getOauth()->getDivision();
        $days = max(1, min(366, $days));
        $timeZone = new DateTimeZone(Craft::$app->getTimeZone());
        $since = (new DateTime('today', $timeZone))->modify('-' . ($days - 1) . ' days');

        if (!Plugin::commerceIsReady()) {
            return [];
        }

        $transactions = (new Query())
            ->select(['t.id', 't.orderId', 't.type', 't.amount', 't.currency', 't.dateCreated'])
            ->from(['t' => \craft\commerce\db\Table::TRANSACTIONS])
            ->where([
                't.status' => TransactionRecord::STATUS_SUCCESS,
                't.type' => [TransactionRecord::TYPE_CAPTURE, TransactionRecord::TYPE_PURCHASE, TransactionRecord::TYPE_REFUND],
            ])
            ->andWhere(['>=', 't.dateCreated', Db::prepareDateForDb($since)])
            ->all();

        $entries = [];

        if ($division !== null && $transactions !== []) {
            foreach ((new Query())
                ->select(['transactionId', 'status'])
                ->from([Table::PAYMENTS])
                ->where(['division' => $division, 'transactionId' => array_column($transactions, 'id')])
                ->all() as $row) {
                $entries[(int)$row['transactionId']] = (string)$row['status'];
            }
        }

        $paidInvoices = [];

        if ($division !== null && $transactions !== []) {
            foreach ((new Query())
                ->select(['orderId'])
                ->from([Table::DOCUMENTS])
                ->where([
                    'division' => $division,
                    'kind' => Document::KIND_INVOICE,
                    'orderId' => array_values(array_unique(array_column($transactions, 'orderId'))),
                    'paymentStatus' => Payments::STATUS_PAID,
                ])
                ->column() as $orderId) {
                $paidInvoices[(int)$orderId] = true;
            }
        }

        $byDay = [];

        for ($i = 0; $i < $days; $i++) {
            $day = (clone $since)->modify("+$i days")->format('Y-m-d');
            $byDay[$day] = [
                'date' => $day,
                'captured' => 0.0, 'capturedCount' => 0,
                'refunded' => 0.0, 'refundedCount' => 0,
                'entered' => 0.0, 'enteredCount' => 0,
                'refundsEntered' => 0.0, 'refundsEnteredCount' => 0,
                'missing' => 0, 'failed' => 0, 'waiting' => 0,
                'orders' => 0, 'settled' => 0,
                'currencies' => [],
                '_orders' => [],
            ];
        }

        foreach ($transactions as $row) {
            // Craft stores bare UTC; name the zone, or every row lands on the wrong side of midnight.
            $day = (new DateTime((string)$row['dateCreated'], new DateTimeZone('UTC')))->setTimezone($timeZone)->format('Y-m-d');

            if (!isset($byDay[$day])) {
                continue;
            }

            $amount = round(abs((float)$row['amount']), 2);
            $status = $entries[(int)$row['id']] ?? null;
            $isRefund = $row['type'] === TransactionRecord::TYPE_REFUND;
            $bucket = &$byDay[$day];

            if ($row['currency']) {
                $bucket['currencies'][strtoupper((string)$row['currency'])] = true;
            }

            if ($isRefund) {
                $bucket['refunded'] += $amount;
                $bucket['refundedCount']++;

                if ($status === self::STATUS_SENT) {
                    $bucket['refundsEntered'] += $amount;
                    $bucket['refundsEnteredCount']++;
                }
            } else {
                $bucket['captured'] += $amount;
                $bucket['capturedCount']++;
                $bucket['_orders'][(int)$row['orderId']] = true;

                if ($status === self::STATUS_SENT) {
                    $bucket['entered'] += $amount;
                    $bucket['enteredCount']++;
                }
            }

            match ($status) {
                self::STATUS_SENT, self::STATUS_SKIPPED => null,
                self::STATUS_FAILED => $bucket['failed']++,
                self::STATUS_WAITING => $bucket['waiting']++,
                default => $bucket['missing']++,
            };

            unset($bucket);
        }

        $rows = [];

        foreach (array_reverse($byDay) as $bucket) {
            $bucket['orders'] = count($bucket['_orders']);
            $bucket['settled'] = count(array_intersect_key($bucket['_orders'], $paidInvoices));
            $bucket['currencies'] = array_keys($bucket['currencies']);
            unset($bucket['_orders']);

            foreach (['captured', 'refunded', 'entered', 'refundsEntered'] as $key) {
                $bucket[$key] = round($bucket[$key], 2);
            }

            $rows[] = $bucket;
        }

        return $rows;
    }

    /**
     * @return int[]
     */
    private function sentTransactionIds(int $orderId, int $division): array
    {
        return array_map('intval', (new Query())
            ->select(['transactionId'])
            ->from([Table::PAYMENTS])
            ->where(['orderId' => $orderId, 'division' => $division, 'status' => self::STATUS_SENT])
            ->column());
    }

    private function update(PaymentEntry $entry, array $values): PaymentEntry
    {
        if ($entry->id === null) {
            return $entry;
        }

        $values['dateUpdated'] = Db::prepareDateForDb(new DateTime());

        Craft::$app->getDb()->createCommand()
            ->update(Table::PAYMENTS, $values, ['id' => $entry->id])
            ->execute();

        return $this->getEntryById($entry->id) ?? $entry;
    }
}
