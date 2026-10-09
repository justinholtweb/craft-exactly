<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use justinholtweb\exactly\errors\ApiException;
use justinholtweb\exactly\errors\RateLimitException;
use justinholtweb\exactly\helpers\Odata;
use justinholtweb\exactly\models\LogEntry;
use justinholtweb\exactly\Plugin;
use Psr\Http\Message\ResponseInterface;

/**
 * The HTTP layer for Exact Online's REST API.
 *
 * Everything that talks to Exact goes through here, so the awkward parts of the protocol are
 * handled once:
 *
 * - The `d` envelope, and `__next` paging (there is no `$skip`-based paging that works reliably
 *   on the bulk endpoints).
 * - `Accept: application/json`. Without it Exact answers **XML**, which is the single most common
 *   reason a first integration attempt "returns nothing".
 * - `Prefer: return=representation`, so a POST hands back the entity it created — including the
 *   invoice number, which is otherwise a second call to go and fetch.
 * - The rate-limit headers, recorded after every response including failures.
 * - 401 handling: one forced refresh and one retry, because a token can expire between the
 *   expiry check and the socket write.
 */
class Api extends Component
{
    public const API_PREFIX = '/api/v1';

    /**
     * Endpoints that must *not* carry a division segment. Addressing `Me` per division is a 404,
     * which is a confusing thing to hit while trying to find out what your division is.
     */
    public const DIVISIONLESS = [
        'current/Me',
    ];

    /**
     * Seconds before a call is abandoned. Exact is not fast, and a sales invoice POST with fifty
     * lines regularly takes several seconds.
     */
    public int $timeout = 30;

    /**
     * The HTTP client. Null means Craft's own (`Craft::createGuzzleClient()`); the test suite puts
     * a Guzzle `MockHandler` client here, so the real request, retry and logging code runs against
     * a scripted Exact rather than a live division.
     */
    public ?ClientInterface $client = null;

    // Verbs
    // -------------------------------------------------------------------------

    /**
     * @param array<string, string|int> $params OData options, without the `$` (`filter`, `select`,
     *                                          `top`, `orderby`, `expand`)
     * @throws ApiException
     */
    public function get(string $endpoint, array $params = [], array $context = []): mixed
    {
        return $this->request('GET', $endpoint, null, $params, $context);
    }

    /**
     * Follow `__next` until the collection runs out.
     *
     * @throws ApiException
     */
    public function getAll(string $endpoint, array $params = [], int $maxPages = 20, array $context = []): array
    {
        $results = [];
        $pages = 0;
        $url = null;

        do {
            $raw = $url === null
                ? $this->request('GET', $endpoint, null, $params, $context, true)
                : $this->request('GET', $url, null, [], $context, true);

            $page = Odata::unwrap($raw);

            if (is_array($page)) {
                // A single entity came back where a collection was expected; still a valid answer.
                $results = array_merge($results, array_is_list($page) ? $page : [$page]);
            }

            $url = Odata::nextUrl($raw);
            $pages++;
        } while ($url !== null && $pages < $maxPages);

        return $results;
    }

    /**
     * @throws ApiException
     */
    public function post(string $endpoint, array $body, array $context = []): mixed
    {
        return $this->request('POST', $endpoint, $body, [], $context);
    }

    /**
     * @throws ApiException
     */
    public function put(string $endpoint, array $body, array $context = []): mixed
    {
        return $this->request('PUT', $endpoint, $body, [], $context);
    }

    /**
     * @throws ApiException
     */
    public function delete(string $endpoint, array $context = []): mixed
    {
        return $this->request('DELETE', $endpoint, null, [], $context);
    }

    /**
     * The first row of a filtered collection, or null.
     *
     * @throws ApiException
     */
    public function findOne(string $endpoint, string $filter, array $select = [], array $context = []): ?array
    {
        $params = ['filter' => $filter, 'top' => 1];

        if ($select) {
            $params['select'] = implode(',', $select);
        }

        $result = Odata::unwrap($this->request('GET', $endpoint, null, $params, $context, true));

        if (is_array($result) && array_is_list($result)) {
            return $result[0] ?? null;
        }

        return is_array($result) ? $result : null;
    }

    // The request
    // -------------------------------------------------------------------------

    /**
     * @throws ApiException
     */
    private function request(
        string $method,
        string $endpoint,
        ?array $body,
        array $params,
        array $context,
        bool $raw = false,
        bool $isRetry = false,
    ): mixed {
        $plugin = Plugin::getInstance();
        $oauth = $plugin->getOauth();

        $this->guardRateLimit();

        try {
            $token = $oauth->getAccessToken();
        } catch (\Throwable $e) {
            throw new ApiException($e->getMessage(), 0, null, null, $e);
        }

        $url = $this->buildUrl($endpoint, $params);
        $action = $context['action'] ?? 'api';
        $started = microtime(true);
        $encodedBody = $body !== null ? Json::encode($body) : null;

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                // Without this the API answers XML.
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                // Makes POST/PUT return the saved entity instead of an empty 204.
                'Prefer' => 'return=representation',
            ],
            'timeout' => $this->timeout,
            'http_errors' => true,
        ];

        if ($encodedBody !== null) {
            $options['body'] = $encodedBody;
        }

        try {
            $client = $this->client ?? Craft::createGuzzleClient();
            $response = $client->request($method, $url, $options);

            $this->recordRateLimits($response);

            $responseBody = (string)$response->getBody();
            $decoded = $responseBody !== '' ? json_decode($responseBody, true) : null;

            $plugin->getLog()->write($action, [
                'method' => $method,
                'endpoint' => $this->logEndpoint($endpoint),
                'statusCode' => $response->getStatusCode(),
                'durationMs' => (int)round((microtime(true) - $started) * 1000),
                'orderId' => $context['orderId'] ?? null,
                'division' => $oauth->getDivision(),
                'summary' => $context['summary'] ?? ($method . ' ' . $this->logEndpoint($endpoint)),
                'request' => $encodedBody,
                'response' => $responseBody,
            ]);

            // Any authenticated 2xx is the evidence that clears an authentication alert.
            $plugin->getAlerts()->noteAuthSuccess();

            if ($decoded === null) {
                return null;
            }

            return $raw ? $decoded : Odata::unwrap($decoded);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $status = $response?->getStatusCode() ?? 0;

            if ($response !== null) {
                $this->recordRateLimits($response);
            }

            $responseBody = $response !== null ? (string)$response->getBody() : null;
            $reason = $response?->getHeaderLine('Reason') ?: null;
            $message = Odata::errorMessage($responseBody) ?? $e->getMessage();

            if ($reason) {
                $message .= ' (' . $reason . ')';
            }

            // A token can expire between the expiry check and the socket write — clock skew, a
            // slow queue worker, a long-running console command. One forced refresh, one retry.
            if ($status === 401 && !$isRetry) {
                $plugin->getLog()->write($action, [
                    'level' => LogEntry::LEVEL_WARNING,
                    'method' => $method,
                    'endpoint' => $this->logEndpoint($endpoint),
                    'statusCode' => 401,
                    'summary' => Craft::t('exactly', 'Access token rejected; refreshing and retrying once'),
                ]);

                try {
                    $oauth->refresh($token);
                } catch (\Throwable $refreshError) {
                    throw new ApiException($refreshError->getMessage(), 401, $responseBody, $reason, $refreshError);
                }

                return $this->request($method, $endpoint, $body, $params, $context, $raw, true);
            }

            // Still refused with a freshly refreshed token: the token is fine and the grant behind
            // it is not (revoked, or the app's access to this division withdrawn). Somebody has to
            // reconnect, and nothing else will say so.
            if ($status === 401) {
                $plugin->getAlerts()->noteAuthFailure(Craft::t('exactly', 'Exact Online answered 401 to a freshly refreshed token: {message}', ['message' => $message]));
            }

            $plugin->getLog()->write($action, [
                'level' => LogEntry::LEVEL_ERROR,
                'method' => $method,
                'endpoint' => $this->logEndpoint($endpoint),
                'statusCode' => $status,
                'durationMs' => (int)round((microtime(true) - $started) * 1000),
                'orderId' => $context['orderId'] ?? null,
                'division' => $oauth->getDivision(),
                'summary' => mb_substr($message, 0, 255),
                'message' => $message,
                'request' => $encodedBody,
                'response' => $responseBody,
            ]);

            if ($status === 429) {
                throw (new RateLimitException($message, 429, $responseBody, $reason, $e))
                    ->setRetryAfter($this->retryAfterFrom($response));
            }

            throw new ApiException($message, $status, $responseBody, $reason, $e);
        } catch (\Throwable $e) {
            $plugin->getLog()->write($action, [
                'level' => LogEntry::LEVEL_ERROR,
                'method' => $method,
                'endpoint' => $this->logEndpoint($endpoint),
                'durationMs' => (int)round((microtime(true) - $started) * 1000),
                'orderId' => $context['orderId'] ?? null,
                'summary' => mb_substr($e->getMessage(), 0, 255),
                'message' => $e->getMessage(),
                'request' => $encodedBody,
            ]);

            throw new ApiException($e->getMessage(), 0, null, null, $e);
        }
    }

    // URLs
    // -------------------------------------------------------------------------

    /**
     * Turn `salesinvoice/SalesInvoices` into a full URL for the current division.
     *
     * An absolute URL is passed through untouched — that is what `__next` hands back, and
     * rewriting it would drop the continuation token. But only on Exact's own host: the bearer
     * token goes wherever this URL points, and `__next` is read from a response body.
     *
     * @throws ApiException
     */
    public function buildUrl(string $endpoint, array $params = []): string
    {
        if (str_starts_with($endpoint, 'http://') || str_starts_with($endpoint, 'https://')) {
            $base = parse_url(Plugin::getInstance()->getSettings()->getBaseUrl());
            $next = parse_url($endpoint);

            if (
                ($next['scheme'] ?? '') !== 'https'
                || strtolower($next['host'] ?? '') !== strtolower($base['host'] ?? '')
            ) {
                throw new ApiException(Craft::t('exactly', 'Exact Online returned a link to another host; it was not followed.'));
            }

            return $this->applyParams($endpoint, $params);
        }

        $settings = Plugin::getInstance()->getSettings();
        $endpoint = ltrim($endpoint, '/');

        if (in_array($endpoint, self::DIVISIONLESS, true)) {
            $path = self::API_PREFIX . '/' . $endpoint;
        } else {
            $division = Plugin::getInstance()->getOauth()->getDivision();

            if ($division === null || $division <= 0) {
                throw new ApiException(Craft::t('exactly', 'No Exact Online division is selected yet.'));
            }

            $path = self::API_PREFIX . '/' . $division . '/' . $endpoint;
        }

        return $this->applyParams($settings->getBaseUrl() . $path, $params);
    }

    /**
     * OData options are `$`-prefixed, which is awkward to type and easy to get wrong, so callers
     * pass them bare and they are prefixed here.
     */
    private function applyParams(string $url, array $params): string
    {
        if (!$params) {
            return $url;
        }

        $query = [];

        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $key = str_starts_with((string)$key, '$') ? (string)$key : '$' . $key;
            $query[$key] = $value;
        }

        if (!$query) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }

    /**
     * The endpoint as it should appear in the log: relative, and with the division number stripped
     * so a screenful of rows lines up.
     */
    private function logEndpoint(string $endpoint): string
    {
        if (str_starts_with($endpoint, 'http')) {
            $endpoint = (string)parse_url($endpoint, PHP_URL_PATH);
        }

        return preg_replace('~^' . preg_quote(self::API_PREFIX, '~') . '/\d+/~', '', $endpoint) ?? $endpoint;
    }

    // Rate limiting
    // -------------------------------------------------------------------------

    /**
     * Refuse to spend a call there is no budget for.
     *
     * In a console command or a queue job the right answer is to wait, because the caller is a
     * batch and a minute is nothing. In a web request it is to throw: sleeping 45 seconds inside
     * an order save is worse than any missing invoice, and the queue will pick the work up.
     *
     * @throws RateLimitException
     */
    private function guardRateLimit(): void
    {
        $connection = Plugin::getInstance()->getOauth()->getConnection();

        if ($connection === null) {
            return;
        }

        // Blocks only until the stored reset time. A spent daily budget with no reset time, or
        // one whose reset has passed, lets the call through — the response is the only thing that
        // can refresh the count, so blocking on the count alone would block for ever.
        $dailyWait = $connection->getSecondsUntilDailyReset();

        if ($dailyWait > 0) {
            throw (new RateLimitException(Craft::t('exactly', 'The Exact Online daily call limit for this administration is spent. It resets at midnight in Exact’s time zone.'), 429))
                ->setRetryAfter($dailyWait);
        }

        $wait = $connection->getSecondsUntilRateLimitReset();

        if ($wait <= 0) {
            return;
        }

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            sleep($wait);

            return;
        }

        throw (new RateLimitException(Craft::t('exactly', 'The Exact Online per-minute call limit is spent; this resets in {seconds}s.', ['seconds' => $wait]), 429))
            ->setRetryAfter($wait);
    }

    private function recordRateLimits(ResponseInterface $response): void
    {
        $limits = [
            'dailyLimit' => $this->intHeader($response, 'X-RateLimit-Limit'),
            'dailyRemaining' => $this->intHeader($response, 'X-RateLimit-Remaining'),
            // Epoch milliseconds, like the minutely one.
            'dailyReset' => $this->intHeader($response, 'X-RateLimit-Reset'),
            'minutelyLimit' => $this->intHeader($response, 'X-RateLimit-Minutely-Limit'),
            'minutelyRemaining' => $this->intHeader($response, 'X-RateLimit-Minutely-Remaining'),
            // Epoch milliseconds, not seconds.
            'minutelyReset' => $this->intHeader($response, 'X-RateLimit-Minutely-Reset'),
        ];

        if (array_filter($limits, static fn($value) => $value !== null) === []) {
            return;
        }

        Plugin::getInstance()->getOauth()->recordRateLimits($limits);
    }

    private function intHeader(ResponseInterface $response, string $name): ?int
    {
        $value = $response->getHeaderLine($name);

        return $value === '' ? null : (int)$value;
    }

    private function retryAfterFrom(?ResponseInterface $response): int
    {
        if ($response === null) {
            return 60;
        }

        $retryAfter = $response->getHeaderLine('Retry-After');

        if ($retryAfter !== '' && ctype_digit($retryAfter)) {
            return (int)$retryAfter;
        }

        // A spent *daily* budget waits for the daily reset, not the minutely one — otherwise the
        // queue comes back every minute to be refused again.
        if ($response->getHeaderLine('X-RateLimit-Remaining') === '0' && $response->getHeaderLine('X-RateLimit-Reset') !== '') {
            return max(1, (int)ceil((int)$response->getHeaderLine('X-RateLimit-Reset') / 1000) - time() + 1);
        }

        $reset = $response->getHeaderLine('X-RateLimit-Minutely-Reset');

        if ($reset !== '') {
            return max(1, (int)ceil((int)$reset / 1000) - time() + 1);
        }

        return 60;
    }
}
