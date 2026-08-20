<?php

namespace justinholtweb\exactly\errors;

/**
 * The minutely or daily call budget for this administration is spent.
 *
 * Exact allows a fixed number of calls per minute and per day *per app per administration*, and
 * the ceiling is low enough that a backfill of a few hundred orders will hit it. This is not an
 * error in the ordinary sense — it is a "come back in {retryAfter} seconds", and the queue is
 * expected to do exactly that rather than burn an attempt.
 */
class RateLimitException extends ApiException
{
    public int $retryAfter = 60;

    public function setRetryAfter(int $seconds): static
    {
        $this->retryAfter = max(1, $seconds);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function isRetryable(): bool
    {
        return true;
    }
}
