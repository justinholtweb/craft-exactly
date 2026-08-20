<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use justinholtweb\exactly\errors\ApiException;
use justinholtweb\exactly\helpers\Odata;
use justinholtweb\exactly\Plugin;

/**
 * General ledger accounts and payment conditions.
 *
 * Merchants think in ledger *codes* — `8000`, `1300` — because that is what is printed on their
 * chart of accounts. Exact's invoice lines want the account's **GUID**. Translating between the
 * two on every line would cost a call per line out of a sixty-per-minute budget, so the whole
 * chart is fetched once and cached.
 */
class Ledger extends Component
{
    public const ENDPOINT = 'financial/GLAccounts';
    public const PAYMENT_CONDITIONS_ENDPOINT = 'cashflow/PaymentConditions';

    /**
     * An hour. Adding a ledger account is a deliberate act a bookkeeper performs a handful of
     * times a year, and the settings screen has a "refresh" button for the moment they do.
     */
    public const CACHE_SECONDS = 3600;

    /**
     * Code => GUID for every GL account in the administration.
     *
     * @return array<string, string>
     */
    public function getAccountMap(bool $refresh = false): array
    {
        $division = Plugin::getInstance()->getOauth()->getDivision();

        if ($division === null) {
            return [];
        }

        $cacheKey = 'exactly.glAccounts.' . $division;
        $cache = Craft::$app->getCache();

        if (!$refresh) {
            $cached = $cache->get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $rows = Plugin::getInstance()->getApi()->getAll(self::ENDPOINT, [
                'select' => 'ID,Code,Description,Type',
                'orderby' => 'Code',
            ], 40, ['action' => 'ledger.accounts', 'summary' => 'List GL accounts']);
        } catch (ApiException $e) {
            Craft::warning('Exactly could not list GL accounts: ' . $e->getMessage(), __METHOD__);

            return [];
        }

        $map = [];
        $labels = [];

        foreach ($rows as $row) {
            $code = Odata::trimCode($row['Code'] ?? null);

            if ($code === '' || !isset($row['ID'])) {
                continue;
            }

            $map[$code] = (string)$row['ID'];
            $labels[$code] = trim($code . ' — ' . (string)($row['Description'] ?? ''));
        }

        $cache->set($cacheKey, $map, self::CACHE_SECONDS);
        $cache->set($cacheKey . '.labels', $labels, self::CACHE_SECONDS);

        return $map;
    }

    /**
     * Code => "code — description", for the settings menus.
     *
     * @return array<string, string>
     */
    public function getAccountLabels(bool $refresh = false): array
    {
        $division = Plugin::getInstance()->getOauth()->getDivision();

        if ($division === null) {
            return [];
        }

        $cacheKey = 'exactly.glAccounts.' . $division . '.labels';

        if (!$refresh) {
            $cached = Craft::$app->getCache()->get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $this->getAccountMap(true);

        $labels = Craft::$app->getCache()->get($cacheKey);

        return is_array($labels) ? $labels : [];
    }

    /**
     * The GUID for a ledger code.
     *
     * A code that is already a GUID is passed straight through — plenty of merchants paste the ID
     * out of Exact's URL bar, and refusing that would be pedantry.
     */
    public function resolveAccountId(?string $code): ?string
    {
        $code = Odata::trimCode($code);

        if ($code === '') {
            return null;
        }

        if (Odata::isGuid($code)) {
            return $code;
        }

        $map = $this->getAccountMap();

        if (isset($map[$code])) {
            return $map[$code];
        }

        // A code that is not in the cached chart may be genuinely new; one refresh, then give up.
        $map = $this->getAccountMap(true);

        return $map[$code] ?? null;
    }

    /**
     * Payment condition codes, for the settings menu.
     *
     * @return array<string, string> code => description
     */
    public function getPaymentConditions(bool $refresh = false): array
    {
        $division = Plugin::getInstance()->getOauth()->getDivision();

        if ($division === null) {
            return [];
        }

        $cacheKey = 'exactly.paymentConditions.' . $division;
        $cache = Craft::$app->getCache();

        if (!$refresh) {
            $cached = $cache->get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $rows = Plugin::getInstance()->getApi()->getAll(self::PAYMENT_CONDITIONS_ENDPOINT, [
                'select' => 'Code,Description',
                'orderby' => 'Code',
            ], 5, ['action' => 'ledger.paymentConditions', 'summary' => 'List payment conditions']);
        } catch (ApiException $e) {
            Craft::warning('Exactly could not list payment conditions: ' . $e->getMessage(), __METHOD__);

            return [];
        }

        $conditions = [];

        foreach ($rows as $row) {
            $code = Odata::trimCode($row['Code'] ?? null);

            if ($code !== '') {
                $conditions[$code] = trim($code . ' — ' . (string)($row['Description'] ?? ''));
            }
        }

        $cache->set($cacheKey, $conditions, self::CACHE_SECONDS);

        return $conditions;
    }

    /**
     * Drop every cached chart for the division. Called when the administration changes.
     */
    public function clearCache(?int $division = null): void
    {
        $division ??= Plugin::getInstance()->getOauth()->getDivision();

        if ($division === null) {
            return;
        }

        $cache = Craft::$app->getCache();

        foreach ([
            'exactly.glAccounts.' . $division,
            'exactly.glAccounts.' . $division . '.labels',
            'exactly.paymentConditions.' . $division,
            'exactly.vatCodes.' . $division,
            'exactly.sellerCountry.' . $division,
        ] as $key) {
            $cache->delete($key);
        }
    }
}
