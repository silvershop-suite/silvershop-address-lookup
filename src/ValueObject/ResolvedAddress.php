<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\ValueObject;

use JsonSerializable;

/**
 * A normalised address resolved by a provider. Immutable, and the one JSON shape the controller ships to the browser
 * regardless of which provider answered — so the frontend field-fill logic never has to know the provider. A
 * postcode-only provider (Zippopotam) leaves {@see self::$street} empty and {@see self::$exact} false; a
 * postcode-number provider (PDOK) fills the street and sets exact true.
 */
final class ResolvedAddress implements JsonSerializable
{
    public function __construct(
        public readonly string $street = '',
        public readonly string $houseNumber = '',
        public readonly string $suffix = '',
        public readonly string $postalCode = '',
        public readonly string $city = '',
        public readonly string $municipality = '',
        public readonly string $state = '',
        public readonly string $countryCode = '',
        public readonly ?float $lat = null,
        public readonly ?float $lng = null,
        public readonly bool $exact = false,
        public readonly string $providerRef = '',
    ) {
    }

    /**
     * The stable wire shape consumed by the frontend. Keys map onto the SilverShop address fields the JS fills
     * (street -> Address, city -> City, state -> State); the rest are informational.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'houseNumber' => $this->houseNumber,
            'suffix' => $this->suffix,
            'postalCode' => $this->postalCode,
            'city' => $this->city,
            'municipality' => $this->municipality,
            'state' => $this->state,
            'countryCode' => $this->countryCode,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'exact' => $this->exact,
            'providerRef' => $this->providerRef,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
