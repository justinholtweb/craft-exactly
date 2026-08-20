<?php

namespace justinholtweb\exactly\helpers;

/**
 * EU VAT: who the customer is, and therefore which VAT code the invoice line takes.
 *
 * This is the part of an Exact Online integration that is not about Exact Online at all. A push
 * that hard-codes one VAT code produces books that are wrong for every cross-border order, and
 * the merchant does not find out until their accountant does. So the treatment is decided here,
 * from the shipping country and the customer's VAT number, and the settings map a treatment onto
 * whichever VAT code that particular administration uses for it.
 *
 * Structural validation only — a well-formed number is not a *real* one. `services\Vat` can check
 * it against VIES when the merchant asks for that.
 */
abstract class Vat
{
    public const TREATMENT_DOMESTIC = 'domestic';
    public const TREATMENT_REVERSE_CHARGE = 'reverse-charge';
    public const TREATMENT_OSS = 'oss';
    public const TREATMENT_EXPORT = 'export';
    public const TREATMENT_EXEMPT = 'exempt';

    /**
     * The 27 member states, by ISO 3166-1 alpha-2.
     */
    public const MEMBER_STATES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE',
        'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    /**
     * VAT-number prefixes that are not the country's ISO code.
     *
     * Greece files VAT under `EL`, and Northern Ireland kept an EU-facing `XI` prefix after Brexit
     * — a `GR…` or `GB…` number therefore fails a naive `substr($number, 0, 2) === $country`
     * check while being perfectly valid, which silently turns a reverse-charge sale into a
     * domestic one.
     */
    public const PREFIX_OVERRIDES = [
        'GR' => 'EL',
        'GB' => 'XI',
    ];

    /**
     * Structural patterns, keyed by VAT prefix (not by ISO country code).
     */
    public const PATTERNS = [
        'AT' => '/^U\d{8}$/',
        'BE' => '/^[01]\d{9}$/',
        'BG' => '/^\d{9,10}$/',
        'CY' => '/^\d{8}[A-Z]$/',
        'CZ' => '/^\d{8,10}$/',
        'DE' => '/^\d{9}$/',
        'DK' => '/^\d{8}$/',
        'EE' => '/^\d{9}$/',
        'EL' => '/^\d{9}$/',
        'ES' => '/^([A-Z]\d{7}[A-Z]|\d{8}[A-Z]|[A-Z]\d{8})$/',
        'FI' => '/^\d{8}$/',
        'FR' => '/^[A-Z0-9]{2}\d{9}$/',
        'HR' => '/^\d{11}$/',
        'HU' => '/^\d{8}$/',
        'IE' => '/^(\d{7}[A-W][A-IW]?|\d[A-Z*+]\d{5}[A-W])$/',
        'IT' => '/^\d{11}$/',
        'LT' => '/^(\d{9}|\d{12})$/',
        'LU' => '/^\d{8}$/',
        'LV' => '/^\d{11}$/',
        'MT' => '/^\d{8}$/',
        'NL' => '/^\d{9}B\d{2}$/',
        'PL' => '/^\d{10}$/',
        'PT' => '/^\d{9}$/',
        'RO' => '/^\d{2,10}$/',
        'SE' => '/^\d{12}$/',
        'SI' => '/^\d{8}$/',
        'SK' => '/^\d{10}$/',
        'XI' => '/^(\d{9}|\d{12}|(GD|HA)\d{3})$/',
    ];

    public static function isMemberState(?string $countryCode): bool
    {
        return $countryCode !== null && in_array(strtoupper(trim($countryCode)), self::MEMBER_STATES, true);
    }

    /**
     * The VAT prefix a country files under.
     */
    public static function prefixForCountry(string $countryCode): string
    {
        $countryCode = strtoupper(trim($countryCode));

        return self::PREFIX_OVERRIDES[$countryCode] ?? $countryCode;
    }

    /**
     * Strip everything a human might have typed around the number.
     *
     * Customers paste `NL 8025.13.146.B.01`, `nl802513146b01`, and every arrangement in between.
     */
    public static function normalize(?string $number): string
    {
        if ($number === null) {
            return '';
        }

        $number = strtoupper($number);
        $number = preg_replace('/[^A-Z0-9]/', '', $number) ?? '';

        return $number;
    }

    /**
     * Split a normalised number into its prefix and body, if it carries a recognised prefix.
     *
     * @return array{0: string|null, 1: string}
     */
    public static function split(?string $number): array
    {
        $number = self::normalize($number);

        if (strlen($number) < 3) {
            return [null, $number];
        }

        $prefix = substr($number, 0, 2);

        if (!isset(self::PATTERNS[$prefix])) {
            return [null, $number];
        }

        return [$prefix, substr($number, 2)];
    }

    /**
     * Whether a VAT number is structurally valid, optionally for a specific country.
     *
     * A bare number with no prefix is validated against `$countryCode` when one is given, because
     * merchants routinely store the number without its country prefix in a plain text field.
     */
    public static function isWellFormed(?string $number, ?string $countryCode = null): bool
    {
        [$prefix, $body] = self::split($number);

        if ($prefix === null) {
            if ($countryCode === null || $body === '') {
                return false;
            }

            $prefix = self::prefixForCountry($countryCode);
        } elseif ($countryCode !== null && $prefix !== self::prefixForCountry($countryCode)) {
            // A German VAT number on a French address is not a formatting problem — it is either a
            // typo or a different legal entity, and either way it must not trigger reverse charge.
            return false;
        }

        $pattern = self::PATTERNS[$prefix] ?? null;

        return $pattern !== null && (bool)preg_match($pattern, $body);
    }

    /**
     * The canonical, prefixed form — what goes on the invoice and to VIES.
     */
    public static function canonical(?string $number, ?string $countryCode = null): string
    {
        [$prefix, $body] = self::split($number);

        if ($prefix === null) {
            if ($body === '' || $countryCode === null) {
                return $body;
            }

            $prefix = self::prefixForCountry($countryCode);
        }

        return $prefix . $body;
    }

    /**
     * Decide the VAT treatment for a sale.
     *
     * @param string|null $sellerCountry ISO code of the administration issuing the invoice
     * @param string|null $buyerCountry ISO code the goods or services are supplied to
     * @param string|null $buyerVatNumber As captured on the order, in any format
     * @param bool $requireValidVatNumber Whether reverse charge needs a structurally valid number
     */
    public static function treatment(
        ?string $sellerCountry,
        ?string $buyerCountry,
        ?string $buyerVatNumber = null,
        bool $requireValidVatNumber = true,
    ): string {
        $sellerCountry = strtoupper(trim((string)$sellerCountry));
        $buyerCountry = strtoupper(trim((string)$buyerCountry));

        // No destination to reason about. Domestic is the only safe default: it is the treatment
        // that charges VAT, and over-charging is recoverable in a way that under-charging is not.
        if ($buyerCountry === '') {
            return self::TREATMENT_DOMESTIC;
        }

        if ($sellerCountry !== '' && $buyerCountry === $sellerCountry) {
            return self::TREATMENT_DOMESTIC;
        }

        if (!self::isMemberState($buyerCountry)) {
            return self::TREATMENT_EXPORT;
        }

        $hasNumber = self::normalize($buyerVatNumber) !== '';

        if ($hasNumber && (!$requireValidVatNumber || self::isWellFormed($buyerVatNumber, $buyerCountry))) {
            return self::TREATMENT_REVERSE_CHARGE;
        }

        // EU consumer in another member state: destination VAT, reported through One Stop Shop.
        return self::TREATMENT_OSS;
    }

    /**
     * @return array<string, string> treatment => human label
     */
    public static function treatments(): array
    {
        return [
            self::TREATMENT_DOMESTIC => 'Domestic',
            self::TREATMENT_REVERSE_CHARGE => 'Intra-community reverse charge',
            self::TREATMENT_OSS => 'EU consumer (OSS)',
            self::TREATMENT_EXPORT => 'Export outside the EU',
            self::TREATMENT_EXEMPT => 'Exempt',
        ];
    }
}
