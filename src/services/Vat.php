<?php

namespace justinholtweb\exactly\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\elements\Address;
use justinholtweb\exactly\errors\ApiException;
use justinholtweb\exactly\helpers\Vat as VatHelper;
use justinholtweb\exactly\Plugin;

/**
 * VAT determination.
 *
 * The one part of this plugin that is not about Exact Online. A push that stamps a single VAT code
 * on every line produces books that are wrong for every cross-border order, and nobody notices
 * until the quarter is filed.
 *
 * The order of precedence, most specific first:
 *
 * 1. **The Commerce tax rate that was actually applied to the line.** If the merchant has
 *    modelled reduced rates in Commerce, that mapping is authoritative — no inference beats
 *    knowing what the customer was really charged.
 * 2. **An exempt tax category.** A line whose Commerce tax category is marked exempt in the
 *    settings, and which carried no tax, takes the Exempt treatment's code. Only untaxed lines: a
 *    line that was charged VAT keeps the code for what was charged, or the invoice total would
 *    not match the payment.
 * 3. **The VAT treatment of the sale** — domestic, intra-community reverse charge, EU consumer
 *    (OSS), or export. Derived from the shipping country and the customer's VAT number.
 *
 * The reverse-charge decision fails *closed*: an unverifiable VAT number is treated as a consumer
 * sale, which charges VAT. Over-charging is recoverable; under-charging is the merchant's
 * liability.
 */
class Vat extends Component
{
    /**
     * The EU's VIES service. Public, unauthenticated, and regularly slow — hence the timeout and
     * the caching.
     */
    public const VIES_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

    /**
     * How long a VIES answer is trusted. A registration does not change hourly, and the service is
     * unreliable enough that re-asking on every order would cost more sales than it saves.
     */
    public const VIES_CACHE_SECONDS = 604800;

    /**
     * Work out how a sale should be treated.
     *
     * @return array{
     *     treatment: string,
     *     vatNumber: string,
     *     countryCode: string,
     *     sellerCountry: string|null,
     *     validated: bool|null,
     *     note: string|null
     * }
     */
    public function getTreatmentForOrder(Order $order): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $sellerCountry = Plugin::getInstance()->getDivisions()->getSellerCountry();
        $address = $this->getVatAddress($order);
        $buyerCountry = strtoupper((string)($address?->countryCode ?? ''));
        $vatNumber = $this->getVatNumberForOrder($order);

        $validated = null;
        $note = null;

        $treatment = VatHelper::treatment(
            $sellerCountry,
            $buyerCountry,
            $vatNumber,
            $settings->requireValidVatNumber,
        );

        // VIES only ever *downgrades* reverse charge to a consumer sale, so there is no point
        // asking about anything else.
        if ($treatment === VatHelper::TREATMENT_REVERSE_CHARGE && $settings->viesValidation) {
            $validated = $this->validateWithVies($vatNumber, $buyerCountry);

            if ($validated !== true) {
                $treatment = VatHelper::TREATMENT_OSS;
                $note = $validated === false
                    ? Craft::t('exactly', 'VIES says this VAT number is not registered, so VAT was charged.')
                    : Craft::t('exactly', 'VIES could not be reached, so VAT was charged rather than assuming reverse charge.');
            }
        }

        return [
            'treatment' => $treatment,
            'vatNumber' => VatHelper::canonical($vatNumber, $buyerCountry),
            'countryCode' => $buyerCountry,
            'sellerCountry' => $sellerCountry,
            'validated' => $validated,
            'note' => $note,
        ];
    }

    /**
     * Which address decides the VAT.
     *
     * Shipping address for goods, because VAT follows where they end up; billing address is the
     * fallback for a store that ships nothing.
     */
    public function getVatAddress(Order $order): ?Address
    {
        return $order->getShippingAddress() ?? $order->getBillingAddress();
    }

    /**
     * The customer's VAT number, wherever this store keeps it.
     *
     * Craft 5 addresses have `organizationTaxId` built in, which is the right home for it and the
     * one this looks at first. A configured field handle wins when there is one, because plenty of
     * stores collected the number into a custom field years before that attribute existed.
     */
    public function getVatNumberForOrder(Order $order): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $handle = trim($settings->vatNumberFieldHandle);

        if ($handle !== '') {
            foreach ([$order, $order->getBillingAddress(), $order->getShippingAddress()] as $source) {
                if ($source === null) {
                    continue;
                }

                $value = $this->readField($source, $handle);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ([$order->getBillingAddress(), $order->getShippingAddress()] as $address) {
            $taxId = trim((string)($address?->organizationTaxId ?? ''));

            if ($taxId !== '') {
                return $taxId;
            }
        }

        return '';
    }

    /**
     * The Exact VAT code for a line.
     *
     * @param LineItem|null $lineItem null for shipping, discount and rounding lines, which have no
     *                                tax rate of their own and follow the order's treatment.
     */
    public function getVatCodeForLine(Order $order, string $treatment, ?LineItem $lineItem = null): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($lineItem !== null && $settings->vatCodeByTaxRate) {
            foreach ($this->getTaxRateIdsForLine($order, $lineItem) as $rateId) {
                $code = trim((string)($settings->vatCodeByTaxRate[$rateId] ?? ''));

                if ($code !== '') {
                    return $code;
                }
            }
        }

        // A line in an exempt category; or — for shipping and discount, which have no category of
        // their own — an order whose every line is exempt and which carried no tax anywhere. Those
        // follow the goods: a coupon on an exempt course is not a 21% discount, and pricing it as
        // one is a 21%-of-the-discount gap reconciliation would rightly refuse.
        if ($lineItem !== null ? $this->isExemptLine($lineItem) : $this->isExemptOrder($order)) {
            $code = $settings->getVatCodeForTreatment(VatHelper::TREATMENT_EXEMPT);

            // Unmapped, fall through to the sale's treatment; the settings screen lists Exempt as
            // unmapped once a category is marked exempt.
            if ($code !== null) {
                return $code;
            }
        }

        return $settings->getVatCodeForTreatment($treatment);
    }

    /**
     * Whether a line is a VAT-exempt supply: its tax category is marked exempt in the settings,
     * and Commerce charged it no tax (neither added nor included).
     */
    public function isExemptLine(LineItem $lineItem): bool
    {
        $exempt = Plugin::getInstance()->getSettings()->getExemptTaxCategoryIds();

        if ($exempt === [] || !in_array((int)$lineItem->taxCategoryId, $exempt, true)) {
            return false;
        }

        return abs((float)$lineItem->getTax()) < 0.005 && abs((float)$lineItem->getTaxIncluded()) < 0.005;
    }

    /**
     * Whether every line on the order is exempt and Commerce charged no tax anywhere on it.
     *
     * Mixed orders do not qualify: their shipping and discount keep the order's treatment, as
     * before. Splitting a discount across exempt and taxed lines would need one discount line per
     * VAT code; until then a mixed order with a discount may be refused by reconciliation, which
     * says so rather than booking a wrong total.
     */
    public function isExemptOrder(Order $order): bool
    {
        $lineItems = $order->getLineItems();

        if ($lineItems === [] || Plugin::getInstance()->getSettings()->getExemptTaxCategoryIds() === []) {
            return false;
        }

        foreach ($lineItems as $lineItem) {
            if (!$this->isExemptLine($lineItem)) {
                return false;
            }
        }

        return abs((float)$order->getTotalTax()) < 0.005 && abs((float)$order->getTotalTaxIncluded()) < 0.005;
    }

    /**
     * Every Commerce tax category, for the exempt checkboxes on the settings screen.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function getCommerceTaxCategoryOptions(): array
    {
        if (!Plugin::commerceIsReady()) {
            return [];
        }

        $options = [];

        foreach (\craft\commerce\Plugin::getInstance()->getTaxCategories()->getAllTaxCategories() as $category) {
            $options[] = ['label' => (string)$category->name, 'value' => (string)$category->id];
        }

        return $options;
    }

    /**
     * The Commerce tax rate IDs applied to a line.
     *
     * Tax adjustments carry the whole rate model in `sourceSnapshot`, which is the only place the
     * rate ID survives onto a completed order — the adjustment itself only records an amount.
     *
     * @return string[]
     */
    public function getTaxRateIdsForLine(Order $order, LineItem $lineItem): array
    {
        $ids = [];

        foreach ($order->getAdjustments() as $adjustment) {
            if ($adjustment->type !== 'tax') {
                continue;
            }

            if ($adjustment->lineItemId !== null && (int)$adjustment->lineItemId !== (int)$lineItem->id) {
                continue;
            }

            $snapshot = $adjustment->sourceSnapshot;

            if (is_array($snapshot) && isset($snapshot['id'])) {
                $ids[] = (string)$snapshot['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Every tax rate Commerce knows about, for the mapping table on the settings screen.
     *
     * @return array<int, array{id: string, name: string, rate: float}>
     */
    public function getCommerceTaxRates(): array
    {
        if (!Plugin::commerceIsReady()) {
            return [];
        }

        $rates = [];

        foreach (\craft\commerce\Plugin::getInstance()->getTaxRates()->getAllTaxRates() as $rate) {
            $rates[] = [
                'id' => (string)$rate->id,
                'name' => (string)$rate->name,
                'rate' => (float)$rate->rate,
            ];
        }

        return $rates;
    }

    /**
     * The VAT codes defined in the connected administration, for the mapping menus.
     *
     * Cached for an hour. Every one of these is a call out of a 60-per-minute budget, and a
     * settings screen that spends three of them on every render is a settings screen that
     * rate-limits the store.
     *
     * @return array<int, array{code: string, description: string, percentage: float}>
     */
    public function getExactVatCodes(bool $refresh = false): array
    {
        $division = Plugin::getInstance()->getOauth()->getDivision();

        if ($division === null) {
            return [];
        }

        $cacheKey = 'exactly.vatCodes.' . $division;
        $cache = Craft::$app->getCache();

        if (!$refresh) {
            $cached = $cache->get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $rows = Plugin::getInstance()->getApi()->getAll('vat/VATCodes', [
                'select' => 'Code,Description,Percentage,Type,IsBlocked',
                'filter' => 'IsBlocked eq false',
                'orderby' => 'Code',
            ], 10, ['action' => 'vat.codes', 'summary' => 'List VAT codes']);
        } catch (ApiException $e) {
            Craft::warning('Exactly could not list VAT codes: ' . $e->getMessage(), __METHOD__);

            return [];
        }

        $codes = [];

        foreach ($rows as $row) {
            $codes[] = [
                'code' => trim((string)($row['Code'] ?? '')),
                'description' => (string)($row['Description'] ?? ''),
                // Exact reports the percentage as a fraction: 0.21, not 21.
                'percentage' => (float)($row['Percentage'] ?? 0) * 100,
            ];
        }

        $cache->set($cacheKey, $codes, 3600);

        return $codes;
    }

    // VIES
    // -------------------------------------------------------------------------

    /**
     * Ask VIES whether a VAT number is really registered.
     *
     * Returns `true` (registered), `false` (definitely not), or `null` (the service could not
     * answer). The three-way answer matters: a member state's own system being down is not the
     * same as a customer making a number up, but for the purposes of charging VAT they are treated
     * the same, and the merchant is told which happened.
     */
    public function validateWithVies(string $vatNumber, ?string $countryCode = null): ?bool
    {
        $canonical = VatHelper::canonical($vatNumber, $countryCode);

        if ($canonical === '' || !VatHelper::isWellFormed($canonical)) {
            return false;
        }

        $prefix = substr($canonical, 0, 2);
        $number = substr($canonical, 2);

        $cacheKey = 'exactly.vies.' . $canonical;
        $cache = Craft::$app->getCache();
        $cached = $cache->get($cacheKey);

        if ($cached === 'valid') {
            return true;
        }

        if ($cached === 'invalid') {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();
        $started = microtime(true);

        try {
            $client = Craft::createGuzzleClient(['timeout' => max(1, $settings->viesTimeout)]);
            $response = $client->get(sprintf(self::VIES_URL, rawurlencode($prefix), rawurlencode($number)));
            $body = json_decode((string)$response->getBody(), true);

            $isValid = is_array($body) ? ($body['isValid'] ?? null) : null;

            Plugin::getInstance()->getLog()->write('vat.vies', [
                'method' => 'GET',
                'endpoint' => 'vies/' . $prefix,
                'statusCode' => $response->getStatusCode(),
                'durationMs' => (int)round((microtime(true) - $started) * 1000),
                'summary' => Craft::t('exactly', 'VIES check for {number}: {result}', [
                    'number' => $canonical,
                    'result' => $isValid === null ? 'unknown' : ($isValid ? 'valid' : 'invalid'),
                ]),
            ]);

            if ($isValid === null) {
                return null;
            }

            // Only a definite answer is cached. Caching "unknown" would keep a customer's valid
            // number rejected for a week because VIES had a bad afternoon.
            $cache->set($cacheKey, $isValid ? 'valid' : 'invalid', self::VIES_CACHE_SECONDS);

            return (bool)$isValid;
        } catch (\Throwable $e) {
            Plugin::getInstance()->getLog()->write('vat.vies', [
                'level' => \justinholtweb\exactly\models\LogEntry::LEVEL_WARNING,
                'method' => 'GET',
                'endpoint' => 'vies/' . $prefix,
                'durationMs' => (int)round((microtime(true) - $started) * 1000),
                'summary' => Craft::t('exactly', 'VIES could not be reached'),
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Read a field off an element without caring whether it is a native attribute or a custom
     * field, and without throwing when it is neither.
     */
    private function readField(mixed $source, string $handle): string
    {
        try {
            if ($source instanceof \craft\base\ElementInterface) {
                if ($source->canGetProperty($handle, true, false)) {
                    $value = $source->$handle;
                } else {
                    $value = $source->getFieldValue($handle);
                }
            } else {
                $value = $source->$handle ?? null;
            }
        } catch (\Throwable) {
            return '';
        }

        if (is_scalar($value)) {
            return trim((string)$value);
        }

        return '';
    }
}
