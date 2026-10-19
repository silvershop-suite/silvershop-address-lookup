<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\ValueObject;

/**
 * An immutable address-lookup request. The structured key for a postcode-number lookup (NL/DE: country + postcode +
 * house number, optional suffix) and the free-text term for a typeahead suggest. Providers read only the parts their
 * {@see \SilverShop\AddressLookup\Provider\AddressLookupProvider::lookupMode()} needs.
 */
final class AddressQuery
{
    public function __construct(
        public readonly string $countryCode,
        public readonly string $postalCode = '',
        public readonly string $houseNumber = '',
        public readonly string $suffix = '',
        public readonly string $term = '',
    ) {
    }

    /**
     * Build a query from a loosely-typed map (e.g. a decoded JSON POST body), trimming values and upper-casing the
     * ISO country code. Unknown keys are ignored.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            strtoupper(trim((string) ($data['country'] ?? $data['countryCode'] ?? ''))),
            trim((string) ($data['postalCode'] ?? $data['postcode'] ?? '')),
            trim((string) ($data['houseNumber'] ?? $data['number'] ?? '')),
            trim((string) ($data['suffix'] ?? '')),
            trim((string) ($data['term'] ?? $data['query'] ?? '')),
        );
    }

    /**
     * Whether this query has the structured parts a postcode-number lookup needs.
     */
    public function hasPostcodeNumber(): bool
    {
        return $this->postalCode !== '' && $this->houseNumber !== '';
    }

    /**
     * Whether this query carries at least a postcode (enough for a postcode-only provider such as Zippopotam).
     */
    public function hasPostcode(): bool
    {
        return $this->postalCode !== '';
    }
}
