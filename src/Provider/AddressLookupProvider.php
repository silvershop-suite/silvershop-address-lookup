<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Provider;

use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\AddressValidationResult;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;

/**
 * The published seam between the address-lookup core and one address data source (PDOK, OpenPLZ, Zippopotam, and the
 * paid satellites MyParcel/Pro6PP/Loqate). A provider declares which countries it covers and, per country, which of
 * the three capabilities it offers — structured postcode+number {@see self::lookup()}, free-text {@see self::suggest()}
 * and {@see self::validate()} — so the {@see AddressLookupProviderRegistry} and
 * {@see \SilverShop\AddressLookup\Service\AddressLookupService} can route a request to the right source.
 *
 * A provider stays inert until {@see self::isConfigured()} is true (a keyless provider is always configured; a paid
 * one needs its env key), so the module is safe to install before a shop supplies credentials.
 */
interface AddressLookupProvider
{
    /** Structured lookup from a postcode + house number (NL/DE/BE…). */
    public const MODE_POSTCODE_NUMBER = 'postcode_number';

    /** Free-text typeahead on the street line (US / global). */
    public const MODE_AUTOCOMPLETE = 'autocomplete';

    /** No automatic lookup for this country — the plain manual form. */
    public const MODE_NONE = 'none';

    /**
     * Short, stable key used in the registry `providers` map and `by_country` routing (e.g. 'pdok', 'openplz').
     */
    public function getKey(): string;

    /**
     * Human-readable provider name, e.g. 'PDOK Locatieserver'.
     */
    public function getName(): string;

    /**
     * Whether the provider has everything it needs to run (a key where required). A keyless public API returns true
     * unconditionally; a paid provider returns false until its env key is present. An unconfigured provider is never
     * returned by the registry.
     */
    public function isConfigured(): bool;

    /**
     * Whether this provider can resolve addresses for the given ISO 3166-1 alpha-2 country code.
     */
    public function supportsCountry(string $countryCode): bool;

    /**
     * Which lookup flow the frontend should run for this country: one of the MODE_* constants. Returns
     * {@see self::MODE_NONE} for a country the provider does not support.
     */
    public function lookupMode(string $countryCode): string;

    /**
     * Resolve a structured postcode + house-number query to a single address, or null when nothing matches / the
     * provider cannot answer (callers treat null as a soft miss and leave the form manual).
     */
    public function lookup(AddressQuery $query): ?ResolvedAddress;

    /**
     * Free-text typeahead: up to a handful of candidate addresses for a partial term in the given country. Returns an
     * empty array for providers without a suggest capability.
     *
     * @return array<int, ResolvedAddress>
     */
    public function suggest(string $term, string $countryCode): array;

    /**
     * Validate / canonicalise an already-entered address. Providers without a validate capability return
     * {@see AddressValidationResult::unknown()} (a soft pass).
     */
    public function validate(ResolvedAddress $address): AddressValidationResult;

    /**
     * The cookieconsent category that must be granted before this provider may be called, or null for a
     * functional/necessary provider that needs no consent gate (the three bundled providers). A future gated
     * provider returns e.g. 'marketing'.
     */
    public function consentCategory(): ?string;
}
