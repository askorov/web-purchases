<?php

declare(strict_types=1);

namespace Wowmaking\WebPurchases\Services;

final class VatRates
{
    /**
     * VAT rates by ISO 3166-1 alpha-2 country code.
     * Rates are percentages (e.g. 19.0 = 19%).
     *
     * @var array<string, array{name: string, rate: float}>
     */
    private const RATES = [
        'AT' => ['name' => 'Austria',        'rate' => 20.0],
        'BE' => ['name' => 'Belgium',        'rate' => 21.0],
        'BG' => ['name' => 'Bulgaria',       'rate' => 20.0],
        'HR' => ['name' => 'Croatia',        'rate' => 25.0],
        'CY' => ['name' => 'Cyprus',         'rate' => 19.0],
        'CZ' => ['name' => 'Czech Republic', 'rate' => 21.0],
        'DK' => ['name' => 'Denmark',        'rate' => 25.0],
        'EE' => ['name' => 'Estonia',        'rate' => 22.0],
        'FI' => ['name' => 'Finland',        'rate' => 25.5],
        'FR' => ['name' => 'France',         'rate' => 20.0],
        'DE' => ['name' => 'Germany',        'rate' => 19.0],
        'GR' => ['name' => 'Greece',         'rate' => 24.0],
        'HU' => ['name' => 'Hungary',        'rate' => 27.0],
        'IE' => ['name' => 'Ireland',        'rate' => 23.0],
        'IT' => ['name' => 'Italy',          'rate' => 22.0],
        'LV' => ['name' => 'Latvia',         'rate' => 21.0],
        'LT' => ['name' => 'Lithuania',      'rate' => 21.0],
        'LU' => ['name' => 'Luxembourg',     'rate' => 17.0],
        'MT' => ['name' => 'Malta',          'rate' => 18.0],
        'NL' => ['name' => 'Netherlands',    'rate' => 21.0],
        'PL' => ['name' => 'Poland',         'rate' => 23.0],
        'PT' => ['name' => 'Portugal',       'rate' => 23.0],
        'RO' => ['name' => 'Romania',        'rate' => 19.0],
        'SK' => ['name' => 'Slovakia',       'rate' => 23.0],
        'SI' => ['name' => 'Slovenia',       'rate' => 22.0],
        'ES' => ['name' => 'Spain',          'rate' => 21.0],
        'SE' => ['name' => 'Sweden',         'rate' => 25.0],
        'GB' => ['name' => 'United Kingdom', 'rate' => 20.0],
        'NO' => ['name' => 'Norway',         'rate' => 25.0],
        'CH' => ['name' => 'Switzerland',    'rate' => 8.1],
    ];

    /**
     * Get VAT rate for a country (alpha-2 code).
     *
     * @return float|null Rate as percentage (e.g. 19.0), or null if country is not in the list
     */
    public static function getRate(string $countryCode): ?float
    {
        return self::RATES[strtoupper($countryCode)]['rate'] ?? null;
    }

    /**
     * Get country name by alpha-2 code.
     */
    public static function getCountryName(string $countryCode): ?string
    {
        return self::RATES[strtoupper($countryCode)]['name'] ?? null;
    }

    /**
     * Get all rates.
     *
     * @return array<string, array{name: string, rate: float}>
     */
    public static function all(): array
    {
        return self::RATES;
    }

    /**
     * Check if a country code has a known VAT rate.
     */
    public static function has(string $countryCode): bool
    {
        return isset(self::RATES[strtoupper($countryCode)]);
    }

    /**
     * Calculate price with VAT added on top.
     * Returns the total amount the customer pays, rounded to 2 decimal places.
     */
    public static function addVat(float $netAmount, string $countryCode): ?float
    {
        $rate = self::getRate($countryCode);

        if ($rate === null) {
            return null;
        }

        return round($netAmount * (1 + $rate / 100), 2);
    }

    /**
     * Calculate VAT amount from a net price, rounded to 2 decimal places.
     */
    public static function vatAmount(float $netAmount, string $countryCode): ?float
    {
        $rate = self::getRate($countryCode);

        if ($rate === null) {
            return null;
        }

        return round($netAmount * $rate / 100, 2);
    }
}
