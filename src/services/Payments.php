<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use justinholtweb\exactly\errors\ApiException;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\Plugin;

/**
 * Reading payment status back out of Exact Online (Pro).
 *
 * Once a merchant pays their bookkeeping through Exact — bank feeds, manual matching, a direct
 * debit run — Exact is the system that knows whether an invoice is settled. Craft usually does
 * not: a Commerce order paid by invoice is "completed" with a €0 payment recorded against it.
 *
 * The trick is not to ask about each invoice. `read/financial/ReceivablesList` returns only what
 * is **outstanding**, so one paged read answers the question for every invoice at once: anything
 * Exactly sent that is not on that list has been paid. Asking per invoice would spend the whole
 * per-minute budget on a shop with fifty open invoices.
 */
class Payments extends Component
{
    public const ENDPOINT = 'read/financial/ReceivablesList';

    public const STATUS_PAID = 'paid';
    public const STATUS_OUTSTANDING = 'outstanding';
    public const STATUS_PARTIAL = 'partial';
    /**
     * Exact still has the invoice as a draft, so there is nothing to reconcile against yet.
     */
    public const STATUS_DRAFT = 'draft';

    /**
     * Reconcile every sent document against Exact's open items.
     *
     * @return array{checked: int, paid: int, outstanding: int, errors: string[]}
     */
    public function sync(int $limit = 500): array
    {
        $plugin = Plugin::getInstance();
        $result = ['checked' => 0, 'paid' => 0, 'outstanding' => 0, 'errors' => []];

        if (!$plugin->isPro() || !$plugin->getSettings()->paymentWriteback) {
            return $result;
        }

        try {
            $outstanding = $this->getOutstanding();
        } catch (ApiException $e) {
            $result['errors'][] = $e->getMessage();

            return $result;
        }

        $documents = $plugin->getDocuments()->find([
            'status' => Document::STATUS_SENT,
            'kind' => Document::KIND_INVOICE,
        ], $limit);

        foreach ($documents as $document) {
            if ($document->invoiceNumber === null || $document->invoiceNumber === '') {
                continue;
            }

            // A draft is not on the receivables list *because it is not in the ledger yet*, not
            // because it has been paid. Reporting one as paid would be worse than reporting
            // nothing at all.
            if ($document->isDraft()) {
                $plugin->getDocuments()->recordPayment($document, null, self::STATUS_DRAFT);
                continue;
            }

            $result['checked']++;
            $open = $outstanding[$document->invoiceNumber] ?? null;

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
