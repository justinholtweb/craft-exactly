<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\exactly\db\Table;
use justinholtweb\exactly\models\LogEntry;
use justinholtweb\exactly\Plugin;

/**
 * The connection log.
 *
 * An accounting integration that silently books nothing is otherwise pure guesswork: the merchant
 * sees no invoices, Exact reports no errors because it was never asked anything, and the order
 * screen looks fine. Every call Exactly makes lands here with its payload and its status.
 *
 * Request bodies are redacted on the way in — the OAuth exchange posts the client secret, and a
 * log that anyone with CP access can read is not the place for it.
 */
class Log extends Component
{
    /**
     * Bodies longer than this are truncated. A 300-line invoice payload is already past the point
     * anybody scrolls.
     */
    public const MAX_PAYLOAD = 65535;

    /**
     * Body keys whose values never get stored.
     */
    private const REDACT_KEYS = [
        'client_secret',
        'access_token',
        'refresh_token',
        'code',
        'authorization',
        'Authorization',
    ];

    /**
     * @param array{
     *     level?: string,
     *     method?: string|null,
     *     endpoint?: string|null,
     *     statusCode?: int|null,
     *     durationMs?: int|null,
     *     orderId?: int|null,
     *     division?: int|null,
     *     summary?: string|null,
     *     message?: string|null,
     *     request?: string|null,
     *     response?: string|null,
     * } $data
     */
    public function write(string $action, array $data = []): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->loggingEnabled) {
            return;
        }

        $keepPayloads = $settings->logPayloads;

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::LOG, [
                'action' => $action,
                'level' => $data['level'] ?? LogEntry::LEVEL_INFO,
                'method' => $data['method'] ?? null,
                'endpoint' => isset($data['endpoint']) ? mb_substr((string)$data['endpoint'], 0, 255) : null,
                'statusCode' => $data['statusCode'] ?? null,
                'durationMs' => $data['durationMs'] ?? null,
                'orderId' => $data['orderId'] ?? null,
                'division' => $data['division'] ?? null,
                'summary' => isset($data['summary']) ? mb_substr((string)$data['summary'], 0, 255) : null,
                'message' => $data['message'] ?? null,
                'request' => $keepPayloads ? $this->truncate(self::redact($data['request'] ?? null)) : null,
                'response' => $keepPayloads ? $this->truncate(self::redact($data['response'] ?? null)) : null,
                'dateCreated' => Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\Throwable $e) {
            // The log is diagnostics, never the point. Failing to write one must not take down the
            // push it was describing.
            Craft::warning('Exactly could not write a log entry: ' . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Blank out credentials and tokens wherever they appear, in JSON or in a form-encoded body.
     */
    public static function redact(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        foreach (self::REDACT_KEYS as $key) {
            // JSON: "client_secret":"…"
            $body = preg_replace(
                '~("' . preg_quote($key, '~') . '"\s*:\s*")([^"]*)(")~i',
                '$1[redacted]$3',
                $body
            ) ?? $body;

            // Form-encoded: client_secret=…
            $body = preg_replace(
                '~(\b' . preg_quote($key, '~') . '=)([^&\s]+)~i',
                '$1[redacted]',
                $body
            ) ?? $body;
        }

        // Bearer tokens, wherever they turn up.
        return preg_replace('~(Bearer\s+)[A-Za-z0-9!._\-+/=]+~i', '$1[redacted]', $body) ?? $body;
    }

    /**
     * @return LogEntry[]
     */
    public function getEntries(array $criteria = [], int $limit = 200): array
    {
        $query = (new Query())
            ->select([
                'id', 'action', 'level', 'method', 'endpoint', 'statusCode', 'durationMs',
                'orderId', 'division', 'summary', 'dateCreated', 'uid',
            ])
            ->from([Table::LOG])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit);

        if (!empty($criteria['action'])) {
            $query->andWhere(['action' => $criteria['action']]);
        }

        if (!empty($criteria['level'])) {
            $query->andWhere(['level' => $criteria['level']]);
        }

        if (!empty($criteria['orderId'])) {
            $query->andWhere(['orderId' => (int)$criteria['orderId']]);
        }

        return array_map(static fn(array $row) => new LogEntry($row), $query->all());
    }

    public function getEntryById(int $id): ?LogEntry
    {
        $row = (new Query())->from([Table::LOG])->where(['id' => $id])->one();

        return $row ? new LogEntry($row) : null;
    }

    /**
     * The distinct actions present in the log, for the filter menu.
     *
     * @return string[]
     */
    public function getActions(): array
    {
        return (new Query())
            ->select(['action'])
            ->distinct()
            ->from([Table::LOG])
            ->orderBy(['action' => SORT_ASC])
            ->column();
    }

    public function prune(?int $days = null): int
    {
        $days = $days ?? Plugin::getInstance()->getSettings()->logRetentionDays;

        if ($days <= 0) {
            return 0;
        }

        $cutoff = (new DateTime())->modify("-{$days} days");

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG, [
            '<', 'dateCreated', Db::prepareDateForDb($cutoff),
        ])->execute();
    }

    public function clear(): int
    {
        return (int)Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    }

    public function count(array $criteria = []): int
    {
        $query = (new Query())->from([Table::LOG]);

        if (!empty($criteria['level'])) {
            $query->andWhere(['level' => $criteria['level']]);
        }

        return (int)$query->count();
    }

    private function truncate(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        if (strlen($payload) <= self::MAX_PAYLOAD) {
            return $payload;
        }

        return mb_strcut($payload, 0, self::MAX_PAYLOAD) . "\n…[truncated]";
    }
}
