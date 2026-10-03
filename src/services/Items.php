<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use craft\commerce\base\Purchasable;
use craft\commerce\models\LineItem;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use justinholtweb\exactly\db\Table;
use justinholtweb\exactly\errors\ApiException;
use justinholtweb\exactly\helpers\Odata;
use justinholtweb\exactly\Plugin;

/**
 * Resolving a Commerce line item to an Exact Online item.
 *
 * `Item` is **mandatory** on an Exact sales invoice line. That single fact shapes this whole
 * class: there is no "just send a description" escape hatch, so every line has to end up holding a
 * GUID or the invoice cannot be written at all.
 *
 * Three ways out, in order:
 *
 * 1. A cached SKU → item mapping in `{{%exactly_items}}`.
 * 2. A live lookup by item code (the SKU), cached on the way past.
 * 3. The configured fallback item — one generic "Webshop sale" line item, with the real product
 *    name carried in the line description. Most stores want this: a Commerce catalogue of 4,000
 *    variants does not belong in an accounting package's stock list.
 *
 * Creating missing items is offered but off by default, for the same reason.
 */
class Items extends Component
{
    public const ENDPOINT = 'logistics/Items';

    /**
     * Resolve the Exact item GUID for a line.
     *
     * `pending` is true (and `id` empty) only for a preview of a SKU the send would create.
     *
     * @return array{id: string, code: string|null, created: bool, fallback: bool, pending?: bool}
     * @throws ApiException
     */
    public function resolveForLineItem(LineItem $lineItem, bool $allowCreate = true): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $division = $this->requireDivision();
        $sku = trim((string)$lineItem->getSku());

        if ($settings->itemStrategy === 'fallback' || $sku === '') {
            return $this->fallback();
        }

        $cached = $this->getCachedItem($division, $sku);

        if ($cached !== null) {
            return [
                'id' => (string)$cached['exactItemId'],
                'code' => $cached['exactItemCode'],
                'created' => false,
                'fallback' => false,
            ];
        }

        $remote = $this->findByCode($sku);

        if ($remote !== null) {
            $this->cacheItem($division, $sku, $lineItem, $remote);

            return [
                'id' => (string)$remote['ID'],
                'code' => Odata::trimCode($remote['Code'] ?? null),
                'created' => false,
                'fallback' => false,
            ];
        }

        // A preview of a SKU that the send would create: say so, with no item, rather than show
        // the fallback item the send would not use (or throw for want of one).
        if (!$allowCreate && $settings->createMissingItems) {
            return ['id' => '', 'code' => null, 'created' => false, 'fallback' => false, 'pending' => true];
        }

        if ($allowCreate && $settings->createMissingItems) {
            $created = $this->createItem($lineItem);
            $this->cacheItem($division, $sku, $lineItem, $created);

            return [
                'id' => (string)$created['ID'],
                'code' => Odata::trimCode($created['Code'] ?? null),
                'created' => true,
                'fallback' => false,
            ];
        }

        return $this->fallback($sku);
    }

    /**
     * The generic item everything unmatched lands on.
     *
     * @throws ApiException
     */
    public function fallback(?string $unmatchedSku = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $code = trim($settings->fallbackItemCode);

        if ($code === '') {
            throw new ApiException($unmatchedSku !== null
                ? Craft::t('exactly', 'No Exact Online item matches SKU “{sku}”, and no fallback item is configured. Set one in Exactly’s settings.', ['sku' => $unmatchedSku])
                : Craft::t('exactly', 'No fallback Exact Online item is configured. Set one in Exactly’s settings.'));
        }

        $division = $this->requireDivision();
        $cached = $this->getCachedItem($division, '__fallback__' . $code);

        if ($cached !== null) {
            return [
                'id' => (string)$cached['exactItemId'],
                'code' => $cached['exactItemCode'],
                'created' => false,
                'fallback' => true,
            ];
        }

        $remote = Odata::isGuid($code)
            ? ['ID' => $code, 'Code' => null, 'Description' => null]
            : $this->findByCode($code);

        if ($remote === null) {
            throw new ApiException(Craft::t('exactly', 'The fallback item “{code}” does not exist in this Exact Online administration.', ['code' => $code]));
        }

        $this->cacheRow($division, '__fallback__' . $code, null, $remote);

        return [
            'id' => (string)$remote['ID'],
            'code' => Odata::trimCode($remote['Code'] ?? null),
            'created' => false,
            'fallback' => true,
        ];
    }

    /**
     * @throws ApiException
     */
    public function findByCode(string $code): ?array
    {
        $padded = Odata::padCode($code);

        if (trim($padded) === '') {
            return null;
        }

        // The leading spaces are load-bearing — an unpadded `Code eq '…'` matches nothing and
        // reports no error, which reads exactly like "this SKU is not in Exact".
        return Plugin::getInstance()->getApi()->findOne(
            self::ENDPOINT,
            'Code eq ' . Odata::quote($padded),
            ['ID', 'Code', 'Description', 'SalesVatCode', 'GLRevenue', 'IsSalesItem'],
            ['action' => 'items.find', 'summary' => 'Look up item ' . trim($code)],
        );
    }

    /**
     * @throws ApiException
     */
    public function createItem(LineItem $lineItem): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $sku = trim((string)$lineItem->getSku());

        $payload = [
            'Code' => $sku,
            'Description' => mb_substr((string)$lineItem->getDescription(), 0, 60),
            'IsSalesItem' => true,
            'IsStockItem' => false,
        ];

        $glId = Plugin::getInstance()->getLedger()->resolveAccountId($settings->defaultGlAccountCode);

        if ($glId !== null) {
            $payload['GLRevenue'] = $glId;
        }

        $created = Plugin::getInstance()->getApi()->post(self::ENDPOINT, $payload, [
            'action' => 'items.create',
            'summary' => Craft::t('exactly', 'Create item {sku}', ['sku' => $sku]),
        ]);

        if (!is_array($created) || !isset($created['ID'])) {
            throw new ApiException(Craft::t('exactly', 'Exact Online accepted the item but returned no ID.'));
        }

        return $created;
    }

    /**
     * The revenue GL account for a line.
     *
     * Per-product-type first — that is how a merchant separates goods revenue from services
     * revenue without touching every product — then the default. Returning null is fine: Exact
     * falls back to the item's own `GLRevenue`, which is where most administrations keep it.
     */
    public function resolveGlAccountId(LineItem $lineItem): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        $ledger = Plugin::getInstance()->getLedger();

        if ($settings->glAccountByProductType) {
            $handle = $this->getProductTypeHandle($lineItem);

            if ($handle !== null) {
                $code = trim((string)($settings->glAccountByProductType[$handle] ?? ''));

                if ($code !== '') {
                    return $ledger->resolveAccountId($code);
                }
            }
        }

        return $ledger->resolveAccountId($settings->defaultGlAccountCode);
    }

    /**
     * The product type handle behind a line item, when there is one.
     *
     * A Variant reaches its product with `getOwner()` in Commerce 5 — `getProduct()` was the
     * Commerce 4 spelling — and plenty of purchasables (donations, plugin-provided ones) have no
     * product type at all, which is not an error.
     */
    public function getProductTypeHandle(LineItem $lineItem): ?string
    {
        try {
            $purchasable = $lineItem->getPurchasable();
        } catch (\Throwable) {
            return null;
        }

        if (!$purchasable instanceof Purchasable) {
            return null;
        }

        if ($purchasable instanceof \craft\commerce\elements\Variant) {
            try {
                return $purchasable->getOwner()?->getType()?->handle;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    // The local cache
    // -------------------------------------------------------------------------

    public function getCachedItem(int $division, string $sku): ?array
    {
        $row = (new Query())
            ->from([Table::ITEMS])
            ->where(['division' => $division, 'sku' => $sku])
            ->one();

        return $row ?: null;
    }

    public function cacheItem(int $division, string $sku, LineItem $lineItem, array $exactItem): void
    {
        $this->cacheRow($division, $sku, $lineItem, $exactItem);
    }

    private function cacheRow(int $division, string $sku, ?LineItem $lineItem, array $exactItem): void
    {
        $purchasableId = null;

        if ($lineItem !== null) {
            try {
                $purchasableId = $lineItem->purchasableId;
            } catch (\Throwable) {
                $purchasableId = null;
            }
        }

        // Everything in the insert half; see the same note in `Accounts::cacheAccount()`.
        Db::upsert(Table::ITEMS, [
            'division' => $division,
            'sku' => $sku,
            'purchasableId' => $purchasableId,
            'exactItemId' => (string)($exactItem['ID'] ?? ''),
            'exactItemCode' => Odata::trimCode($exactItem['Code'] ?? null),
            'description' => mb_substr((string)($exactItem['Description'] ?? ''), 0, 255),
            'glAccountId' => isset($exactItem['GLRevenue']) && Odata::isGuid($exactItem['GLRevenue'])
                ? (string)$exactItem['GLRevenue']
                : null,
            'dateSynced' => Db::prepareDateForDb(new DateTime()),
        ], true);
    }

    public function clearCache(?int $division = null): int
    {
        $condition = $division !== null ? ['division' => $division] : '';

        return (int)Craft::$app->getDb()->createCommand()->delete(Table::ITEMS, $condition)->execute();
    }

    public function countCached(?int $division = null): int
    {
        $query = (new Query())->from([Table::ITEMS]);

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
