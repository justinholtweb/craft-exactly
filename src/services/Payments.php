<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use justinholtweb\exactly\errors\ApiException;
use justinholtweb\exactly\helpers\Odata;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\Plugin;

/**
 * Reading payment status back out of Exact Online.
 *
 * Once a merchant pays their bookkeeping through Exact — bank feeds, manual matching, a direct
 * debit run — Exact is the system that knows whether an invoice is settled. Craft usually does
 * not: a Commerce order paid by invoice is "completed" with a €0 payment recorded against it.
 *
 * Two reads, both batched, so a shop with fifty open invoices spends a handful of calls rather
 * than the per-minute budget:
 *
 * 1. **The invoice's own status.** Exact hands back a *draft* (`Status` 10) when the invoice is
 *    created, and that is all the ledger row knows until something asks again. A draft is not in
 *    the general ledger, so it can be neither paid nor outstanding. Every invoice not yet known to
 *    be processed is re-read here, `STATUS_BATCH` to a call, with an `$filter` on their IDs.
 * 2. **`read/financial/ReceivablesList`**, which returns only what is **outstanding**. One paged
 *    read answers the question for every invoice at once: a *processed* invoice that is not on
 *    that list has been paid.
 *
 * "Not on the receivables list" only means "paid" for a processed invoice. For a draft (or an
 * invoice whose status could not be read) it means "not booked", and reporting that as paid would
 * be worse than reporting nothing at all.
 */
class Payments extends Component
{
    public const ENDPOINT = 'read/financial/ReceivablesList';
    public const INVOICES_ENDPOINT = 'salesinvoice/SalesInvoices';

    /**
     * Invoice IDs per status read. Each is ~60 characters of `$filter`, so twenty keeps the URL
     * comfortably short while turning fifty invoices into three calls instead of fifty.
     */
    public const STATUS_BATCH = 20;

    /**
     * The batch size actually used; a property so it can be configured on the component.
     */
    public int $statusBatch = self::STATUS_BATCH;

    public const STATUS_PAID = 'paid';
    public const STATUS_OUTSTANDING = 'outstanding';
    public const STATUS_PARTIAL = 'partial';
    /**
     * Exact has not booked the invoice yet (draft or open, not processed), so there is nothing to
     * reconcile against.
     */
    public const STATUS_DRAFT = 'draft';
    /**
     * A credit note has been sent against the invoice. Matching the two in Exact takes the invoice
     * off the receivables list, which is not the customer paying — so it is never reported paid,
     * and the order is never moved to the paid status.
     */
    public const STATUS_CREDITED = 'credited';

    /**
     * Reconcile every sent document against Exact's open items.
     *
     * @return array{checked: int, paid: int, outstanding: int, draft: int, credited: int, errors: string[]}
     */
    public function sync(int $limit = 500): array
    {
        $plugin = Plugin::getInstance();
        $result = ['checked' => 0, 'paid' => 0, 'outstanding' => 0, 'draft' => 0, 'credited' => 0, 'errors' => []];

        if (!$plugin->getSettings()->paymentWriteback) {
            return $result;
        }

        $documents = $plugin->getDocuments()->find([
            'status' => Document::STATUS_SENT,
            'kind' => Document::KIND_INVOICE,
        ], $limit);

        $documents = array_values(array_filter(
            $documents,
            static fn(Document $document) => $document->invoiceNumber !== null && $document->invoiceNumber !== '',
        ));

        if ($documents === []) {
            return $result;
        }

        // Per-batch errors come back alongside whatever the other batches did read: a failure in
        // one batch must not throw away statuses already fetched. A document whose status is
        // unknown is never reported paid below.
        ['documents' => $documents, 'errors' => $statusErrors] = $this->refreshStatuses($documents);
        array_push($result['errors'], ...$statusErrors);

        $credited = $this->getCreditedOrderIds($documents);

        try {
            $outstanding = $this->getOutstanding();
        } catch (ApiException $e) {
            $result['errors'][] = $e->getMessage();

            return $result;
        }

        foreach ($documents as $document) {
            $open = $outstanding[$document->invoiceNumber] ?? null;

            if ($open === null && !$document->isProcessed()) {
                // Off the receivables list because it is not in the ledger yet, not because it has
                // been paid.
                if ($document->paymentStatus !== self::STATUS_DRAFT) {
                    $plugin->getDocuments()->recordPayment($document, null, self::STATUS_DRAFT);
                }

                $result['draft']++;
                continue;
            }

            $result['checked']++;

            if ($open === null && isset($credited[(int)$document->orderId . ':' . (int)$document->division])) {
                if ($document->paymentStatus !== self::STATUS_CREDITED) {
                    $plugin->getDocuments()->recordPayment($document, null, self::STATUS_CREDITED);
                }

                $result['credited']++;
                continue;
            }

            if ($open === null) {
                if ($document->paymentStatus !== self::STATUS_PAID) {
                    $plugin->getDocuments()->recordPayment($document, $document->amount, self::STATUS_PAID);
                    $this->onPaid($document);
                }

                $result['paid']++;
                continue;
            }

            $status = $document->amount !== null && abs($open) < abs($document->amount) - 0.005
                ? self::STATUS_PARTIAL
                : self::STATUS_OUTSTANDING;

            $paidSoFar = $document->amount !== null ? round($document->amount - $open, 2) : null;

            $plugin->getDocuments()->recordPayment($document, $paidSoFar, $status);
            $result['outstanding']++;
        }

        return $result;
    }

    /**
     * Re-read Exact's status for every invoice not yet known to be processed, and store it.
     *
     * A processed invoice cannot go back to draft, so those cost nothing. The rest are read
     * `STATUS_BATCH` at a time with one `$filter` per call.
     *
     * Never throws on an API error: each failed batch adds its message to `errors`, and the
     * statuses every other batch read are kept.
     *
     * @param Document[] $documents
     * @return array{documents: Document[], errors: string[]} the same documents, with
     *         `exactStatus` brought up to date wherever it could be read
     */
    public function refreshStatuses(array $documents): array
    {
        $plugin = Plugin::getInstance();
        $stale = [];
        $errors = [];

        foreach ($documents as $index => $document) {
            if (!$document->isProcessed() && Odata::isGuid($document->exactInvoiceId)) {
                $stale[strtolower((string)$document->exactInvoiceId)] = $index;
            }
        }

        foreach (array_chunk(array_keys($stale), max(1, $this->statusBatch)) as $ids) {
            $filter = implode(' or ', array_map(
                static fn(string $id) => 'InvoiceID eq ' . Odata::guid($id),
                $ids,
            ));

            try {
                $rows = $plugin->getApi()->getAll(self::INVOICES_ENDPOINT, [
                    'filter' => $filter,
                    'select' => 'InvoiceID,Status',
                ], 5, ['action' => 'payments.status', 'summary' => 'Read invoice status']);
            } catch (ApiException $e) {
                $errors[] = $e->getMessage();
                continue;
            }

            foreach ($rows as $row) {
                $id = strtolower(trim((string)($row['InvoiceID'] ?? '')));

                if (!isset($stale[$id]) || !isset($row['Status']) || !is_numeric($row['Status'])) {
                    continue;
                }

                $index = $stale[$id];
                $documents[$index] = $plugin->getDocuments()->recordExactStatus($documents[$index], (int)$row['Status']);
            }
        }

        return ['documents' => $documents, 'errors' => array_values(array_unique($errors))];
    }

    /**
     * `orderId:division` for every order among these documents that has a sent credit note.
     *
     * @param Document[] $documents
     * @return array<string, true>
     */
    private function getCreditedOrderIds(array $documents): array
    {
        $orderIds = array_values(array_unique(array_map(static fn(Document $document) => (int)$document->orderId, $documents)));

        if ($orderIds === []) {
            return [];
        }

        $rows = (new \craft\db\Query())
            ->select(['orderId', 'division'])
            ->from([\justinholtweb\exactly\db\Table::DOCUMENTS])
            ->where([
                'orderId' => $orderIds,
                'kind' => Document::KIND_CREDIT_NOTE,
                'status' => Document::STATUS_SENT,
            ])
            ->all();

        $credited = [];

        foreach ($rows as $row) {
            $credited[(int)$row['orderId'] . ':' . (int)$row['division']] = true;
        }

        return $credited;
    }

    /**
     * Invoice number => amount still owed.
     *
     * @return array<string, float>
     * @throws ApiException
     */
    public function getOutstanding(): array
    {
        $rows = Plugin::getInstance()->getApi()->getAll(self::ENDPOINT, [
            'select' => 'HID,InvoiceNumber,Amount,CurrencyCode,DueDate,AccountName',
        ], 40, ['action' => 'payments.receivables', 'summary' => 'Read open items']);

        $outstanding = [];

        foreach ($rows as $row) {
            $number = trim((string)($row['InvoiceNumber'] ?? ''));

            if ($number === '') {
                continue;
            }

            $outstanding[$number] = (float)($row['Amount'] ?? 0);
        }

        return $outstanding;
    }

    /**
     * Move the Craft order to the configured "paid" status, if there is one.
     *
     * Deliberately quiet about failure: an order status that cannot be applied is a configuration
     * problem, not a reason to make the payment sync itself look broken.
     */
    private function onPaid(Document $document): void
    {
        $handle = trim(Plugin::getInstance()->getSettings()->paidStatusHandle);

        if ($handle === '' || !Plugin::commerceIsReady()) {
            return;
        }

        $order = $document->getOrder();

        if (!$order instanceof Order) {
            return;
        }

        $status = Commerce::getInstance()->getOrderStatuses()->getOrderStatusByHandle($handle, $order->storeId);

        if ($status === null || (int)$order->orderStatusId === (int)$status->id) {
            return;
        }

        $order->orderStatusId = $status->id;
        $order->message = Craft::t('exactly', 'Exact Online reports invoice {number} as paid.', [
            'number' => $document->invoiceNumber,
        ]);

        try {
            Craft::$app->getElements()->saveElement($order, false);
        } catch (\Throwable $e) {
            Craft::warning('Exactly could not move order ' . $order->id . ' to the paid status: ' . $e->getMessage(), __METHOD__);
        }
    }
}
