<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use craft\commerce\db\Table as CommerceTable;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use justinholtweb\exactly\db\Table;
use justinholtweb\exactly\events\AlertEvent;
use justinholtweb\exactly\helpers\Ip;
use justinholtweb\exactly\models\Document;
use justinholtweb\exactly\Plugin;
use Throwable;

/**
 * Failure alerts: tell somebody when Exactly is in trouble, once, and again when it is not.
 *
 * Ported from Erpy, the reference for the connector family (see its CLAUDE.md, "Failure alerts"),
 * by way of Zo. The Documents screen already knows everything that has gone wrong. What it cannot
 * do is make a merchant look at it. Three incidents are worth interrupting somebody for:
 *
 * - **Failures** — at least `alertFailureThreshold` invoices, credit notes or payment entries
 *   failed inside the window. Every one of those is an order that is not (fully) in the books.
 *   Exactly refuses to send an invoice whose total does not reconcile, so a reconciliation
 *   problem arrives here as a failure too.
 * - **Stalled** — work that should have happened and has not: a document or payment sitting
 *   `queued`/`sending` for longer than `alertStallHours` (the queue is not running), or, with an
 *   automatic trigger, a recent order past that age with no invoice row at all.
 * - **Authentication** — Exact refused the refresh token (`invalid_grant`, or any 4xx from the
 *   token endpoint), answered a 401 after Exactly had already refreshed, or the stored connection
 *   has simply expired. Nothing is invoiced until somebody reconnects, and Exact will not say so
 *   twice.
 *
 * Each incident is a latch in `exactly_alerts`, one row per incident type, unique in the
 * database. Opening it sends one alert; it stays open — and silent — however many checks see the
 * same trouble; clearing it sends one recovery. A send is claimed with a conditional update
 * before it goes out and released if it fails, so the queue and cron checking in the same minute
 * cannot both send, and a mail outage does not swallow the alert.
 *
 * Detection runs from three places: {@see afterSync()} at the end of every push and payment entry
 * (failures, no cron needed), {@see noteAuthFailure()}/{@see noteAuthSuccess()} from `Api` and
 * `Oauth` (authentication, immediately), and {@see check()} from `exactly/alerts/check`,
 * `exactly/sync/retry` and `exactly/sync/maintenance` — the only paths that can notice an incident
 * *clearing*, or a stall, when nothing is being pushed.
 *
 * Bodies are redacted ({@see redact()}): an alert goes to a mailbox and a chat channel, both of
 * which outlive the credential they would otherwise quote.
 */
class Alerts extends Component
{
    public const INCIDENT_FAILURES = 'failures';
    public const INCIDENT_STALLED = 'stalled';
    public const INCIDENT_AUTH = 'auth';

    public const STATE_OK = 'ok';
    public const STATE_OPEN = 'open';

    /** @event AlertEvent before an alert or a recovery is sent; set `isValid` false to swallow it. */
    public const EVENT_BEFORE_NOTIFY = 'beforeNotify';

    /** How often one process re-checks that an authentication incident is still clear. */
    private const AUTH_SUCCESS_TTL = 60;

    /**
     * How far back the "order with no invoice" half of the stall check looks. Older orders are a
     * backfill's business, not an alert's: a store that switched automatic invoicing on last week
     * must not be paged about the year before.
     */
    private const STALL_LOOKBACK_DAYS = 7;

    /**
     * The HTTP client for the webhook. Null means a curl-only client built per send; tests put a
     * Guzzle `MockHandler` client here. Never a client on Guzzle's default stack: it can hand a
     * request to PHP's stream wrapper, which ignores `CURLOPT_RESOLVE` — the address pin.
     */
    public ?ClientInterface $webhookClient = null;

    /** When this process last confirmed authentication is clear. */
    private ?int $authClearAt = null;

    /**
     * @return array<string,string> incident => label
     */
    public static function incidents(): array
    {
        return [
            self::INCIDENT_FAILURES => Craft::t('exactly', 'Orders failing to reach Exact Online'),
            self::INCIDENT_STALLED => Craft::t('exactly', 'Invoicing has stalled'),
            self::INCIDENT_AUTH => Craft::t('exactly', 'Exact Online refused the connection'),
        ];
    }

    public static function incidentLabel(string $incident): string
    {
        return self::incidents()[$incident] ?? $incident;
    }

    // ---------------------------------------------------------------------------------------
    // Detection
    // ---------------------------------------------------------------------------------------

    /**
     * Evaluate every incident and send whatever is owed.
     *
     * Nothing is checked until Exactly has app credentials and a stored connection: an install
     * that was never connected is not an incident, and disconnecting is neither a new incident nor
     * a recovery.
     *
     * @param string[]|null $only limit to these incidents
     * @return array<int,array{incident:string,state:string,transition:?string,notified:bool,detail:?string}>
     */
    public function check(?array $only = null): array
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->hasCredentials() || $plugin->getOauth()->getConnection() === null) {
            return [];
        }

        $results = [];

        foreach (array_keys(self::incidents()) as $incident) {
            if ($only !== null && !in_array($incident, $only, true)) {
                continue;
            }

            $row = $this->row($incident);
            [$open, $detail] = $this->measure($incident, $row);
            $results[] = $this->transition($incident, $open, $detail);
        }

        return $results;
    }

    /**
     * The end of every push and payment entry. Fail-open: an alert that cannot be worked out must
     * never be the reason a push reports a failure.
     */
    public function afterSync(): void
    {
        try {
            $this->check([self::INCIDENT_FAILURES]);
        } catch (Throwable $e) {
            Craft::warning('Exactly could not evaluate alerts after a push: ' . $e->getMessage(), 'exactly');
        }
    }

    /**
     * Exact refused the credentials: a final 401, or a refresh token it would not honour.
     *
     * Recorded as a signal rather than measured, because nothing else remembers it — the log can
     * be switched off, and a refused token never makes a document row.
     */
    public function noteAuthFailure(string $reason): void
    {
        try {
            $this->ensureRow(self::INCIDENT_AUTH);

            Craft::$app->getDb()->createCommand()->update(Table::ALERTS, [
                'signalledAt' => $this->now(),
                'signalClearedAt' => null,
                'detail' => $this->redact($reason),
                'dateUpdated' => $this->now(),
            ], ['incident' => self::INCIDENT_AUTH])->execute();

            $this->authClearAt = null;

            $this->check([self::INCIDENT_AUTH]);
        } catch (Throwable $e) {
            Craft::warning('Exactly could not record an authentication failure: ' . $e->getMessage(), 'exactly');
        }
    }

    /**
     * An authenticated request succeeded. Called by `Api` on every success, so it costs at most
     * one conditional UPDATE per minute per process, and usually nothing.
     */
    public function noteAuthSuccess(): void
    {
        if ($this->authClearAt !== null && time() - $this->authClearAt < self::AUTH_SUCCESS_TTL) {
            return;
        }

        $this->authClearAt = time();

        try {
            $cleared = Craft::$app->getDb()->createCommand()->update(Table::ALERTS, [
                'signalClearedAt' => $this->now(),
                'dateUpdated' => $this->now(),
            ], [
                'and',
                ['incident' => self::INCIDENT_AUTH, 'signalClearedAt' => null],
                ['not', ['signalledAt' => null]],
            ])->execute();

            if ($cleared > 0) {
                $this->check([self::INCIDENT_AUTH]);
            }
        } catch (Throwable $e) {
            Craft::warning('Exactly could not clear an authentication alert: ' . $e->getMessage(), 'exactly');
        }
    }

    /**
     * Whether an incident is happening right now, and a redacted line saying what was seen.
     *
     * @param array<string,mixed> $row
     * @return array{0:bool,1:?string}
     */
    private function measure(string $incident, array $row): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $wasOpen = ($row['state'] ?? self::STATE_OK) === self::STATE_OPEN;
        $window = max(5, $settings->alertWindowMinutes);
        $cutoff = Db::prepareDateForDb((new DateTime())->modify("-$window minutes"));

        switch ($incident) {
            case self::INCIDENT_FAILURES:
                if (!$settings->alertOnFailures) {
                    return [false, null];
                }

                $documents = (new Query())
                    ->from([Table::DOCUMENTS])
                    ->where(['status' => Document::STATUS_FAILED])
                    ->andWhere(['>=', 'dateUpdated', $cutoff]);
                $payments = (new Query())
                    ->from([Table::PAYMENTS])
                    ->where(['status' => PaymentEntries::STATUS_FAILED])
                    ->andWhere(['>=', 'dateUpdated', $cutoff]);
                $count = (int)(clone $documents)->count() + (int)(clone $payments)->count();

                // Hysteresis: it takes the threshold to open, and a whole quiet window to close.
                // Closing the moment the count dipped below the threshold would page somebody
                // twice an hour for an Exact that is refusing every fourth order.
                $open = $wasOpen ? $count > 0 : $count >= max(1, $settings->alertFailureThreshold);

                if (!$open) {
                    return [false, null];
                }

                $latestDocument = (clone $documents)
                    ->select(['kind', 'orderNumber', 'lastError', 'dateUpdated'])
                    ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])
                    ->one() ?: null;
                $latestPayment = (clone $payments)
                    ->select(['kind', 'reference', 'lastError', 'dateUpdated'])
                    ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC])
                    ->one() ?: null;

                $latest = $latestDocument;
                $label = $latestDocument !== null ? $latestDocument['kind'] . ' ' . $latestDocument['orderNumber'] : '';

                if ($latestPayment !== null && ($latest === null || (string)$latestPayment['dateUpdated'] >= (string)$latest['dateUpdated'])) {
                    $latest = $latestPayment;
                    $label = $latestPayment['kind'] . ' ' . $latestPayment['reference'];
                }

                return [true, $this->redact(Craft::t('exactly', '{count} failures in the last {window} minutes; {failed} documents and payments show as failed. Latest: {label}: {error}', [
                    'count' => $count,
                    'window' => $window,
                    'failed' => $this->standingCount(self::INCIDENT_FAILURES),
                    'label' => trim($label),
                    'error' => is_string($latest['lastError'] ?? null) && $latest['lastError'] !== '' ? $latest['lastError'] : '—',
                ]))];

            case self::INCIDENT_STALLED:
                if (!$settings->alertOnStall) {
                    return [false, null];
                }

                $hours = max(1, $settings->alertStallHours);
                $stuck = $this->stuckCount($hours);
                $unsent = $this->overdueOrderCount($hours);

                if ($stuck === 0 && $unsent === 0) {
                    return [false, null];
                }

                return [true, $this->redact(Craft::t('exactly', '{stuck} documents or payments have been waiting in the queue for more than {hours} hours, and {unsent} orders from the last {days} days are past {hours} hours with no invoice. Check that the queue is running.', [
                    'stuck' => $stuck,
                    'unsent' => $unsent,
                    'hours' => $hours,
                    'days' => self::STALL_LOOKBACK_DAYS,
                ]))];

            case self::INCIDENT_AUTH:
                if (!$settings->alertOnAuthFailure) {
                    return [false, null];
                }

                if (!empty($row['signalledAt']) && empty($row['signalClearedAt'])) {
                    return [true, $row['detail'] ?? null];
                }

                // Measured as well as signalled: a connection nobody used for thirty days expires
                // without a single refused request to say so.
                $connection = Plugin::getInstance()->getOauth()->getConnection();

                if ($connection !== null && !$connection->isUsable()) {
                    return [true, Craft::t('exactly', 'The stored Exact Online connection has expired. Nothing will be invoiced until Exactly is reconnected.')];
                }

                return [false, null];
        }

        return [false, null];
    }

    /**
     * What is still wrong regardless of when it happened — quoted in alerts and recoveries, so a
     * recovery ("nothing new for an hour") does not read as "everything is fixed".
     */
    public function standingCount(string $incident): int
    {
        return match ($incident) {
            self::INCIDENT_FAILURES => (int)(new Query())
                    ->from([Table::DOCUMENTS])
                    ->where(['status' => Document::STATUS_FAILED])
                    ->count()
                + (int)(new Query())
                    ->from([Table::PAYMENTS])
                    ->where(['status' => PaymentEntries::STATUS_FAILED])
                    ->count(),
            default => 0,
        };
    }

    /**
     * Documents and payment entries that have been `queued` or `sending` for longer than a queue
     * worker ever takes. A job that was pushed and never ran is the queue not running.
     */
    public function stuckCount(int $hours): int
    {
        $cutoff = Db::prepareDateForDb((new DateTime())->modify("-$hours hours"));

        return (int)(new Query())
                ->from([Table::DOCUMENTS])
                ->where(['status' => [Document::STATUS_QUEUED, Document::STATUS_SENDING]])
                ->andWhere(['<', 'dateUpdated', $cutoff])
                ->count()
            + (int)(new Query())
                ->from([Table::PAYMENTS])
                ->where(['status' => [PaymentEntries::STATUS_PENDING, PaymentEntries::STATUS_SENDING]])
                ->andWhere(['<', 'dateUpdated', $cutoff])
                ->count();
    }

    /**
     * Recent orders an automatic trigger should have invoiced by now and has not even recorded.
     *
     * Only for the `completed` and `paid` triggers, whose rule is a fact about the order alone; a
     * `status` trigger waits on a person moving the order, and `manual` waits on nobody. A failed
     * row is the failures incident's business, so any invoice row at all takes the order out.
     */
    public function overdueOrderCount(int $hours): int
    {
        $plugin = Plugin::getInstance();
        $trigger = $plugin->getSettings()->pushTrigger;
        $division = $plugin->getOauth()->getDivision();

        if (!in_array($trigger, ['completed', 'paid'], true) || $division === null || !Plugin::commerceIsReady()) {
            return 0;
        }

        $column = $trigger === 'paid' ? 'o.datePaid' : 'o.dateOrdered';

        return (int)(new Query())
            ->from(['o' => CommerceTable::ORDERS])
            ->innerJoin(['e' => CraftTable::ELEMENTS], '[[e.id]] = [[o.id]]')
            ->leftJoin(
                ['d' => Table::DOCUMENTS],
                '[[d.orderId]] = [[o.id]] AND [[d.division]] = :division AND [[d.kind]] = :kind',
                [':division' => $division, ':kind' => Document::KIND_INVOICE],
            )
            ->where(['d.id' => null, 'o.isCompleted' => true, 'e.dateDeleted' => null])
            ->andWhere(['<', $column, Db::prepareDateForDb((new DateTime())->modify("-$hours hours"))])
            ->andWhere(['>=', $column, Db::prepareDateForDb((new DateTime())->modify('-' . self::STALL_LOOKBACK_DAYS . ' days'))])
            ->count();
    }

    // ---------------------------------------------------------------------------------------
    // The latch
    // ---------------------------------------------------------------------------------------

    /**
     * Move the latch, then send whatever it says is owed.
     *
     * @return array{incident:string,state:string,transition:?string,notified:bool,detail:?string}
     */
    private function transition(string $incident, bool $open, ?string $detail): array
    {
        $db = Craft::$app->getDb();
        $row = $this->row($incident);
        $transition = null;
        $where = ['id' => $row['id']];

        if ($open && $row['state'] !== self::STATE_OPEN) {
            // Conditional on the old state, so two checks racing open it once.
            $won = $db->createCommand()->update(Table::ALERTS, [
                'state' => self::STATE_OPEN,
                'openedAt' => $this->now(),
                'notifiedAt' => null,
                'recoveryNotifiedAt' => null,
                'detail' => $detail,
                'dateUpdated' => $this->now(),
            ], $where + ['state' => self::STATE_OK])->execute();
            $transition = $won ? 'opened' : null;
        } elseif ($open && $detail !== null && $detail !== $row['detail']) {
            $db->createCommand()->update(Table::ALERTS, ['detail' => $detail, 'dateUpdated' => $this->now()], $where)->execute();
        } elseif (!$open && $row['state'] === self::STATE_OPEN) {
            $won = $db->createCommand()->update(Table::ALERTS, [
                'state' => self::STATE_OK,
                'recoveredAt' => $this->now(),
                'dateUpdated' => $this->now(),
            ], $where + ['state' => self::STATE_OPEN])->execute();
            $transition = $won ? 'recovered' : null;
        }

        $notified = $this->deliverOwed($incident);
        $row = $this->row($incident);

        return [
            'incident' => $incident,
            'state' => $row['state'],
            'transition' => $transition,
            'notified' => $notified,
            'detail' => $row['detail'],
        ];
    }

    /**
     * Send what the latch says is owed: an opening alert nobody has had yet, or the recovery for
     * one somebody has. Claimed by a conditional update first, released again if every channel
     * failed — so it is sent once, and a mail outage delays it rather than losing it.
     */
    private function deliverOwed(string $incident): bool
    {
        $db = Craft::$app->getDb();
        $row = $this->row($incident);
        $where = ['id' => $row['id']];

        if ($row['state'] === self::STATE_OPEN && $row['notifiedAt'] === null) {
            // A flapping connection gets one alert and one recovery per cooldown, not one each
            // per check. A reopening inside the cooldown is told about once the cooldown ends,
            // if it is still open by then.
            if ($row['quietUntil'] !== null && $row['quietUntil'] > $this->now()) {
                return false;
            }

            $claimed = $db->createCommand()->update(Table::ALERTS, ['notifiedAt' => $this->now()], $where + ['notifiedAt' => null, 'state' => self::STATE_OPEN])->execute();

            if (!$claimed) {
                return false;
            }

            if ($this->notify($incident, false, (string)$row['detail'])) {
                return true;
            }

            $db->createCommand()->update(Table::ALERTS, ['notifiedAt' => null], $where)->execute();

            return false;
        }

        // A recovery is only owed for an incident somebody was told about.
        if ($row['state'] === self::STATE_OK && $row['notifiedAt'] !== null && $row['recoveryNotifiedAt'] === null) {
            $cooldown = max(0, Plugin::getInstance()->getSettings()->alertCooldownMinutes);
            $claimed = $db->createCommand()->update(Table::ALERTS, [
                'recoveryNotifiedAt' => $this->now(),
                'quietUntil' => $cooldown > 0 ? Db::prepareDateForDb((new DateTime())->modify("+$cooldown minutes")) : null,
            ], $where + ['state' => self::STATE_OK, 'recoveryNotifiedAt' => null])->execute();

            if (!$claimed) {
                return false;
            }

            if ($this->notify($incident, true, (string)$row['detail'])) {
                return true;
            }

            $db->createCommand()->update(Table::ALERTS, [
                'recoveryNotifiedAt' => null,
                'quietUntil' => $row['quietUntil'],
            ], $where)->execute();
        }

        return false;
    }

    /**
     * The latch row, created on first sight.
     *
     * @return array<string,mixed>
     */
    private function row(string $incident): array
    {
        $this->ensureRow($incident);

        return (array)(new Query())
            ->from(Table::ALERTS)
            ->where(['incident' => $incident])
            ->one();
    }

    private function ensureRow(string $incident): void
    {
        if ((new Query())->from(Table::ALERTS)->where(['incident' => $incident])->exists()) {
            return;
        }

        try {
            Craft::$app->getDb()->createCommand()->insert(Table::ALERTS, [
                'incident' => $incident,
                'state' => self::STATE_OK,
                'dateCreated' => $this->now(),
                'dateUpdated' => $this->now(),
                'uid' => StringHelper::UUID(),
            ])->execute();
        } catch (\yii\db\IntegrityException) {
            // Another process created it between the check and the insert. The unique index is
            // the point; there is nothing to do.
        }
    }

    /**
     * Every open incident, for the widget and the console.
     *
     * @return array<int,array<string,mixed>>
     */
    public function openIncidents(): array
    {
        $rows = (new Query())
            ->from([Table::ALERTS])
            ->where(['state' => self::STATE_OPEN])
            ->orderBy(['openedAt' => SORT_DESC])
            ->all();

        return array_map(static fn(array $row) => $row + ['label' => self::incidentLabel((string)$row['incident'])], $rows);
    }

    /**
     * What the Dashboard widget shows.
     *
     * @return array{connected:bool,daysLeft:?int,counts:array<string,int>,payments:array<string,int>,uninvoiced:int,lastSent:?string,incidents:array<int,array<string,mixed>>}
     */
    public function overview(): array
    {
        $plugin = Plugin::getInstance();
        $counts = $plugin->getDocuments()->getStatusCounts();
        $lastSent = (new Query())
            ->from([Table::DOCUMENTS])
            ->where(['status' => Document::STATUS_SENT])
            ->max('dateSent');

        $uninvoiced = 0;

        try {
            if (Plugin::commerceIsReady()) {
                $uninvoiced = $plugin->getSync()->countUninvoicedOrders();
            }
        } catch (Throwable) {
            // A widget that 500s takes the whole Dashboard with it.
        }

        $payments = [];

        foreach ((new Query())->select(['status', 'total' => 'COUNT(*)'])->from([Table::PAYMENTS])->groupBy(['status'])->all() as $row) {
            $payments[(string)$row['status']] = (int)$row['total'];
        }

        $connection = $plugin->getOauth()->getConnection();

        return [
            'connected' => $plugin->getOauth()->isConnected(),
            'daysLeft' => $connection?->getDaysUntilExpiry(),
            'counts' => $counts,
            'payments' => $payments,
            'uninvoiced' => $uninvoiced,
            // A bare UTC string; the zone is named so it is not read as site-local.
            'lastSent' => is_string($lastSent) ? $lastSent : null,
            'incidents' => $this->openIncidents(),
        ];
    }

    // ---------------------------------------------------------------------------------------
    // Delivery
    // ---------------------------------------------------------------------------------------

    /**
     * Send one alert to every configured channel. True when at least one channel took it — a
     * second email because the webhook failed would be worse than a missing Slack message.
     */
    public function notify(string $incident, bool $recovered, string $detail): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = $settings->recipientList();
        $webhookUrl = trim((string)App::parseEnv($settings->alertWebhookUrl));
        $hasWebhook = $webhookUrl !== '' && !str_starts_with($webhookUrl, '$');

        if ($recipients === [] && !$hasWebhook) {
            return false;
        }

        $message = $this->compose($incident, $recovered, $detail);

        $event = new AlertEvent([
            'incident' => $incident,
            'recovered' => $recovered,
            'detail' => $message['detail'],
            'subject' => $message['subject'],
            'body' => $message['body'],
            'payload' => $this->payload($settings->alertWebhookFormat, $message),
        ]);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_NOTIFY)) {
            $this->trigger(self::EVENT_BEFORE_NOTIFY, $event);

            if (!$event->isValid) {
                return true;
            }
        }

        $sent = false;

        if ($recipients !== []) {
            try {
                $sent = Craft::$app->getMailer()->compose()
                    ->setTo($recipients)
                    ->setSubject($event->subject)
                    ->setTextBody($event->body)
                    ->send();
            } catch (Throwable $e) {
                Craft::error('Exactly could not email an alert: ' . $e->getMessage(), 'exactly');
            }
        }

        if ($hasWebhook) {
            $result = $this->postWebhook($webhookUrl, $event->payload);

            if ($result === true) {
                $sent = true;
            } else {
                Craft::error('Exactly could not post an alert webhook: ' . $result, 'exactly');
            }
        }

        return $sent;
    }

    /**
     * Send a sample through every channel, for the settings screen's "Send a test alert".
     *
     * @return array{email:?bool,webhook:bool|string|null}
     */
    public function sendTest(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $recipients = $settings->recipientList();
        $webhookUrl = trim((string)App::parseEnv($settings->alertWebhookUrl));
        $message = $this->compose('test', false, Craft::t('exactly', 'This is a test. If you can read it, failure alerts will reach you here.'));
        $result = ['email' => null, 'webhook' => null];

        if ($recipients !== []) {
            try {
                $result['email'] = Craft::$app->getMailer()->compose()
                    ->setTo($recipients)
                    ->setSubject($message['subject'])
                    ->setTextBody($message['body'])
                    ->send();
            } catch (Throwable $e) {
                Craft::error('Exactly could not email a test alert: ' . $e->getMessage(), 'exactly');
                $result['email'] = false;
            }
        }

        if ($webhookUrl !== '' && !str_starts_with($webhookUrl, '$')) {
            $result['webhook'] = $this->postWebhook($webhookUrl, $this->payload($settings->alertWebhookFormat, $message));
        }

        return $result;
    }

    /**
     * Subject, plain-text body and links for one alert.
     *
     * Plain text on purpose: it may be read on a phone at an inconvenient hour, and it should say
     * what happened and where to go — nothing that needs a rendering engine. `UrlHelper::cpUrl()`
     * rather than a hand-assembled host, because this runs from the queue and the console, where
     * there is no request to read a host from.
     *
     * @return array{subject:string,body:string,title:string,detail:string,url:string,syncUrl:string,incident:string,recovered:bool,site:string}
     */
    public function compose(string $incident, bool $recovered, string $detail): array
    {
        $site = Craft::$app->getSites()->getPrimarySite()->getName();
        $label = $incident === 'test' ? Craft::t('exactly', 'Test alert') : self::incidentLabel($incident);
        $detail = $this->redact($detail);

        $syncUrl = UrlHelper::cpUrl('exactly/documents');
        $url = match ($incident) {
            self::INCIDENT_AUTH => UrlHelper::cpUrl('settings/plugins/exactly'),
            self::INCIDENT_FAILURES => UrlHelper::cpUrl('exactly/documents', ['status' => Document::STATUS_FAILED]),
            self::INCIDENT_STALLED => UrlHelper::cpUrl('utilities/queue-manager'),
            default => $syncUrl,
        };

        $title = $recovered
            ? Craft::t('exactly', 'Recovered: {label}', ['label' => $label])
            : $label;

        $lines = [
            $recovered
                ? Craft::t('exactly', 'Exactly on {site}: this has cleared.', ['site' => $site])
                : Craft::t('exactly', 'Exactly on {site} needs attention.', ['site' => $site]),
            '',
            Craft::t('exactly', 'Incident: {label}', ['label' => $label]),
        ];

        if (!$recovered && $detail !== '') {
            $lines[] = '';
            $lines[] = $detail;
        }

        if (!$recovered && $incident === self::INCIDENT_AUTH) {
            $lines[] = '';
            $lines[] = Craft::t('exactly', 'Nothing will be invoiced until Exactly is reconnected. Open Exactly’s settings and press Connect to Exact Online.');
        }

        // "Nothing new for an hour" is not "fixed". Say what is still waiting.
        if ($recovered && $incident === self::INCIDENT_FAILURES) {
            $lines[] = '';
            $lines[] = Craft::t('exactly', 'Nothing new has failed in the last {window} minutes. {count} documents and payments still show as failed in Exactly.', [
                'window' => max(5, Plugin::getInstance()->getSettings()->alertWindowMinutes),
                'count' => $this->standingCount($incident),
            ]);
        } elseif ($recovered) {
            $lines[] = '';
            $lines[] = Craft::t('exactly', 'No action is needed.');
        }

        $lines[] = '';
        $lines[] = Craft::t('exactly', 'Open: {url}', ['url' => $url]);

        if ($url !== $syncUrl) {
            $lines[] = Craft::t('exactly', 'Documents: {url}', ['url' => $syncUrl]);
        }

        $lines[] = '';
        $lines[] = Craft::t('exactly', 'You get one message when this starts and one when it clears. Change who gets them in Exactly’s settings.');

        return [
            'subject' => '[' . $site . '] ' . $title,
            'body' => implode("\n", $lines) . "\n",
            'title' => $title,
            'detail' => $recovered ? '' : $detail,
            'url' => $url,
            'syncUrl' => $syncUrl,
            'incident' => $incident,
            'recovered' => $recovered,
            'site' => $site,
        ];
    }

    /**
     * The webhook body in the receiver's own shape.
     *
     * @param array{subject:string,body:string,title:string,detail:string,url:string,syncUrl:string,incident:string,recovered:bool,site:string} $m
     * @return array<string,mixed>
     */
    public function payload(string $format, array $m): array
    {
        $text = $m['title'] . ($m['detail'] !== '' ? "\n" . $m['detail'] : '');

        return match ($format) {
            'teams' => [
                'type' => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => array_values(array_filter([
                            ['type' => 'TextBlock', 'size' => 'Large', 'weight' => 'Bolder', 'color' => $m['recovered'] ? 'Good' : 'Attention', 'text' => $m['title'], 'wrap' => true],
                            $m['detail'] !== '' ? ['type' => 'TextBlock', 'text' => $m['detail'], 'wrap' => true] : null,
                            ['type' => 'FactSet', 'facts' => [['title' => 'Site', 'value' => $m['site']]]],
                        ])),
                        'actions' => [['type' => 'Action.OpenUrl', 'title' => Craft::t('exactly', 'Open in Craft'), 'url' => $m['url']]],
                    ],
                ]],
            ],
            'json' => [
                'event' => $m['recovered'] ? 'exactly.alert.recovered' : 'exactly.alert.opened',
                'incident' => $m['incident'],
                'site' => $m['site'],
                'title' => $m['title'],
                'detail' => $m['detail'],
                'url' => $m['url'],
                'syncUrl' => $m['syncUrl'],
                'at' => (new DateTime('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            ],
            default => [
                'text' => $text,
                'blocks' => [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*' . $m['title'] . '*' . ($m['detail'] !== '' ? "\n" . $m['detail'] : '')]],
                    ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $m['site'] . ' · <' . $m['url'] . '|' . Craft::t('exactly', 'Open in Craft') . '>']]],
                ],
            ],
        };
    }

    /**
     * Where an alert webhook may be sent, or why it may not. The family SSRF rules, as in Erpy:
     *
     * 1. `http` and `https` only, with no credentials in the URL.
     * 2. Every address the host resolves to must be public ({@see Ip::resolvePublic()}).
     * 3. The send pins the connection to those addresses with `CURLOPT_RESOLVE`, so a second
     *    lookup at connect time cannot rebind the host somewhere private.
     * 4. Redirects are never followed.
     *
     * `allowPrivateAlertWebhookHosts` (config file only) skips 2 and 3; 1 and 4 still hold.
     *
     * @return array{host:string,port:int,addresses:string[]}|string the pinned target, or the refusal
     */
    public function webhookTarget(string $url): array|string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)(is_array($parts) ? ($parts['scheme'] ?? '') : ''));
        $host = (string)(is_array($parts) ? ($parts['host'] ?? '') : '');

        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            return Craft::t('exactly', 'Only http:// and https:// webhook URLs are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return Craft::t('exactly', 'Webhook URLs may not carry a username or password.');
        }

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (Plugin::getInstance()->getSettings()->allowPrivateAlertWebhookHosts) {
            return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => []];
        }

        $addresses = Ip::resolvePublic($host);

        if ($addresses === []) {
            return Craft::t('exactly', 'That host doesn’t resolve, or resolves to a private, loopback or link-local address. Alert webhooks only go to public addresses.');
        }

        return ['host' => trim($host, '[]'), 'port' => $port, 'addresses' => $addresses];
    }

    /**
     * POST the payload. True on a 2xx, otherwise the reason — never an exception.
     *
     * @param array<string,mixed> $payload
     */
    public function postWebhook(string $url, array $payload): bool|string
    {
        $target = $this->webhookTarget($url);

        if (is_string($target)) {
            return $target;
        }

        $body = (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Exactly alerts'];
        $secret = trim((string)App::parseEnv(Plugin::getInstance()->getSettings()->alertWebhookSecret));

        if ($secret !== '' && !str_starts_with($secret, '$')) {
            $timestamp = (string)time();
            $headers['X-Exactly-Timestamp'] = $timestamp;
            $headers['X-Exactly-Signature'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        }

        $options = [
            'body' => $body,
            'headers' => $headers,
            'timeout' => 10,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'http_errors' => false,
        ];

        if ($target['addresses'] !== []) {
            // One entry per host:port, addresses comma-joined — one entry per address would leave
            // only the last one pinned.
            $options['curl'][CURLOPT_RESOLVE] = [sprintf(
                '%s:%d:%s',
                $target['host'],
                $target['port'],
                implode(',', array_map(static fn(string $ip) => str_contains($ip, ':') ? "[$ip]" : $ip, $target['addresses'])),
            )];
        }

        try {
            $client = $this->webhookClient ?? new Client(['handler' => HandlerStack::create(new CurlHandler())]);
            $response = $client->request('POST', $url, $options);
            $status = $response->getStatusCode();

            return $status >= 200 && $status < 300 ? true : 'HTTP ' . $status;
        } catch (Throwable $e) {
            // A Slack or Teams webhook URL is itself the credential; the reason ends up in logs.
            return str_replace($url, $target['host'], $e->getMessage());
        }
    }

    /**
     * Take anything secret out of a line that is about to leave the building.
     *
     * `Log` already redacts what it writes, but an alert quotes document errors that never passed
     * through it, and it goes somewhere — a mailbox, a chat channel — that outlives the
     * credential. So: the client secret and the stored access and refresh tokens by value,
     * anything shaped like a credential by pattern, tags stripped, and a length cap so a stack
     * trace cannot ride along.
     */
    public function redact(string $text): string
    {
        $plugin = Plugin::getInstance();
        $secrets = [];

        try {
            $secrets[] = $plugin->getSettings()->getParsedClientSecret();
            $connection = $plugin->getOauth()->getConnection();
            $secrets[] = (string)$connection?->accessToken;
            $secrets[] = (string)$connection?->refreshToken;
        } catch (Throwable) {
            // A connection row that cannot be decrypted still leaves the patterns below.
        }

        foreach ($secrets as $secret) {
            if (strlen($secret) >= 6) {
                $text = str_replace($secret, '••••', $text);
            }
        }

        $text = (string)preg_replace('/\b(Bearer|Basic|Token)\s+[A-Za-z0-9\-._~+\/=!]{6,}/i', '$1 ••••', $text);
        $text = (string)preg_replace(
            '/(["\']?\b(?:password|passwd|pwd|secret|client_secret|api[_-]?key|apikey|access_token|refresh_token|token|code|signature|sig)\b["\']?\s*[:=]\s*["\']?)[^"\'&\s,;}]+/i',
            '$1••••',
            $text,
        );
        $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($text)));

        return mb_strlen($text) > 500 ? mb_substr($text, 0, 499) . '…' : $text;
    }

    private function now(): string
    {
        return (string)Db::prepareDateForDb(new DateTime());
    }
}
