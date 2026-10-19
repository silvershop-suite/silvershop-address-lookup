<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Provider;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Registry of address-lookup providers, selected PER COUNTRY — the one nuance over the suite's other provider
 * registries (newsletter's single-active, conversion-tracking's multi-active). Providers declare themselves in
 * config (key => Injector service); `by_country` maps an ISO code to a provider key, with `default` as the global
 * fallback:
 *
 *   SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry:
 *     providers:
 *       pdok: '%$SilverShop\AddressLookup\Provider\PdokProvider'
 *       openplz: '%$SilverShop\AddressLookup\Provider\OpenPlzProvider'
 *       zippopotam: '%$SilverShop\AddressLookup\Provider\ZippopotamProvider'
 *     default: ~
 *     by_country: { NL: pdok, DE: openplz }
 *
 * {@see self::forCountry()} resolves by_country -> default -> null, and only ever returns a provider that is both
 * configured AND supports that country, so an unconfigured key or a country a provider cannot handle cleanly falls
 * back to the manual form.
 */
class AddressLookupProviderRegistry
{
    use Configurable;
    use Injectable;

    /**
     * @var array<string, string> provider key => Injector service name / class.
     */
    private static array $providers = [];

    /**
     * Provider key used for any country without a `by_country` entry, or null/'' for no global fallback (unmapped
     * countries then use the manual form).
     */
    private static ?string $default = null;

    /**
     * @var array<string, string> ISO 3166-1 alpha-2 (uppercase) => provider key.
     */
    private static array $by_country = [];

    /**
     * Every registered provider, keyed by its {@see AddressLookupProvider::getKey()}.
     *
     * @return array<string, AddressLookupProvider>
     */
    public function all(): array
    {
        $providers = [];
        foreach ((array) static::config()->get('providers') as $service) {
            if (!$service) {
                continue;
            }
            /** @var AddressLookupProvider $provider */
            $provider = Injector::inst()->get($service);
            $providers[$provider->getKey()] = $provider;
        }

        return $providers;
    }

    public function get(string $key): ?AddressLookupProvider
    {
        if ($key === '') {
            return null;
        }

        return $this->all()[$key] ?? null;
    }

    /**
     * The provider that should handle the given country, or null for the manual form. Tries the `by_country` entry
     * first, then `default`; a candidate is only returned when it is configured and supports the country.
     */
    public function forCountry(string $countryCode): ?AddressLookupProvider
    {
        $code = strtoupper(trim($countryCode));
        if ($code === '') {
            return null;
        }

        $byCountry = (array) static::config()->get('by_country');
        $default = (string) (static::config()->get('default') ?? '');

        $candidateKeys = [];
        if (isset($byCountry[$code]) && (string) $byCountry[$code] !== '') {
            $candidateKeys[] = (string) $byCountry[$code];
        }
        if ($default !== '' && !in_array($default, $candidateKeys, true)) {
            $candidateKeys[] = $default;
        }

        foreach ($candidateKeys as $key) {
            $provider = $this->get($key);
            if ($provider && $provider->isConfigured() && $provider->supportsCountry($code)) {
                return $provider;
            }
        }

        return null;
    }
}
