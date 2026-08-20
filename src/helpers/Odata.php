<?php

namespace justinholtweb\exactly\helpers;

use DateTime;
use DateTimeInterface;
use DateTimeZone;

/**
 * The bits of OData that Exact Online actually uses.
 *
 * Exact's REST API is OData v2 wearing a JSON coat. That means three things that bite anyone who
 * treats it as a normal JSON API:
 *
 * 1. Every response is wrapped in a `d` envelope; collections nest again under `results` and
 *    page through a `__next` URL rather than an offset.
 * 2. Dates come back in Microsoft's `/Date(ms)/` form, which no date parser in PHP understands,
 *    and go *in* as plain ISO 8601 without a zone.
 * 3. `$filter` is a query language, not a query string. Strings are single-quoted with doubled
 *    quotes for escaping, and GUIDs carry a `guid` prefix — an unescaped apostrophe in a customer
 *    name is a 400, not a mismatch.
 */
abstract class Odata
{
    /**
     * Exact's `Code` columns are fixed-width, space-padded on the left, and eighteen characters
     * wide for accounts and items.
     */
    public const CODE_WIDTH = 18;

    /**
     * Unwrap the `d` envelope.
     *
     * A collection comes back as `{"d":{"results":[…],"__next":"…"}}` and a single entity as
     * `{"d":{…}}`, so this returns whichever the response actually was and leaves the caller to
     * know which it asked for.
     */
    public static function unwrap(mixed $body): mixed
    {
        if (!is_array($body)) {
            return $body;
        }

        if (!array_key_exists('d', $body)) {
            return $body;
        }

        $d = $body['d'];

        if (is_array($d) && array_key_exists('results', $d)) {
            return $d['results'];
        }

        return $d;
    }

    /**
     * The `__next` page URL, if the response had one.
     */
    public static function nextUrl(mixed $body): ?string
    {
        if (!is_array($body) || !isset($body['d']) || !is_array($body['d'])) {
            return null;
        }

        $next = $body['d']['__next'] ?? null;

        return is_string($next) && $next !== '' ? $next : null;
    }

    /**
     * Exact's error bodies are `{"error":{"message":{"value":"…"}}}`. Anything else — an HTML
     * error page from a proxy, an empty body, a `Reason` header on its own — is returned as-is so
     * the log records what actually arrived rather than "unknown error".
     */
    public static function errorMessage(?string $body): ?string
    {
        if ($body === null || trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $value = $decoded['error']['message']['value'] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }

            $message = $decoded['error']['message'] ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return mb_substr(trim(strip_tags($body)), 0, 500);
    }

    /**
     * Parse `/Date(1755648000000)/`, and fall back to anything PHP can read.
     *
     * The milliseconds are a UTC epoch. An offset suffix (`/Date(1755648000000+0120)/`) appears on
     * some endpoints and is deliberately ignored: the instant is already absolute, and applying
     * the offset a second time moves the date by two hours.
     */
    public static function parseDate(mixed $value): ?DateTime
    {
        if ($value instanceof DateTime) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('~^/Date\((-?\d+)([+-]\d{4})?\)/$~', $value, $match)) {
            $seconds = (int)floor((int)$match[1] / 1000);

            return (new DateTime('@' . $seconds))->setTimezone(new DateTimeZone('UTC'));
        }

        try {
            return new DateTime($value, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Format a date for a request body.
     *
     * Exact wants a zone-less ISO 8601 local date-time and interprets it in the administration's
     * own time zone. Sending `Z` or an offset is accepted and then silently shifted, which is how
     * an invoice dated the 1st lands in the previous VAT period.
     */
    public static function formatDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\TH:i:s');
    }

    /**
     * A date-only value, for InvoiceDate / ReportingPeriod style fields.
     */
    public static function formatDay(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d\T00:00:00');
    }

    /**
     * Quote a string for use inside `$filter`.
     */
    public static function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Quote a GUID for use inside `$filter`.
     */
    public static function guid(string $value): string
    {
        return "guid'" . str_replace("'", '', $value) . "'";
    }

    /**
     * Whether a string looks like an Exact primary key.
     *
     * Worth checking before it goes into a filter: Exact answers a malformed GUID with a 400 and
     * a message about the *query*, which sends you looking at the wrong end of the problem.
     */
    public static function isGuid(mixed $value): bool
    {
        return is_string($value)
            && (bool)preg_match('~^\{?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\}?$~i', $value);
    }

    /**
     * Exact's `Code` columns are fixed-width and space-padded on the left — an account code of
     * `1024` is stored as eighteen characters ending in `1024`. Comparing a trimmed code against
     * a stored one never matches, and neither does filtering on the padded form the API returned.
     */
    public static function trimCode(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * Pad a code back out to Exact's stored width, for use in a `$filter`.
     *
     * Exact's own documentation is blunt about this: "when you use OData $filter on this field you
     * have to make sure the filter parameter contains the leading spaces". Filtering
     * `Code eq '1024'` against a column holding `'              1024'` matches nothing and reports
     * no error, so the integration concludes the customer does not exist and creates a duplicate.
     */
    public static function padCode(mixed $value, int $width = self::CODE_WIDTH): string
    {
        $value = self::trimCode($value);

        if ($value === '') {
            return '';
        }

        return str_pad($value, $width, ' ', STR_PAD_LEFT);
    }
}
