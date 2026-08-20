<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use GuzzleHttp\Client;
use justinholtweb\exactly\db\Table;
use justinholtweb\exactly\models\Connection;
use justinholtweb\exactly\models\LogEntry;
use justinholtweb\exactly\Plugin;
use yii\base\Exception;

/**
 * The OAuth 2.0 connection to Exact Online.
 *
 * ## Why this class is more careful than it looks
 *
 * Exact Online's access token lasts **ten minutes**, and its refresh token is **single use**: every
 * refresh returns a new refresh token and invalidates the one that bought it. That combination is
 * unforgiving on a busy store.
 *
 * Two queue workers that both notice an expired token and both refresh with the same stored value
 * do not simply race — the second one presents a token Exact has already burned, gets
 * `invalid_grant`, and the connection is *dead* until a human re-authorises it in the CP. On a
 * store pushing invoices from a queue that is not a rare interleaving; it is what happens the
 * first time two orders complete in the same minute.
 *
 * So every refresh runs inside a named mutex, and the first thing it does after taking the lock is
 * **re-read the row**. Whoever lost the race finds a perfectly good token waiting and makes no
 * request at all.
 *
 * Tokens are encrypted with the Craft security key and stored in the database, never in project
 * config — they rotate constantly, and project config is a file that gets committed.
 */
class Oauth extends Component
{
    public const AUTH_PATH = '/api/oauth2/auth';
    public const TOKEN_PATH = '/api/oauth2/token';

    /**
     * Exact's refresh token is documented as lasting 30 days. It is not returned with an expiry,
     * so the deadline is computed and stored to drive the "reconnect soon" warning.
     */
    public const REFRESH_TOKEN_DAYS = 30;

    /**
     * Seconds to wait for another process to finish refreshing before giving up on the lock.
     * A token request to Exact takes well under a second; ten is generous and still bounded.
     */
    public const REFRESH_LOCK_TIMEOUT = 10;

    public const STATE_SESSION_KEY = 'exactly.oauthState';

    private ?Connection $_connection = null;
    private bool $_loaded = false;

    // Authorisation
    // -------------------------------------------------------------------------

    /**
     * The URL to send an admin to in order to authorise the app.
     *
     * `force_login=0` matters: without it Exact makes an already-signed-in user type their
     * password again, which reads as "the connection is broken" every time it is refreshed.
     */
    public function getAuthorizationUrl(string $state): string
    {
        $settings = Plugin::getInstance()->getSettings();

        $query = http_build_query([
            'client_id' => $settings->getParsedClientId(),
            'redirect_uri' => $settings->getRedirectUri(),
            'response_type' => 'code',
            'state' => $state,
            'force_login' => '0',
        ]);

        return $settings->getBaseUrl() . self::AUTH_PATH . '?' . $query;
    }

    /**
     * Mint and remember a state value, so the callback can prove it started here.
     */
    public function generateState(): string
    {
        $state = StringHelper::UUID();

        Craft::$app->getSession()->set(self::STATE_SESSION_KEY, $state);

        return $state;
    }

    /**
     * Consume the remembered state. Returns false if it is missing or does not match, which is
     * the whole point — an unsolicited callback must not be able to swap the connection.
     */
    public function verifyState(?string $state): bool
    {
        $expected = Craft::$app->getSession()->get(self::STATE_SESSION_KEY);
        Craft::$app->getSession()->remove(self::STATE_SESSION_KEY);

        return is_string($expected) && $expected !== '' && hash_equals($expected, (string)$state);
    }

    /**
     * Trade an authorization code for a token pair and store the connection.
     *
     * @throws Exception if Exact refuses the exchange
     */
    public function exchangeCode(string $code): Connection
    {
        $settings = Plugin::getInstance()->getSettings();

        $body = $this->requestToken([
            'grant_type' => 'authorization_code',
            'client_id' => $settings->getParsedClientId(),
            'client_secret' => $settings->getParsedClientSecret(),
            'code' => $code,
            'redirect_uri' => $settings->getRedirectUri(),
        ], 'oauth.connect');

        $connection = $this->getConnection(true) ?? new Connection([
            'connectionKey' => $settings->getConnectionKey(),
            'baseUrl' => $settings->getBaseUrl(),
        ]);

        $connection->connectionKey = $settings->getConnectionKey();
        $connection->baseUrl = $settings->getBaseUrl();
        $connection->dateConnected = new DateTime();

        $this->applyTokenResponse($connection, $body);
        $this->saveConnection($connection);

        $this->_connection = $connection;
        $this->_loaded = true;

        return $connection;
    }

    // Reading the connection
    // -------------------------------------------------------------------------

    /**
     * The stored connection for the configured app + region, or null if there is none.
     */
    public function getConnection(bool $forceReload = false): ?Connection
    {
        if ($this->_loaded && !$forceReload) {
            return $this->_connection;
        }

        $this->_loaded = true;
        $this->_connection = $this->readConnection();

        return $this->_connection;
    }

    public function isConnected(): bool
    {
        $connection = $this->getConnection();

        return $connection !== null && $connection->isUsable();
    }

    /**
     * A usable access token, refreshing first if it has to.
     *
     * @throws Exception when there is nothing to refresh — the caller is expected to surface
     *                   "reconnect in Settings" rather than retry.
     */
    public function getAccessToken(): string
    {
        $connection = $this->getConnection();

        if ($connection === null) {
            throw new Exception(Craft::t('exactly', 'Exactly is not connected to Exact Online yet.'));
        }

        if ($connection->accessTokenIsUsable()) {
            return (string)$connection->accessToken;
        }

        $connection = $this->refresh();

        return (string)$connection->accessToken;
    }

    /**
     * The division every call should address.
     */
    public function getDivision(): ?int
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->division > 0) {
            return $settings->division;
        }

        return $this->getConnection()?->division;
    }

    // Refreshing
    // -------------------------------------------------------------------------

    /**
     * Refresh the access token, exactly once, however many callers ask at the same moment.
     *
     * @throws Exception
     */
    public function refresh(): Connection
    {
        $settings = Plugin::getInstance()->getSettings();
        $mutex = Craft::$app->getMutex();
        $lockName = 'exactly.refresh.' . $settings->getConnectionKey();

        $locked = $mutex->acquire($lockName, self::REFRESH_LOCK_TIMEOUT);

        try {
            // Re-read *inside* the lock. If another process refreshed while this one was waiting,
            // the stored refresh token has already been spent and using it again would kill the
            // connection — but the access token it bought is sitting right here.
            $connection = $this->getConnection(true);

            if ($connection === null) {
                throw new Exception(Craft::t('exactly', 'Exactly is not connected to Exact Online yet.'));
            }

            if ($connection->accessTokenIsUsable()) {
                return $connection;
            }

            if (!$locked) {
                // Waited the full timeout and still nothing usable. Refreshing anyway would be the
                // exact race this lock exists to prevent.
                throw new Exception(Craft::t('exactly', 'Timed out waiting for another process to refresh the Exact Online token.'));
            }

            if (!$connection->canRefresh()) {
                throw new Exception(Craft::t('exactly', 'The Exact Online connection has expired. Reconnect it in Exactly’s settings.'));
            }

            $body = $this->requestToken([
                'grant_type' => 'refresh_token',
                'client_id' => $settings->getParsedClientId(),
                'client_secret' => $settings->getParsedClientSecret(),
                'refresh_token' => (string)$connection->refreshToken,
            ], 'oauth.refresh');

            $this->applyTokenResponse($connection, $body);
            $this->saveConnection($connection);

            $this->_connection = $connection;

            return $connection;
        } finally {
            if ($locked) {
                $mutex->release($lockName);
            }
        }
    }

    // Division & identity
    // -------------------------------------------------------------------------

    /**
     * Record which division and user the connection belongs to.
     */
    public function updateIdentity(array $me): void
    {
        $connection = $this->getConnection();

        if ($connection === null) {
            return;
        }

        $connection->division = isset($me['CurrentDivision']) ? (int)$me['CurrentDivision'] : $connection->division;
        $connection->userName = isset($me['FullName']) ? (string)$me['FullName'] : $connection->userName;
        $connection->userEmail = isset($me['Email']) ? (string)$me['Email'] : $connection->userEmail;
        $connection->divisionName = isset($me['DivisionCustomerName'])
            ? (string)$me['DivisionCustomerName']
            : $connection->divisionName;

        $this->saveConnection($connection);
    }

    /**
     * Stash the rate-limit budget from the last response, so the CP and the queue can both see it
     * without spending a call to find out.
     */
    public function recordRateLimits(array $limits): void
    {
        $connection = $this->getConnection();

        if ($connection === null || $connection->id === null) {
            return;
        }

        $connection->dailyLimit = $limits['dailyLimit'] ?? $connection->dailyLimit;
        $connection->dailyRemaining = $limits['dailyRemaining'] ?? $connection->dailyRemaining;
        $connection->minutelyLimit = $limits['minutelyLimit'] ?? $connection->minutelyLimit;
        $connection->minutelyRemaining = $limits['minutelyRemaining'] ?? $connection->minutelyRemaining;
        $connection->minutelyReset = $limits['minutelyReset'] ?? $connection->minutelyReset;
        $connection->dateLastCall = new DateTime();

        // A partial update: nothing here may touch the tokens, which another process may be
        // rotating at this very moment.
        try {
            Craft::$app->getDb()->createCommand()->update(Table::CONNECTIONS, [
                'dailyLimit' => $connection->dailyLimit,
                'dailyRemaining' => $connection->dailyRemaining,
                'minutelyLimit' => $connection->minutelyLimit,
                'minutelyRemaining' => $connection->minutelyRemaining,
                'minutelyReset' => $connection->minutelyReset,
                'dateLastCall' => Db::prepareDateForDb($connection->dateLastCall),
                'dateUpdated' => Db::prepareDateForDb(new DateTime()),
            ], ['id' => $connection->id])->execute();
        } catch (\Throwable $e) {
            Craft::warning('Exactly could not record rate limits: ' . $e->getMessage(), __METHOD__);
        }
    }

    // Disconnecting
    // -------------------------------------------------------------------------

    /**
     * Forget the tokens. Exact has no revocation endpoint, so this is a local disconnect — the app
     * stays authorised in the merchant's Exact account until they remove it there.
     */
    public function disconnect(): void
    {
        $settings = Plugin::getInstance()->getSettings();

        Craft::$app->getDb()->createCommand()
            ->delete(Table::CONNECTIONS, ['connectionKey' => $settings->getConnectionKey()])
            ->execute();

        $this->_connection = null;
        $this->_loaded = true;

        Plugin::getInstance()->getLog()->write('oauth.disconnect', [
            'summary' => Craft::t('exactly', 'Disconnected from Exact Online'),
        ]);
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * @throws Exception
     */
    private function requestToken(array $params, string $action): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->hasCredentials()) {
            throw new Exception(Craft::t('exactly', 'Add your Exact Online client ID and secret first.'));
        }

        $url = $settings->getBaseUrl() . self::TOKEN_PATH;
        $started = microtime(true);

        try {
            $client = Craft::createGuzzleClient(['timeout' => 20]);
            $response = $client->post($url, ['form_params' => $params]);
            $raw = (string)$response->getBody();
            $body = json_decode($raw, true);

            if (!is_array($body) || !isset($body['access_token'], $body['refresh_token'])) {
                throw new Exception(Craft::t('exactly', 'Exact Online returned a token response that could not be read.'));
            }

            Plugin::getInstance()->getLog()->write($action, [
                'method' => 'POST',
                'endpoint' => self::TOKEN_PATH,
                'statusCode' => $response->getStatusCode(),
                'durationMs' => (int)round((microtime(true) - $started) * 1000),
                'summary' => Craft::t('exactly', 'Token acquired'),
                // `redact()` in the Log service strips the secret and the tokens; the grant type
                // is the only part worth keeping.
                'request' => http_build_query($params),
                'response' => $raw,
            ]);

            return $body;
        } catch (\Throwable $e) {
            $message = $this->describeTokenError($e);

            Plugin::getInstance()->getLog()->write($action, [
                'level' => LogEntry::LEVEL_ERROR,
                'method' => 'POST',
                'endpoint' => self::TOKEN_PATH,
                'durationMs' => (int)round((microtime(true) - $started) * 1000),
                'summary' => $message,
                'message' => $message,
                'request' => http_build_query($params),
            ]);

            throw new Exception($message, 0, $e);
        }
    }

    /**
     * Exact's token endpoint answers with `{"error":"invalid_grant","error_description":"…"}`,
     * which is worth surfacing verbatim: `invalid_grant` on a refresh means the token was already
     * spent, and that is the one error whose fix (reconnect) is different from every other.
     */
    private function describeTokenError(\Throwable $e): string
    {
        if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
            $raw = (string)$e->getResponse()->getBody();
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $error = (string)($decoded['error'] ?? '');
                $description = (string)($decoded['error_description'] ?? '');

                if ($error === 'invalid_grant') {
                    return Craft::t('exactly', 'Exact Online rejected the refresh token. It has either expired or already been used — reconnect Exactly in its settings.');
                }

                if ($description !== '' || $error !== '') {
                    return trim($error . ' ' . $description);
                }
            }

            return Craft::t('exactly', 'Exact Online answered {status} to the token request.', [
                'status' => $e->getResponse()->getStatusCode(),
            ]);
        }

        return $e->getMessage();
    }

    private function applyTokenResponse(Connection $connection, array $body): void
    {
        $expiresIn = (int)($body['expires_in'] ?? 600);

        $connection->accessToken = (string)$body['access_token'];
        // Rotated on every refresh. Storing it is not optional.
        $connection->refreshToken = (string)$body['refresh_token'];
        $connection->accessTokenExpires = (new DateTime())->setTimestamp(time() + $expiresIn);
        $connection->refreshTokenExpires = (new DateTime())->modify('+' . self::REFRESH_TOKEN_DAYS . ' days');
    }

    private function readConnection(): ?Connection
    {
        $settings = Plugin::getInstance()->getSettings();

        try {
            $row = (new Query())
                ->from([Table::CONNECTIONS])
                ->where(['connectionKey' => $settings->getConnectionKey()])
                ->one();
        } catch (\Throwable) {
            // The table does not exist yet (installing, or mid-migration).
            return null;
        }

        if (!$row) {
            return null;
        }

        $security = Craft::$app->getSecurity();

        foreach (['accessToken', 'refreshToken'] as $key) {
            if (empty($row[$key])) {
                $row[$key] = null;
                continue;
            }

            try {
                $row[$key] = $security->decryptByKey(base64_decode((string)$row[$key]));
            } catch (\Throwable) {
                // The security key changed. The token is unrecoverable, so present the connection
                // as needing reauthorisation rather than throwing on every page load.
                $row[$key] = null;
            }
        }

        return new Connection($row);
    }

    private function saveConnection(Connection $connection): void
    {
        $security = Craft::$app->getSecurity();
        $now = new DateTime();

        $values = [
            'connectionKey' => $connection->connectionKey,
            'baseUrl' => $connection->baseUrl,
            'accessToken' => $connection->accessToken !== null
                ? base64_encode($security->encryptByKey($connection->accessToken))
                : null,
            'refreshToken' => $connection->refreshToken !== null
                ? base64_encode($security->encryptByKey($connection->refreshToken))
                : null,
            'accessTokenExpires' => $connection->accessTokenExpires ? Db::prepareDateForDb($connection->accessTokenExpires) : null,
            'refreshTokenExpires' => $connection->refreshTokenExpires ? Db::prepareDateForDb($connection->refreshTokenExpires) : null,
            'division' => $connection->division,
            'divisionName' => $connection->divisionName,
            'userName' => $connection->userName,
            'userEmail' => $connection->userEmail,
            'dateConnected' => $connection->dateConnected ? Db::prepareDateForDb($connection->dateConnected) : null,
            'dateUpdated' => Db::prepareDateForDb($now),
        ];

        $db = Craft::$app->getDb();

        if ($connection->id !== null) {
            $db->createCommand()->update(Table::CONNECTIONS, $values, ['id' => $connection->id])->execute();

            return;
        }

        $values['dateCreated'] = Db::prepareDateForDb($now);
        $values['uid'] = StringHelper::UUID();

        $db->createCommand()->insert(Table::CONNECTIONS, $values)->execute();

        // Read the id back rather than trusting `getLastInsertID()`, which needs the sequence
        // name on Postgres and the raw table name on MySQL. `connectionKey` is unique, so this
        // is exact on both.
        $connection->id = (int)(new Query())
            ->select(['id'])
            ->from([Table::CONNECTIONS])
            ->where(['connectionKey' => $connection->connectionKey])
            ->scalar();
    }
}
