<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Provider;

use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;

/**
 * NL address lookup via the PDOK Locatieserver (the Dutch government BAG geocoder). Free, keyless and the correct
 * source for the standard NL flow: postcode + house number -> street + city. Resolves to a single exact address.
 *
 * @see https://api.pdok.nl/bzk/locatieserver/search/v3_1/free
 */
class PdokProvider extends AbstractHttpProvider
{
    /**
     * The Locatieserver "free" search endpoint. Overridable in config (e.g. to pin a mirror).
     */
    private static string $endpoint = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/free';

    public function getKey(): string
    {
        return 'pdok';
    }

    public function getName(): string
    {
        return 'PDOK Locatieserver';
    }

    public function supportsCountry(string $countryCode): bool
    {
        return strtoupper(trim($countryCode)) === 'NL';
    }

    public function lookup(AddressQuery $query): ?ResolvedAddress
    {
        if (!$this->supportsCountry($query->countryCode) || !$query->hasPostcodeNumber()) {
            return null;
        }

        // Normalise the postcode to the compact "1012JS" the geocoder indexes, and take the numeric part of the
        // house number (a typed "22A" filters on 22; the suffix variants share street + city).
        $postcode = strtoupper((string) preg_replace('/\s+/', '', $query->postalCode));
        if (!preg_match('/\d+/', $query->houseNumber, $nm)) {
            return null;
        }
        $number = $nm[0];

        // Filter by postcode AND house number (fq), not a free-text `q` — a free-text match returns a misleading
        // nearby address for a non-existent number, whereas an exact filter correctly returns nothing (soft miss).
        $fl = 'straatnaam,huisnummer,huis_nlt,postcode,woonplaatsnaam,gemeentenaam,provincienaam,centroide_ll';
        $queryString = implode('&', [
            'q=' . rawurlencode('*'),
            'fq=' . rawurlencode('type:adres'),
            'fq=' . rawurlencode('postcode:' . $postcode),
            'fq=' . rawurlencode('huisnummer:' . $number),
            'rows=1',
            'fl=' . rawurlencode($fl),
            'wt=json',
        ]);

        $data = $this->getJson((string) static::config()->get('endpoint') . '?' . $queryString);

        $doc = $data['response']['docs'][0] ?? null;
        if (!is_array($doc) || ($doc['straatnaam'] ?? '') === '') {
            return null;
        }

        [$lat, $lng] = $this->parsePoint((string) ($doc['centroide_ll'] ?? ''));

        return new ResolvedAddress(
            street: (string) $doc['straatnaam'],
            houseNumber: trim((string) ($doc['huis_nlt'] ?? $doc['huisnummer'] ?? $query->houseNumber)),
            suffix: $query->suffix,
            postalCode: $this->formatPostcode((string) ($doc['postcode'] ?? $postcode)),
            city: (string) ($doc['woonplaatsnaam'] ?? ''),
            municipality: (string) ($doc['gemeentenaam'] ?? ''),
            state: (string) ($doc['provincienaam'] ?? ''),
            countryCode: 'NL',
            lat: $lat,
            lng: $lng,
            exact: true,
            providerRef: $this->getKey(),
        );
    }

    /**
     * Parse a Locatieserver "POINT(lng lat)" WKT string into [lat, lng], or [null, null] when absent/malformed.
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function parsePoint(string $wkt): array
    {
        if (preg_match('/POINT\(\s*([0-9.\-]+)\s+([0-9.\-]+)\s*\)/i', $wkt, $m)) {
            return [(float) $m[2], (float) $m[1]];
        }

        return [null, null];
    }

    /**
     * Canonical NL postcode "1012 JS" (4 digits, space, 2 upper letters). Falls back to the input uppercased.
     */
    private function formatPostcode(string $postcode): string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $postcode));

        return preg_match('/^([0-9]{4})([A-Z]{2})$/', $compact, $m) ? $m[1] . ' ' . $m[2] : strtoupper(trim($postcode));
    }
}
