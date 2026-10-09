<?php

namespace justinholtweb\exactly\jobs;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\queue\BaseJob;
use justinholtweb\exactly\errors\RateLimitException;
use justinholtweb\exactly\Plugin;
use justinholtweb\exactly\services\PaymentEntries;
use yii\queue\RetryableJobInterface;

/**
 * Enter one Commerce payment or refund in Exact Online, off the request.
 *
 * Unlike `PushOrder`, this one lets the queue retry: the row is claimed under a mutex and the
 * unique index, and skipped once entered, so a retry racing the order panel or the console cannot
 * enter it twice. Only failures that could go differently next time are thrown for a retry —
 * Exact unreachable or a 5xx. A refusal or a missing journal is recorded on the row and in the
 * log, and left for a person; a payment whose invoice is not processed yet waits, and is queued
 * again by the invoice push.
 */
class RegisterPayment extends BaseJob implements RetryableJobInterface
{
    public const MAX_ATTEMPTS = 5;

    public int $transactionId;

    /**
     * Whether a rate-limited run may re-queue itself; false on the copy, so a spent daily budget
     * cannot grow an endless chain of jobs.
     */
    public bool $mayRequeue = true;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $transaction = Commerce::getInstance()->getTransactions()->getTransactionById($this->transactionId);

        if ($transaction === null) {
            return;
        }

        try {
            $result = Plugin::getInstance()->getPaymentEntries()->register($transaction);
        } catch (RateLimitException $e) {
            // Not this payment's fault. The row is `failed` with the attempt uncounted, so the
            // maintenance cron picks it up; a copy on Exact's own reset time is quicker.
            if ($this->mayRequeue) {
                Craft::$app->getQueue()->delay(max(5, $e->retryAfter))->push(new self([
                    'transactionId' => $this->transactionId,
                    'mayRequeue' => false,
                ]));
            }

            return;
        }

        if ($result['status'] === PaymentEntries::STATUS_FAILED && $result['retryable']) {
            throw new \RuntimeException(sprintf(
                'Exactly could not enter transaction %d in Exact Online: %s',
                $this->transactionId,
                $result['message'] ?? 'unknown error',
            ));
        }
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        return 300;
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        return $attempt < self::MAX_ATTEMPTS;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('exactly', 'Entering a payment in Exact Online');
    }
}
