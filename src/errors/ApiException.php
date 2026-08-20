<?php

namespace justinholtweb\exactly\errors;

/**
 * Something Exact Online said no to.
 *
 * `$statusCode` is the HTTP status, or 0 when the request never got that far (DNS, timeout, a
 * proxy in the way). `$isRetryable` is the interesting one: a 429 or a 5xx will very likely work
 * in ten minutes and belongs back on the queue, whereas a 400 about a missing GL account will
 * fail identically forever and belongs in front of a human.
 */
class ApiException extends \RuntimeException
{
    public int $statusCode = 0;
    public ?string $responseBody = null;
    public ?string $reason = null;

    public function __construct(
        string $message,
        int $statusCode = 0,
        ?string $responseBody = null,
        ?string $reason = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);

        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        $this->reason = $reason;
    }

    /**
     * Whether trying again later could plausibly succeed.
     *
     * 401 counts: the caller refreshes and retries once, and if the refresh itself is what failed
     * the OAuth service throws its own, clearer error instead.
     */
    public function isRetryable(): bool
    {
        return $this->statusCode === 0
            || $this->statusCode === 401
            || $this->statusCode === 408
            || $this->statusCode === 429
            || $this->statusCode >= 500;
    }
}
