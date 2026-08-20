<?php

namespace justinholtweb\exactly\models;

use craft\base\Model;
use DateTime;

/**
 * One entry in the connection log.
 */
class LogEntry extends Model
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public ?int $id = null;
    public string $action = '';
    public string $level = self::LEVEL_INFO;
    public ?string $method = null;
    public ?string $endpoint = null;
    public ?int $statusCode = null;
    public ?int $durationMs = null;
    public ?int $orderId = null;
    public ?int $division = null;
    public ?string $summary = null;
    public ?string $message = null;
    public ?string $request = null;
    public ?string $response = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function getStatusColor(): string
    {
        return match ($this->level) {
            self::LEVEL_ERROR => 'red',
            self::LEVEL_WARNING => 'orange',
            default => 'green',
        };
    }

    /**
     * Pretty-print a stored JSON body, leaving anything that is not JSON alone.
     */
    public function prettyBody(?string $body): ?string
    {
        if ($body === null || trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return $body;
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
