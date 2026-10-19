<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Provider;

use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;

/**
 * DACH address lookup via the free, keyless OpenPLZ API (German/Austrian/Swiss postal datasets). Given a postcode it
 * resolves the city (and region), and — where the postcode maps to a single street — the street too. House numbers
 * are not resolved (OpenPLZ has no premise data), so the number stays whatever the customer typed and the street is
 * only auto-filled when unambiguous.
 *
 * Liechtenstein shares the Swiss postal system, so LI is served from the `ch` dataset.
 *
 * @see https://openplzapi.org
 */
class OpenPlzProvider extends AbstractHttpProvider
{
    private static string $endpoint = 'https://openplzapi.org';

    /** ISO country code => OpenPLZ dataset path segment. */
    private const COUNTRY_SEGMENTS = [
        'DE' => 'de',
        'AT' => 'at',
        'CH' => 'ch',
        'LI' => 'ch',
    ];

    public function getKey(): string
    {
        return 'openplz';
    }

    public function getName(): string
    {
        return 'OpenPLZ';
    }

    public function supportsCountry(string $countryCode): bool
    {
        return isset(self::COUNTRY_SEGMENTS[strtoupper(trim($countryCode))]);
    }

    public function lookup(AddressQuery $query): ?ResolvedAddress
    {
        $code = strtoupper(trim($query->countryCode));
        if (!isset(self::COUNTRY_SEGMENTS[$code]) || !$query->hasPostcode()) {
            return null;
        }

        $segment = self::COUNTRY_SEGMENTS[$code];
        $postcode = trim((string) preg_replace('/\s+/', '', $query->postalCode));
        $base = rtrim((string) static::config()->get('endpoint'), '/');

        // Streets carry both the locality (city) and — when the postcode maps to one street — the street name.
        $streets = $this->getJson($base . '/' . $segment . '/Streets', [
            'postalCode' => $postcode,
            'page' => 1,
            'pageSize' => 50,
        ]);

        if ($streets !== [] && isset($streets[0]) && is_array($streets[0])) {
            return $this->fromStreets($streets, $code, $postcode, $query);
        }

        // Fallback: resolve just the city from the postcode.
        $localities = $this->getJson($base . '/' . $segment . '/Localities', [
            'postalCode' => $postcode,
            'page' => 1,
            'pageSize' => 1,
        ]);
        $locality = $localities[0] ?? null;
        if (!is_array($locality) || ($locality['name'] ?? '') === '') {
            return null;
        }

        $municipality = $this->nameOf($locality['municipality'] ?? null)
            ?: $this->nameOf($locality['commune'] ?? null);

        return new ResolvedAddress(
            street: '',
            houseNumber: $query->houseNumber,
            suffix: $query->suffix,
            postalCode: $postcode,
            city: (string) $locality['name'],
            municipality: $municipality,
            state: $this->regionName($locality),
            countryCode: $code,
            exact: false,
            providerRef: $this->getKey(),
        );
    }

    /**
     * Build a resolved address from the Streets response: city from the shared locality, and the street only when the
     * postcode maps to a single street name (otherwise left blank for the customer to complete).
     *
     * @param array<int, mixed> $streets
     */
    private function fromStreets(array $streets, string $code, string $postcode, AddressQuery $query): ResolvedAddress
    {
        $first = is_array($streets[0]) ? $streets[0] : [];

        $names = [];
        foreach ($streets as $row) {
            if (is_array($row) && ($row['name'] ?? '') !== '') {
                $names[(string) $row['name']] = true;
            }
        }
        $street = count($names) === 1 ? (string) array_key_first($names) : '';

        return new ResolvedAddress(
            street: $street,
            houseNumber: $query->houseNumber,
            suffix: $query->suffix,
            postalCode: $postcode,
            city: (string) ($first['locality'] ?? ''),
            municipality: (string) ($first['suburb'] ?? $first['borough'] ?? ''),
            state: $this->regionName($first),
            countryCode: $code,
            exact: $street !== '',
            providerRef: $this->getKey(),
        );
    }

    /**
     * The region name across the DACH datasets: DE federalState, AT federalProvince, CH/LI canton.
     *
     * @param array<string, mixed> $row
     */
    private function regionName(array $row): string
    {
        foreach (['federalState', 'federalProvince', 'canton'] as $key) {
            $name = $this->nameOf($row[$key] ?? null);
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * The `name` of a nested OpenPLZ object ({"key":…,"name":…}), or '' when absent.
     */
    private function nameOf(mixed $node): string
    {
        return is_array($node) ? (string) ($node['name'] ?? '') : '';
    }
}
