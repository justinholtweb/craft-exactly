<?php

namespace justinholtweb\exactly\twig;

use craft\commerce\elements\Order;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\Plugin;
use yii\base\Component;

/**
 * `craft.exactly` — read-only, for order confirmation pages and customer account areas.
 *
 * Nothing here pushes anything. A template render is not a place to write to a merchant's
 * accounting system, and a page that could would be one crawler away from a duplicate invoice.
 */
class ExactlyVariable extends Component
{
    /**
     * The Exact Online invoice recorded against an order, if there is one.
     */
    public function document(Order|int|null $order, string $kind = Document::KIND_INVOICE): ?Document
    {
        $orderId = $order instanceof Order ? $order->id : $order;

        if ($orderId === null) {
            return null;
        }

        return Plugin::getInstance()->getDocuments()->getDocument((int)$orderId, null, $kind);
    }

    /**
     * Every document for an order, invoices and credit notes alike.
     *
     * @return Document[]
     */
    public function documents(Order|int|null $order): array
    {
        $orderId = $order instanceof Order ? $order->id : $order;

        if ($orderId === null) {
            return [];
        }

        return Plugin::getInstance()->getDocuments()->getDocumentsForOrder((int)$orderId);
    }

    /**
     * The Exact invoice number for an order, for "Invoice {{ craft.exactly.invoiceNumber(order) }}".
     */
    public function invoiceNumber(Order|int|null $order): ?string
    {
        return $this->document($order)?->invoiceNumber;
    }

    /**
     * Whether Exact reports the invoice as paid. `null` when there is no invoice, or when payment
     * write-back is switched off and nothing has been asked.
     */
    public function isPaid(Order|int|null $order): ?bool
    {
        $document = $this->document($order);

        if ($document === null || $document->paymentStatus === null) {
            return null;
        }

        return $document->paymentStatus === 'paid';
    }

    /**
     * The VAT treatment Exactly worked out for an order — useful for showing a customer why their
     * invoice carries no VAT ("intra-community supply, VAT reverse-charged").
     */
    public function vatTreatment(Order|int|null $order): ?string
    {
        return $this->document($order)?->vatTreatment;
    }

    /**
     * Whether Exactly is connected. For a status widget in a client's own dashboard.
     */
    public function isConnected(): bool
    {
        return Plugin::getInstance()->getOauth()->isConnected();
    }
}
