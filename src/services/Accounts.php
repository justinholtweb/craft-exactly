<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\Address;
use craft\helpers\Db;
use DateTime;
use justinholtweb\exactly\db\Table;
use justinholtweb\exactly\errors\ApiException;
use justinholtweb\exactly\helpers\Odata;
use justinholtweb\exactly\helpers\Vat as VatHelper;
use justinholtweb\exactly\Plugin;

/**
 * Matching a Craft customer to an Exact Online account, and creating one when there is no match.
 *
 * The failure mode this exists to prevent is duplicates. Exact accounts are permanent bookkeeping
 * records; a store that creates a fresh one on every checkout leaves the merchant with four
 * hundred "J. Jansen"s and a receivables list nobody can read. So every resolution is cached
 * locally in `{{%exactly_accounts}}`, and the unique index on `(division, matchKey)` means two
 * simultaneous orders from the same customer cannot both decide to create one.
 */
class Accounts extends Component
{
    public const ENDPOINT = 'crm/Accounts';

    /**
     * Resolve the Exact account for an order, creating it if the settings allow.
     *
     * @return array{id: string, code: string|null, created: bool}
     * @throws ApiException
     */
    public function resolveForOrder(Order $order, bool $allowCreate = true): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $division = $this->requireDivision();

        $email = strtolower(trim((string)$order->getEmail()));
        $vatInfo = Plugin::getInstance()->getVat()->getTreatmentForOrder($order);
        $vatNumber = $vatInfo['vatNumber'];

        foreach ($this->matchOrder($settings->accountMatchStrategy) as $strategy) {
            $key = $strategy === 'vat'
                ? ($vatNumber !== '' ? 'vat:' . $vatNumber : '')
                : $email;

            if ($key === '') {
                continue;
            }

            $cached = $this->getCachedAccount($division, $key);

            if ($cached !== null) {
                return ['id' => $cached['exactAccountId'], 'code' => $cached['exactAccountCode'], 'created' => false];
            }

            $remote = $strategy === 'vat'
                ? $this->findByVatNumber($vatNumber)
                : $this->findByEmail($email);

            if ($remote !== null) {
                $this->cacheAccount($division, $key, $order, $remote, $vatNumber);

                if ($settings->updateExistingAccounts) {
                    $this->updateAccount((string)$remote['ID'], $order, $vatNumber);
                }

                return [
                    'id' => (string)$remote['ID'],
                    'code' => Odata::trimCode($remote['Code'] ?? null),
                    'created' => false,
                ];
            }
        }

        if (!$allowCreate) {
            // A preview must not leave a new customer behind in the merchant's books.
            return ['id' => '', 'code' => null, 'created' => false];
        }

        if (!$settings->createMissingAccounts) {
            throw new ApiException(Craft::t('exactly', 'No Exact Online account matches {email}, and creating accounts is switched off.', [
                'email' => $email ?: Craft::t('exactly', 'this customer'),
            ]));
        }

        $created = $this->createAccount($order, $vatNumber);
        $key = $email !== '' ? $email : 'vat:' . $vatNumber;

        if ($key !== '' && $key !== 'vat:') {
            $this->cacheAccount($division, $key, $order, $created, $vatNumber);
        }

        return [
            'id' => (string)$created['ID'],
            'code' => Odata::trimCode($created['Code'] ?? null),
            'created' => true,
        ];
    }

    /**
     * The lookup order implied by the configured strategy.
     *
     * @return string[]
     */
    private function matchOrder(string $strategy): array
    {
        return match ($strategy) {
            'email' => ['email'],
            'vat' => ['vat'],
            'vatThenEmail' => ['vat', 'email'],
            default => ['email', 'vat'],
        };
    }

    // Remote lookups
    // -------------------------------------------------------------------------

    /**
     * @throws ApiException
     */
    public function findByEmail(string $email): ?array
    {
        if ($email === '') {
            return null;
        }

        return Plugin::getInstance()->getApi()->findOne(
            self::ENDPOINT,
            'Email eq ' . Odata::quote($email),
            ['ID', 'Code', 'Name', 'Email', 'VATNumber', 'Country'],
            ['action' => 'accounts.find', 'summary' => 'Look up account by email'],
        );
    }

    /**
     * @throws ApiException
     */
    public function findByVatNumber(string $vatNumber): ?array
    {
        $vatNumber = VatHelper::canonical($vatNumber);

        if ($vatNumber === '') {
            return null;
        }

        return Plugin::getInstance()->getApi()->findOne(
            self::ENDPOINT,
            'VATNumber eq ' . Odata::quote($vatNumber),
            ['ID', 'Code', 'Name', 'Email', 'VATNumber', 'Country'],
            ['action' => 'accounts.find', 'summary' => 'Look up account by VAT number'],
        );
    }

    /**
     * @throws ApiException
     */
    public function findByCode(string $code): ?array
    {
        $code = Odata::padCode($code);

        if (trim($code) === '') {
            return null;
        }

        // The padding is not cosmetic — see `Odata::padCode()`.
        return Plugin::getInstance()->getApi()->findOne(
            self::ENDPOINT,
            'Code eq ' . Odata::quote($code),
            ['ID', 'Code', 'Name', 'Email', 'VATNumber', 'Country'],
            ['action' => 'accounts.find', 'summary' => 'Look up account by code'],
        );
    }

    // Writing
    // -------------------------------------------------------------------------

    /**
     * @throws ApiException
     */
    public function createAccount(Order $order, string $vatNumber = ''): array
    {
        $payload = $this->buildAccountPayload($order, $vatNumber);

        $created = Plugin::getInstance()->getApi()->post(self::ENDPOINT, $payload, [
            'action' => 'accounts.create',
            'orderId' => $order->id,
            'summary' => Craft::t('exactly', 'Create account {name}', ['name' => $payload['Name']]),
        ]);

        if (!is_array($created) || !isset($created['ID'])) {
            throw new ApiException(Craft::t('exactly', 'Exact Online accepted the account but returned no ID.'));
        }

        return $created;
    }

    /**
     * @throws ApiException
     */
    public function updateAccount(string $accountId, Order $order, string $vatNumber = ''): void
    {
        $payload = $this->buildAccountPayload($order, $vatNumber);

        // `Status` and `Code` are the merchant's to manage once the account exists. Overwriting
        // them from a checkout is how a carefully set-up prospect becomes a customer by accident.
        unset($payload['Status'], $payload['Code']);

        Plugin::getInstance()->getApi()->put(
            self::ENDPOINT . "(guid'{$accountId}')",
            $payload,
            [
                'action' => 'accounts.update',
                'orderId' => $order->id,
                'summary' => Craft::t('exactly', 'Update account'),
            ],
        );
    }

    /**
     * The Exact account body for an order's customer.
     *
     * `Name` is the only mandatory field, and it is the one most likely to be empty — a guest
     * checkout with no organisation and no full name would otherwise post a blank name and get a
     * 400 that says nothing useful.
     */
    public function buildAccountPayload(Order $order, string $vatNumber = ''): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $address = $order->getBillingAddress() ?? $order->getShippingAddress();
        $email = trim((string)$order->getEmail());

        $payload = [
            'Name' => $this->resolveName($order, $address, $email),
            'Status' => $settings->accountStatus,
            'IsSales' => true,
        ];

        if ($email !== '') {
            $payload['Email'] = $email;
        }

        if ($vatNumber !== '') {
            $payload['VATNumber'] = VatHelper::canonical($vatNumber, $address?->countryCode);
        }

        if ($address !== null) {
            $payload += array_filter([
                'AddressLine1' => trim((string)$address->addressLine1),
                'AddressLine2' => trim((string)$address->addressLine2),
                'Postcode' => trim((string)$address->postalCode),
                'City' => trim((string)$address->locality),
                'State' => trim((string)$address->administrativeArea),
                'Country' => strtoupper(trim((string)$address->countryCode)),
            ], static fn(string $value) => $value !== '');
        }

        if ($order->getCustomer()?->id) {
            // Not a real Exact field, but a searchable one — it makes "which Craft user is this?"
            // answerable from inside Exact without a spreadsheet.
            $payload['Remarks'] = Craft::t('exactly', 'Craft user #{id}', ['id' => $order->getCustomer()->id]);
        }

        return $payload;
    }

    /**
     * Organisation first, then the address's name, then the order email, then the order number.
     * Something always comes out, because `Name` is mandatory and a failed push here is a failed
     * invoice.
     */
    private function resolveName(Order $order, ?Address $address, string $email): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $handle = trim($settings->companyFieldHandle);

        if ($handle !== '' && $address !== null) {
            try {
                $value = $address->canGetProperty($handle, true, false)
                    ? $address->$handle
                    : $address->getFieldValue($handle);

                if (is_scalar($value) && trim((string)$value) !== '') {
                    return mb_substr(trim((string)$value), 0, 255);
                }
            } catch (\Throwable) {
                // Fall through to the ordinary candidates.
            }
        }

        $candidates = [
            trim((string)($address?->organization ?? '')),
            trim((string)($address?->fullName ?? '')),
            trim(implode(' ', array_filter([
                (string)($address?->firstName ?? ''),
                (string)($address?->lastName ?? ''),
            ]))),
            $email,
            (string)$order->reference,
            'Order ' . $order->id,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return mb_substr($candidate, 0, 255);
            }
        }

        return 'Unknown customer';
    }

    // The local cache
    // -------------------------------------------------------------------------

    public function getCachedAccount(int $division, string $matchKey): ?array
    {
        $row = (new Query())
            ->from([Table::ACCOUNTS])
            ->where(['division' => $division, 'matchKey' => $matchKey])
            ->one();

        return $row ?: null;
    }

    /**
     * Remember a resolution.
     *
     * Written with an upsert on the unique `(division, matchKey)` index, so two orders from the
     * same customer arriving at the same moment cannot both insert.
     */
    public function cacheAccount(int $division, string $matchKey, Order $order, array $exactAccount, string $vatNumber = ''): void
    {
        $address = $order->getBillingAddress() ?? $order->getShippingAddress();

        // Every column goes in the *insert* half. `Db::upsert()` only inserts the columns it is
        // handed — a key/value split would leave `exactAccountId` null on the insert path, which
        // is a NOT NULL violation on exactly the run that matters (the first one). Passing `true`
        // for the update half makes Craft derive it from these, minus `dateCreated` and `uid`.
        Db::upsert(Table::ACCOUNTS, [
            'division' => $division,
            'matchKey' => $matchKey,
            'customerId' => $order->getCustomer()?->id,
            'exactAccountId' => (string)($exactAccount['ID'] ?? ''),
            'exactAccountCode' => Odata::trimCode($exactAccount['Code'] ?? null),
            'name' => mb_substr((string)($exactAccount['Name'] ?? ''), 0, 255),
            'vatNumber' => $vatNumber !== '' ? $vatNumber : null,
            'countryCode' => strtoupper(substr((string)($address?->countryCode ?? ''), 0, 2)) ?: null,
            'dateSynced' => Db::prepareDateForDb(new DateTime()),
        ], true);
    }

    /**
     * Forget every cached match for a division. Used when the merchant changes administration, or
     * has cleaned up duplicates in Exact and wants Exactly to look again.
     */
    public function clearCache(?int $division = null): int
    {
        $condition = $division !== null ? ['division' => $division] : '';

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::ACCOUNTS, $condition)->execute();
    }

    public function countCached(?int $division = null): int
    {
        $query = (new Query())->from([Table::ACCOUNTS]);

        if ($division !== null) {
            $query->where(['division' => $division]);
        }

        return (int)$query->count();
    }

    /**
     * @throws ApiException
     */
    private function requireDivision(): int
    {
        $division = Plugin::getInstance()->getOauth()->getDivision();

        if ($division === null || $division <= 0) {
            throw new ApiException(Craft::t('exactly', 'No Exact Online division is selected yet.'));
        }

        return $division;
    }
}
