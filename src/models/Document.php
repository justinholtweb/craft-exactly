<?php

namespace justinholtweb\exactly\models;

use Craft;
use craft\base\Model;
use craft\commerce\elements\Order;
use DateTime;

/**
 * The ledger row tying one Craft order to one Exact Online document.
 */
class Document extends Model
{
    public const KIND_INVOICE = 'invoice';
    public const KIND_CREDIT_NOTE = 'credit-note';

    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    /**
     * A push is in flight. Held only for the length of one attempt, and reclaimable after
     * `Documents::STALE_ATTEMPT_MINUTES` so a worker killed mid-push does not wedge the order
     * forever.
     */
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /*
     * Where an *order* stands with Exact, as one word, for the Orders index column and the
     * "Exact Online status" condition rule. See `Documents::orderStatuses()`.
     */
    public const ORDER_FAILED = 'failed';
    public const ORDER_CREDITED = 'credited';
    public const ORDER_PAID = 'paid';
    public const ORDER_INVOICED = 'invoiced';
    public const ORDER_IN_FLIGHT = 'inFlight';
    public const ORDER_PENDING = 'pending';
    public const ORDER_SKIPPED = 'skipped';
    public const ORDER_NONE = 'none';

    public ?int $id = null;
    public ?int $orderId = null;
    public ?int $division = null;
    public string $kind = self::KIND_INVOICE;
    public string $status = self::STATUS_PENDING;
    public ?string $orderNumber = null;
    public ?string $exactInvoiceId = null;
    public ?string $exactEntryId = null;
    public ?string $exactAccountId = null;
    public ?string $invoiceNumber = null;
    public ?string $entryNumber = null;
    /**
     * Exact's own document status: 10 draft, 20 open, 50 processed.
     */
    public ?int $exactStatus = null;
    public ?string $journal = null;
    public ?string $currency = null;
    public ?float $amount = null;
    public ?float $amountExclVat = null;
    public ?string $vatTreatment = null;
    public ?string $payloadHash = null;
    public ?string $payload = null;
    public int $attempts = 0;
    public ?string $lastError = null;
    public ?string $deliveryStatus = null;
    public ?string $paymentStatus = null;
    public ?float $amountPaid = null;
    public ?DateTime $dateInvoiced = null;
    public ?DateTime $dateSent = null;
    public ?DateTime $dateLastAttempt = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    private ?Order $_order = null;

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), [
            'dateInvoiced',
            'dateSent',
            'dateLastAttempt',
        ]);
    }

    public function getOrder(): ?Order
    {
        if ($this->_order === null && $this->orderId !== null) {
            $this->_order = Order::find()->id($this->orderId)->status(null)->one();
        }

        return $this->_order;
    }

    public function setOrder(?Order $order): void
    {
        $this->_order = $order;
    }

    public const EXACT_STATUS_DRAFT = 10;
    public const EXACT_STATUS_OPEN = 20;
    public const EXACT_STATUS_PROCESSED = 50;

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT && $this->exactInvoiceId !== null;
    }

    /**
     * Whether Exact still has this as a draft.
     *
     * A draft sales invoice exists in Exact but is not in the general ledger and does not appear
     * on the receivables list — which means "not outstanding" for a draft means "not booked", not
     * "paid". Processing it is what printing or sending it does.
     */
    public function isDraft(): bool
    {
        return $this->exactStatus !== null && $this->exactStatus < self::EXACT_STATUS_OPEN;
    }

    public function isProcessed(): bool
    {
        return $this->exactStatus !== null && $this->exactStatus >= self::EXACT_STATUS_PROCESSED;
    }

    /**
     * Whether the order has changed since the invoice was written.
     *
     * Exact will not let the invoice change once it is processed, so this is information, not an
     * offer to re-push: it tells the merchant to issue a credit note rather than wonder why the
     * numbers disagree.
     */
    public function isStale(?string $currentHash): bool
    {
        return $this->isSent()
            && $currentHash !== null
            && $this->payloadHash !== null
            && $this->payloadHash !== $currentHash;
    }

    /**
     * A short label for the CP.
     */
    public function getLabel(): string
    {
        if ($this->invoiceNumber !== null && $this->invoiceNumber !== '') {
            return $this->kind === self::KIND_CREDIT_NOTE
                ? Craft::t('exactly', 'Credit note {number}', ['number' => $this->invoiceNumber])
                : Craft::t('exactly', 'Invoice {number}', ['number' => $this->invoiceNumber]);
        }

        return Craft::t('exactly', 'Not yet invoiced');
    }

    /**
     * The CP status-light colour for this row.
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_SENT => 'green',
            self::STATUS_FAILED => 'red',
            self::STATUS_QUEUED, self::STATUS_SENDING => 'blue',
            self::STATUS_SKIPPED => 'grey',
            default => 'orange',
        };
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SENT => Craft::t('exactly', 'Sent'),
            self::STATUS_FAILED => Craft::t('exactly', 'Failed'),
            self::STATUS_QUEUED => Craft::t('exactly', 'Queued'),
            self::STATUS_SENDING => Craft::t('exactly', 'Sending'),
            self::STATUS_SKIPPED => Craft::t('exactly', 'Skipped'),
            default => Craft::t('exactly', 'Pending'),
        };
    }

    /**
     * The decoded payload, for the CP detail screen.
     */
    public function getDecodedPayload(): ?array
    {
        if ($this->payload === null || $this->payload === '') {
            return null;
        }

        $decoded = json_decode($this->payload, true);

        return is_array($decoded) ? $decoded : null;
    }
}
