<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\exactly\db\Table;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\Plugin;
use yii\db\IntegrityException;

/**
 * The ledger tying Craft orders to Exact Online documents.
 *
 * **This is the only place a document row is created or advanced.** The manual push, the automatic
 * push, the queue job, the console backfill and the credit-note flow all come through `claim()`
 * and `markSent()`, so the "has this order already been invoiced?" decision is made once and
 * cannot disagree with itself.
 *
 * ## Why `claim()` looks like that
 *
 * An invoice in an accounting system is not a cache entry. Writing the same order twice leaves the
 * merchant with two invoice numbers, two VAT liabilities and a receivables list that does not
 * balance — and Exact will not let them simply delete one once it is processed.
 *
 * There are three ways a store gets there, and all of them are ordinary:
 *
 * - Two queue workers pick up the same order (a retry that overlapped the original).
 * - An impatient merchant clicks "Send to Exact Online" twice.
 * - An order save fires the automatic push while a console backfill is walking the same range.
 *
 * So the unique index on `(orderId, division, kind)` is the guarantee, and `claim()` is how a
 * caller finds out whether it is the one holding it. A row that says `sent` is never re-pushed. A
 * row that is mid-flight is left alone until it goes stale, because a worker that was killed at
 * the wrong moment must not strand the order for good.
 */
class Documents extends Component
{
    /**
     * How long a `sending` row is respected before another worker may take it over.
     *
     * Long enough that a slow Exact (thirty seconds is common for a large invoice) is never
     * overtaken; short enough that a killed worker does not block the order until someone notices.
     */
    public const STALE_ATTEMPT_MINUTES = 15;

    // Reading
    // -------------------------------------------------------------------------

    public function getDocument(int $orderId, ?int $division = null, string $kind = Document::KIND_INVOICE): ?Document
    {
        $division ??= Plugin::getInstance()->getOauth()->getDivision();

        $query = (new Query())
            ->from([Table::DOCUMENTS])
            ->where(['orderId' => $orderId, 'kind' => $kind]);

        if ($division !== null) {
            $query->andWhere(['division' => $division]);
        }

        $row = $query->one();

        return $row ? new Document($row) : null;
    }

    /**
     * Every document recorded against an order, across divisions and kinds.
     *
     * @return Document[]
     */
    public function getDocumentsForOrder(int $orderId): array
    {
        $rows = (new Query())
            ->from([Table::DOCUMENTS])
            ->where(['orderId' => $orderId])
            ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return array_map(static fn(array $row) => new Document($row), $rows);
    }

    public function getDocumentById(int $id): ?Document
    {
        $row = (new Query())->from([Table::DOCUMENTS])->where(['id' => $id])->one();

        return $row ? new Document($row) : null;
    }

    /**
     * @return Document[]
     */
    public function find(array $criteria = [], int $limit = 100, int $offset = 0): array
    {
        $rows = $this->buildQuery($criteria)
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();

        return array_map(static fn(array $row) => new Document($row), $rows);
    }

    public function count(array $criteria = []): int
    {
        return (int)$this->buildQuery($criteria)->count();
    }

    /**
     * Counts by status, for the index screen's summary.
     *
     * @return array<string, int>
     */
    public function getStatusCounts(): array
    {
        $rows = (new Query())
            ->select(['status', 'total' => 'COUNT(*)'])
            ->from([Table::DOCUMENTS])
            ->groupBy(['status'])
            ->all();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string)$row['status']] = (int)$row['total'];
        }

        return $counts;
    }

    /**
     * Rows that failed and are still worth another attempt.
     *
     * "Worth another attempt" means attempts left *and* `retryDelayMinutes` since the last one, so
     * a cron running maintenance every five minutes does not hammer an Exact outage (or a closed
     * journal) every five minutes. `0` retries at the next opportunity. Every retry path — the
     * maintenance cron, `exactly/sync/retry` (with or without `--now`) and the **Retry failures**
     * button — comes through here; a person who wants one order *now* uses its own Send button.
     *
     * @return Document[]
     */
    public function getRetryable(int $limit = 50): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $query = (new Query())
            ->from([Table::DOCUMENTS])
            ->where(['status' => Document::STATUS_FAILED])
            ->andWhere(['<', 'attempts', $settings->maxAttempts]);

        if ($settings->retryDelayMinutes > 0) {
            $cutoff = (new DateTime())->modify('-' . $settings->retryDelayMinutes . ' minutes');
            $query->andWhere([
                'or',
                ['dateLastAttempt' => null],
                ['<=', 'dateLastAttempt', Db::prepareDateForDb($cutoff)],
            ]);
        }

        $rows = $query
            ->orderBy(['dateLastAttempt' => SORT_ASC])
            ->limit($limit)
            ->all();

        return array_map(static fn(array $row) => new Document($row), $rows);
    }

    private function buildQuery(array $criteria): Query
    {
        $query = (new Query())->from([Table::DOCUMENTS]);

        if (!empty($criteria['status'])) {
            $query->andWhere(['status' => $criteria['status']]);
        }

        if (!empty($criteria['kind'])) {
            $query->andWhere(['kind' => $criteria['kind']]);
        }

        if (!empty($criteria['division'])) {
            $query->andWhere(['division' => (int)$criteria['division']]);
        }

        if (!empty($criteria['search'])) {
            $search = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$criteria['search']) . '%';
            $query->andWhere([
                'or',
                ['like', 'orderNumber', $search, false],
                ['like', 'invoiceNumber', $search, false],
            ]);
        }

        return $query;
    }

    // Claiming
    // -------------------------------------------------------------------------

    /**
     * Take ownership of an order's document, or find out that somebody else has it.
     *
     * @param bool $force allow re-pushing an order that is already `sent`. Only the CP's explicit
     *                    "push again" path passes true, and it is what makes a duplicate invoice
     *                    a deliberate act rather than an accident.
     * @return array{document: Document|null, claimed: bool, reason: string|null}
     */
    public function claim(Order $order, int $division, string $kind = Document::KIND_INVOICE, bool $force = false): array
    {
        $now = new DateTime();

        // Fast path: no row at all. The unique index turns a race here into an exception rather
        // than a second invoice, which is exactly what should happen.
        try {
            Craft::$app->getDb()->createCommand()->insert(Table::DOCUMENTS, [
                'orderId' => (int)$order->id,
                'division' => $division,
                'kind' => $kind,
                'status' => Document::STATUS_SENDING,
                'orderNumber' => $this->orderNumber($order),
                'attempts' => 1,
                'dateLastAttempt' => Db::prepareDateForDb($now),
                'dateCreated' => Db::prepareDateForDb($now),
                'dateUpdated' => Db::prepareDateForDb($now),
                'uid' => StringHelper::UUID(),
            ])->execute();

            return [
                'document' => $this->getDocument((int)$order->id, $division, $kind),
                'claimed' => true,
                'reason' => null,
            ];
        } catch (IntegrityException) {
            // Somebody got there first — or this order has been pushed before. Both are handled
            // below, and neither is an error.
        }

        $document = $this->getDocument((int)$order->id, $division, $kind);

        if ($document === null) {
            // The insert failed for a reason other than the unique index.
            return [
                'document' => null,
                'claimed' => false,
                'reason' => Craft::t('exactly', 'Could not record the document.'),
            ];
        }

        if ($document->isSent() && !$force) {
            $number = $document->invoiceNumber ?? $document->exactInvoiceId;

            return [
                'document' => $document,
                'claimed' => false,
                'reason' => $kind === Document::KIND_CREDIT_NOTE
                    ? Craft::t('exactly', 'This order already has credit note {number} in Exact Online.', ['number' => $number])
                    : Craft::t('exactly', 'This order is already invoice {number} in Exact Online.', ['number' => $number]),
            ];
        }

        if ($document->status === Document::STATUS_SENDING && !$this->isStale($document)) {
            return [
                'document' => $document,
                'claimed' => false,
                'reason' => Craft::t('exactly', 'Another process is sending this order to Exact Online right now.'),
            ];
        }

        // Take it over atomically: the affected-row count is the answer. Two workers that both
        // read `failed` cannot both come out of this with a claim, because the row lock serialises
        // them and the second one no longer matches the `status` predicate.
        $affected = Craft::$app->getDb()->createCommand()->update(
            Table::DOCUMENTS,
            [
                'status' => Document::STATUS_SENDING,
                'attempts' => $document->attempts + 1,
                'dateLastAttempt' => Db::prepareDateForDb($now),
                'dateUpdated' => Db::prepareDateForDb($now),
            ],
            [
                'and',
                ['id' => $document->id],
                ['status' => $document->status],
            ],
        )->execute();

        if ($affected === 0) {
            return [
                'document' => $this->getDocument((int)$order->id, $division, $kind),
                'claimed' => false,
                'reason' => Craft::t('exactly', 'Another process claimed this order first.'),
            ];
        }

        $document->status = Document::STATUS_SENDING;
        $document->attempts++;
        $document->dateLastAttempt = $now;

        return ['document' => $document, 'claimed' => true, 'reason' => null];
    }

    private function isStale(Document $document): bool
    {
        if ($document->dateLastAttempt === null) {
            return true;
        }

        return $document->dateLastAttempt->getTimestamp() < time() - (self::STALE_ATTEMPT_MINUTES * 60);
    }

    // Advancing
    // -------------------------------------------------------------------------

    /**
     * Record a successful push.
     */
    public function markSent(Document $document, array $exactInvoice, array $payload, string $payloadHash, array $extra = []): Document
    {
        $now = new DateTime();

        $values = array_merge([
            'status' => Document::STATUS_SENT,
            'exactInvoiceId' => $this->guidOrNull($exactInvoice['InvoiceID'] ?? null),
            'exactEntryId' => $this->guidOrNull($exactInvoice['EntryID'] ?? null),
            'exactAccountId' => $this->guidOrNull($exactInvoice['OrderedBy'] ?? null),
            'invoiceNumber' => $this->stringOrNull($exactInvoice['InvoiceNumber'] ?? null),
            'entryNumber' => $this->stringOrNull($exactInvoice['EntryNumber'] ?? null),
            'exactStatus' => isset($exactInvoice['Status']) ? (int)$exactInvoice['Status'] : null,
            'journal' => $this->stringOrNull($exactInvoice['Journal'] ?? null),
            'currency' => $this->stringOrNull($exactInvoice['Currency'] ?? null),
            'amount' => isset($exactInvoice['AmountFC']) ? (float)$exactInvoice['AmountFC'] : null,
            'amountExclVat' => isset($exactInvoice['AmountFCExclVat']) ? (float)$exactInvoice['AmountFCExclVat'] : null,
            'payloadHash' => $payloadHash,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'lastError' => null,
            'dateSent' => Db::prepareDateForDb($now),
            'dateInvoiced' => $this->dateOrNull($exactInvoice['InvoiceDate'] ?? null),
        ], $extra);

        return $this->update($document, $values);
    }

    public function markFailed(Document $document, string $error, ?array $payload = null): Document
    {
        return $this->update($document, [
            'status' => Document::STATUS_FAILED,
            'lastError' => mb_substr($error, 0, 2000),
            'payload' => $payload !== null
                ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : $document->payload,
        ]);
    }

    /**
     * Atomically mark a row `queued`, unless it must not be touched.
     *
     * Never a `sent` row, and never a `sending` row that is still within the staleness window: a
     * worker may be waiting on Exact for it right now, and a `queued` row is one a new job may
     * claim — which would POST a second invoice beside the first. Like `claim()`, the answer is the
     * affected-row count of one conditional `UPDATE`, so there is no read-then-write gap.
     *
     * @param string|null $expectedStatus only transition from exactly this status
     * @return bool whether the row is now `queued` because of this call. Callers push the job
     *              only when it is true.
     */
    public function markQueued(Document $document, ?string $expectedStatus = null): bool
    {
        if ($document->id === null) {
            return false;
        }

        $staleCutoff = Db::prepareDateForDb((new DateTime())->modify('-' . self::STALE_ATTEMPT_MINUTES . ' minutes'));

        // A `sending` row is only ever taken over once it has gone stale — the claim() rule.
        $staleSending = [
            'and',
            ['status' => Document::STATUS_SENDING],
            ['or', ['dateLastAttempt' => null], ['<', 'dateLastAttempt', $staleCutoff]],
        ];

        $condition = match ($expectedStatus) {
            null => ['or', ['not in', 'status', [Document::STATUS_SENT, Document::STATUS_SENDING]], $staleSending],
            Document::STATUS_SENDING => $staleSending,
            default => ['status' => $expectedStatus],
        };

        $affected = Craft::$app->getDb()->createCommand()->update(
            Table::DOCUMENTS,
            ['status' => Document::STATUS_QUEUED, 'dateUpdated' => Db::prepareDateForDb(new DateTime())],
            ['and', ['id' => $document->id], $condition],
        )->execute();

        return $affected > 0;
    }

    /**
     * Why a row could not be queued, in the words `claim()` would use.
     */
    public function queueRefusal(Document $document): string
    {
        $fresh = $this->getDocumentById((int)$document->id) ?? $document;

        if ($fresh->isSent()) {
            $number = $fresh->invoiceNumber ?? $fresh->exactInvoiceId;

            return $fresh->kind === Document::KIND_CREDIT_NOTE
                ? Craft::t('exactly', 'This order already has credit note {number} in Exact Online.', ['number' => $number])
                : Craft::t('exactly', 'This order is already invoice {number} in Exact Online.', ['number' => $number]);
        }

        return Craft::t('exactly', 'Another process is sending this order to Exact Online right now.');
    }

    /**
     * @param string|null $expectedStatus only when the row is still in exactly this status (atomic)
     */
    public function markSkipped(Document $document, string $reason, ?string $expectedStatus = null): Document
    {
        if ($expectedStatus === null) {
            return $this->update($document, [
                'status' => Document::STATUS_SKIPPED,
                'lastError' => mb_substr($reason, 0, 2000),
            ]);
        }

        if ($document->id !== null) {
            Craft::$app->getDb()->createCommand()->update(Table::DOCUMENTS, [
                'status' => Document::STATUS_SKIPPED,
                'lastError' => mb_substr($reason, 0, 2000),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            ], ['id' => $document->id, 'status' => $expectedStatus])->execute();
        }

        return $this->getDocumentById((int)$document->id) ?? $document;
    }

    /**
     * Release a claim without counting the attempt — used when nothing reached Exact (a
     * precondition failed, or Exact refused the call on its rate limit), so the attempt should not
     * count against the retry budget and the row must not sit in `sending` until it goes stale.
     */
    public function release(Document $document, string $status = Document::STATUS_PENDING, ?string $reason = null): Document
    {
        $values = [
            'status' => $status,
            'attempts' => max(0, $document->attempts - 1),
        ];

        if ($reason !== null) {
            $values['lastError'] = mb_substr($reason, 0, 2000);
        }

        return $this->update($document, $values);
    }

    /**
     * Record Exact's current document status (10 draft, 20 open, 50 processed), as re-read by the
     * payment sync. The value stored at creation is always a draft, so without this a processed
     * invoice could never be told apart from one nobody has booked.
     */
    public function recordExactStatus(Document $document, int $exactStatus): Document
    {
        if ($document->exactStatus === $exactStatus) {
            return $document;
        }

        return $this->update($document, ['exactStatus' => $exactStatus]);
    }

    public function recordPayment(Document $document, ?float $amountPaid, string $status): Document
    {
        return $this->update($document, [
            'amountPaid' => $amountPaid,
            'paymentStatus' => $status,
        ]);
    }

    public function recordDelivery(Document $document, string $status): Document
    {
        return $this->update($document, ['deliveryStatus' => mb_substr($status, 0, 32)]);
    }

    /**
     * Forget a row. The Exact document, of course, stays — an issued invoice is not Craft's to
     * retract, and this only ever means "stop tracking it here".
     */
    public function delete(int $id): bool
    {
        return (bool)Craft::$app->getDb()->createCommand()
            ->delete(Table::DOCUMENTS, ['id' => $id])
            ->execute();
    }

    private function update(Document $document, array $values): Document
    {
        if ($document->id === null) {
            return $document;
        }

        $values['dateUpdated'] = Db::prepareDateForDb(new DateTime());

        Craft::$app->getDb()->createCommand()
            ->update(Table::DOCUMENTS, $values, ['id' => $document->id])
            ->execute();

        return $this->getDocumentById($document->id) ?? $document;
    }

    /**
     * The order identifier the merchant recognises, as configured.
     */
    public function orderNumber(Order $order): string
    {
        return match (Plugin::getInstance()->getSettings()->orderNumberSource) {
            'number' => (string)$order->number,
            'shortNumber' => (string)$order->getShortNumber(),
            'id' => (string)$order->id,
            default => (string)($order->reference ?: $order->getShortNumber()),
        };
    }

    private function guidOrNull(mixed $value): ?string
    {
        return \justinholtweb\exactly\helpers\Odata::isGuid($value) ? (string)$value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? mb_substr((string)$value, 0, 64) : null;
    }

    private function dateOrNull(mixed $value): ?string
    {
        $date = \justinholtweb\exactly\helpers\Odata::parseDate($value);

        return $date !== null ? Db::prepareDateForDb($date) : null;
    }
}
