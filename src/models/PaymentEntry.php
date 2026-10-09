<?php

namespace justinholtweb\exactly\models;

use Craft;
use craft\base\Model;
use DateTime;
use justinholtweb\exactly\services\PaymentEntries;

/**
 * One Commerce payment or refund, and where it stands as an Exact Online bank or cash entry.
 */
class PaymentEntry extends Model
{
    public ?int $id = null;
    public ?int $transactionId = null;
    public ?int $orderId = null;
    public ?int $division = null;
    public string $kind = PaymentEntries::KIND_PAYMENT;
    public string $status = PaymentEntries::STATUS_PENDING;
    public ?int $documentId = null;
    public ?string $invoiceNumber = null;
    public ?string $gatewayHandle = null;
    public ?string $entryType = null;
    public ?string $journal = null;
    public ?string $currency = null;
    public ?float $amount = null;
    public ?float $fee = null;
    public ?string $reference = null;
    public ?string $exactEntryId = null;
    public ?string $entryNumber = null;
    public ?string $payload = null;
    public int $attempts = 0;
    public ?string $lastError = null;
    public ?DateTime $dateTransaction = null;
    public ?DateTime $dateSent = null;
    public ?DateTime $dateLastAttempt = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['dateTransaction', 'dateSent', 'dateLastAttempt']);
    }

    public function isSent(): bool
    {
        return $this->status === PaymentEntries::STATUS_SENT;
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            PaymentEntries::STATUS_SENT => 'green',
            PaymentEntries::STATUS_FAILED => 'red',
            PaymentEntries::STATUS_SENDING => 'blue',
            PaymentEntries::STATUS_WAITING => 'yellow',
            PaymentEntries::STATUS_SKIPPED => 'grey',
            default => 'orange',
        };
    }

    public function getStatusLabel(): string
    {
        return PaymentEntries::statusLabels()[$this->status] ?? $this->status;
    }

    public function getKindLabel(): string
    {
        return $this->kind === PaymentEntries::KIND_REFUND
            ? Craft::t('exactly', 'Refund')
            : Craft::t('exactly', 'Payment');
    }

    public function getDecodedPayload(): ?array
    {
        if ($this->payload === null || $this->payload === '') {
            return null;
        }

        $decoded = json_decode($this->payload, true);

        return is_array($decoded) ? $decoded : null;
    }
}
