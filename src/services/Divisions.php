<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use justinholtweb\exactly\errors\ApiException;
use justinholtweb\exactly\Plugin;

/**
 * Who we are connected as, and which administrations that account can write to.
 *
 * `current/Me` is the one endpoint that carries no division segment — asking for it per division
 * is a 404, which is an unhelpful thing to hit while trying to find out what your division is.
 * Everything else, `hrm/Divisions` included, is division-scoped, so listing administrations needs
 * a working division first. `Me` provides it.
 */
class Divisions extends Component
{
    /**
     * The connected user, straight from Exact.
     *
     * @throws ApiException
     */
    public function getMe(): array
    {
        $me = Plugin::getInstance()->getApi()->get('current/Me', [
            'select' => 'UserID,FullName,Email,CurrentDivision,DivisionCustomerName,ServerTime,ServerUtcOffset,Language',
        ], ['action' => 'divisions.me', 'summary' => 'Identify connected user']);

        if (is_array($me) && array_is_list($me)) {
            $me = $me[0] ?? [];
        }

        return is_array($me) ? $me : [];
    }

    /**
     * Refresh the stored identity after connecting, or when the merchant asks.
     */
    public function syncIdentity(): array
    {
        $me = $this->getMe();

        if ($me) {
            Plugin::getInstance()->getOauth()->updateIdentity($me);
        }

        return $me;
    }

    /**
     * Every administration the connected user can reach.
     *
     * Archived administrations (`Status` 1) are filtered out: they exist only to satisfy record
     * retention and cannot take new invoices, so offering one in the settings menu can only lead
     * somewhere disappointing.
     *
     * @return array<int, array{code: int, name: string, currency: string, country: string, main: bool}>
     */
    public function getDivisions(): array
    {
        try {
            $rows = Plugin::getInstance()->getApi()->getAll('hrm/Divisions', [
                'select' => 'Code,Description,Currency,Country,Main,Status,HID',
                'orderby' => 'Description',
            ], 5, ['action' => 'divisions.list', 'summary' => 'List administrations']);
        } catch (ApiException $e) {
            Craft::warning('Exactly could not list divisions: ' . $e->getMessage(), __METHOD__);

            return [];
        }

        $divisions = [];

        foreach ($rows as $row) {
            if ((int)($row['Status'] ?? 0) === 1) {
                continue;
            }

            $divisions[] = [
                'code' => (int)($row['Code'] ?? 0),
                'name' => (string)($row['Description'] ?? ''),
                'currency' => (string)($row['Currency'] ?? ''),
                'country' => strtoupper((string)($row['Country'] ?? '')),
                'main' => (bool)($row['Main'] ?? false),
            ];
        }

        return $divisions;
    }

    /**
     * The country the current administration invoices from — the seller side of every VAT
     * decision. Cached for a day: it is a legal fact about a company, not live data.
     */
    public function getSellerCountry(): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        $configured = strtoupper(trim($settings->sellerCountry));

        if ($configured !== '') {
            return $configured;
        }

        $division = Plugin::getInstance()->getOauth()->getDivision();

        if ($division === null) {
            return null;
        }

        $cacheKey = 'exactly.sellerCountry.' . $division;
        $cached = Craft::$app->getCache()->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        foreach ($this->getDivisions() as $candidate) {
            if ($candidate['code'] === $division && $candidate['country'] !== '') {
                Craft::$app->getCache()->set($cacheKey, $candidate['country'], 86400);

                return $candidate['country'];
            }
        }

        return null;
    }

    /**
     * A connection summary for the settings screen. Never throws — a broken connection has to be
     * able to *render* as broken.
     *
     * @return array{connected: bool, message: string, me: array, daysLeft: int|null}
     */
    public function getStatus(): array
    {
        $oauth = Plugin::getInstance()->getOauth();
        $connection = $oauth->getConnection();

        if ($connection === null) {
            return [
                'connected' => false,
                'message' => Craft::t('exactly', 'Not connected.'),
                'me' => [],
                'daysLeft' => null,
            ];
        }

        if (!$connection->isUsable()) {
            return [
                'connected' => false,
                'message' => Craft::t('exactly', 'The connection has expired and needs re-authorising.'),
                'me' => [],
                'daysLeft' => $connection->getDaysUntilExpiry(),
            ];
        }

        try {
            $me = $this->getMe();
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'message' => $e->getMessage(),
                'me' => [],
                'daysLeft' => $connection->getDaysUntilExpiry(),
            ];
        }

        return [
            'connected' => true,
            'message' => Craft::t('exactly', 'Connected as {name}.', [
                'name' => $me['FullName'] ?? $connection->describe(),
            ]),
            'me' => $me,
            'daysLeft' => $connection->getDaysUntilExpiry(),
        ];
    }
}
