<?php

namespace justinholtweb\exactly\models;

use craft\base\Model;
use DateTime;

/**
 * A live OAuth connection to one Exact Online administration.
 *
 * Hydrated from `{{%exactly_connections}}` with the tokens already decrypted, so an instance of
 * this is a secret — it is never rendered, logged or handed to Twig.
 */
class Connection extends Model
{
    public ?int $id = null;
    public string $connectionKey = '';
    public string $baseUrl = '';
    public ?string $accessToken = null;
    public ?string $refreshToken = null;
    public ?DateTime $accessTokenExpires = null;
    public ?DateTime $refreshTokenExpires = null;
    public ?int $division = null;
    public ?string $divisionName = null;
    public ?string $userName = null;
    public ?string $userEmail = null;
    public ?int $dailyLimit = null;
    public ?int $dailyRemaining = null;
    /**
     * Epoch **milliseconds** at which the daily bucket refills (`X-RateLimit-Reset`).
     */
    public ?int $dailyReset = null;
    public ?int $minutelyLimit = null;
    public ?int $minutelyRemaining = null;
    /**
     * Epoch **milliseconds** at which the minutely bucket refills. Exact reports it that way and
     * treating it as seconds parks the queue until 1970.
     */
    public ?int $minutelyReset = null;
    public ?DateTime $dateConnected = null;
    public ?DateTime $dateLastCall = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), [
            'accessTokenExpires',
            'refreshTokenExpires',
            'dateConnected',
            'dateLastCall',
        ]);
    }

    /**
     * Whether the access token is usable right now.
     *
     * The 30-second margin is not politeness. An access token lives ten minutes, and a push that
     * starts with 4 seconds left on the clock fails halfway through — after the account lookup and
     * before the invoice — which is the one failure mode this plugin most wants to avoid.
     */
    public function accessTokenIsUsable(): bool
    {
        if ($this->accessToken === null || $this->accessToken === '') {
            return false;
        }

        if ($this->accessTokenExpires === null) {
            return false;
        }

        return $this->accessTokenExpires->getTimestamp() - 30 > time();
    }

    /**
     * Whether the connection can still be refreshed.
     *
     * Exact's refresh token lasts 30 days *from the last use*. An install that stops selling for a
     * month comes back to a dead connection and has to re-authorise, so this is surfaced in the CP
     * rather than discovered on the next order.
     */
    public function canRefresh(): bool
    {
        if ($this->refreshToken === null || $this->refreshToken === '') {
            return false;
        }

        if ($this->refreshTokenExpires === null) {
            return true;
        }

        return $this->refreshTokenExpires->getTimestamp() > time();
    }

    public function isUsable(): bool
    {
        return $this->accessTokenIsUsable() || $this->canRefresh();
    }

    /**
     * Days left on the refresh token, for the warning on the settings screen.
     */
    public function getDaysUntilExpiry(): ?int
    {
        if ($this->refreshTokenExpires === null) {
            return null;
        }

        $seconds = $this->refreshTokenExpires->getTimestamp() - time();

        return (int)floor($seconds / 86400);
    }

    /**
     * Seconds until the minutely rate-limit bucket refills, or 0 if there is budget left.
     */
    public function getSecondsUntilRateLimitReset(): int
    {
        if ($this->minutelyRemaining === null || $this->minutelyRemaining > 0) {
            return 0;
        }

        if ($this->minutelyReset === null || $this->minutelyReset <= 0) {
            return 0;
        }

        // Milliseconds, not seconds.
        $seconds = (int)ceil($this->minutelyReset / 1000) - time() + 1;

        return max(0, min(120, $seconds));
    }

    /**
     * Seconds until the daily budget refills, or 0 when a call may be made.
     *
     * Only a *known, future* reset time blocks. Once it has passed, or when none was ever stored
     * (a row from before the reset was recorded), the call goes ahead: the response is the only
     * thing that can refresh `dailyRemaining`, so refusing pre-emptively without an end time would
     * refuse for ever. If the budget really is still spent, Exact answers 429 with fresh headers.
     */
    public function getSecondsUntilDailyReset(): int
    {
        if ($this->dailyRemaining === null || $this->dailyRemaining > 0) {
            return 0;
        }

        if ($this->dailyReset === null || $this->dailyReset <= 0) {
            return 0;
        }

        // Milliseconds, not seconds.
        return max(0, (int)ceil($this->dailyReset / 1000) - time() + 1);
    }

    /**
     * A redacted description, safe to put in a log or on screen.
     */
    public function describe(): string
    {
        $parts = array_filter([
            $this->divisionName ?: ($this->division !== null ? "Division {$this->division}" : null),
            $this->userEmail,
        ]);

        return implode(' — ', $parts);
    }
}
