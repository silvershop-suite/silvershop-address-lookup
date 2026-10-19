<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Provider;

use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;

/**
 * Global postcode -> city/region lookup via the free, keyless Zippopotam API (60+ countries). Resolves the city,
 * state/region and lat/lng from a postcode; it has NO street or house-number data, so the street line stays manual.
 * The pragmatic free global fallback — map a country to it (it is registered but unmapped by default) to autofill
 * city from the postcode everywhere Zippopotam has coverage.
 *
 * @see https://api.zippopotam.us
 */
class ZippopotamProvider extends AbstractHttpProvider
{
    private static string $endpoint = 'https://api.zippopotam.us';

    public function getKey(): string
    {
        return 'zippopotam';
    }

    public function getName(): string
    {
        return 'Zippopotam';
    }

    public function supportsCountry(string $countryCode): bool
    {
        // Zippopotam covers a fixed ~60-country list with no capabilities endpoint; accept any ISO alpha-2 and let a
        // miss resolve to null (the manual form). The registry only routes here for an explicitly mapped country.
        return (bool) preg_match('/^[A-Za-z]{2}$/', trim($countryCode));
    }

    public function lookup(AddressQuery $query): ?ResolvedAddress
    {
        $code = strtoupper(trim($query->countryCode));
        if (!$this->supportsCountry($code) || !$query->hasPostcode()) {
            return null;
        }

        $base = rtrim((string) static::config()->get('endpoint'), '/');
        $postcode = rawurlencode($this->normalisePostcode($code, $query->postalCode));
        $url = $base . '/' . strtolower($code) . '/' . $postcode;

        $data = $this->getJson($url);
        $place = $data['places'][0] ?? null;
        if (!is_array($place) || ($place['place name'] ?? '') === '') {
            return null;
        }

        return new ResolvedAddress(
            street: '',
            houseNumber: $query->houseNumber,
            suffix: $query->suffix,
            postalCode: (string) ($data['post code'] ?? $query->postalCode),
            city: (string) $place['place name'],
            municipality: '',
            state: (string) ($place['state'] ?? ''),
            countryCode: $code,
            lat: isset($place['latitude']) ? (float) $place['latitude'] : null,
            lng: isset($place['longitude']) ? (float) $place['longitude'] : null,
            exact: false,
            providerRef: $this->getKey(),
        );
    }

    /**
     * Zippopotam keys NL on the 4-digit numeric part only; elsewhere the full postcode (spaces stripped) works.
     */
    private function normalisePostcode(string $code, string $postcode): string
    {
        $compact = (string) preg_replace('/\s+/', '', trim($postcode));
        if ($code === 'NL' && preg_match('/^([0-9]{4})/', $compact, $m)) {
            return $m[1];
        }

        return $compact;
    }
}
