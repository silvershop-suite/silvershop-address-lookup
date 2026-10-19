<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Consent;

use SilverShop\AddressLookup\Provider\AddressLookupProvider;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * The single binding point to silvershop/cookieconsent. The three bundled providers are functional/necessary (EU
 * government / EU logistics processors used only to fulfil the order) and declare no consent category, so this
 * resolver passes them through untouched. It exists for a FUTURE gated provider (e.g. a non-EU one declaring
 * `consent_category: marketing`): only then is cookieconsent consulted.
 *
 * States, mirroring conversion-tracking's gate:
 *  - provider has no category  -> always allowed (functional).
 *  - category + cookieconsent installed -> allowed iff ConsentState::has(category) (fail-closed before a decision).
 *  - category + cookieconsent absent -> the `assume_consent_without_module` default (false = deny).
 */
class ConsentResolver
{
    use Injectable;
    use Configurable;

    /**
     * Only when cookieconsent is NOT installed: assume consent for a gated provider. Default false (no consent
     * source -> a gated provider does not run). The bundled providers are ungated and unaffected either way.
     */
    private static bool $assume_consent_without_module = false;

    /**
     * The cookieconsent class that signals its consent API is present. Overridable in config (to a non-existent
     * class) so the fallback branch can be exercised deterministically in a test.
     */
    private static string $consent_state_class = \SilverShop\CookieConsent\ConsentState::class;

    /**
     * Whether a lookup through this provider may run right now, given its declared consent category (if any).
     */
    public function allows(AddressLookupProvider $provider): bool
    {
        $category = $provider->consentCategory();
        if ($category === null || $category === '') {
            return true;
        }

        return $this->categoryActive($category);
    }

    private function categoryActive(string $category): bool
    {
        $class = (string) static::config()->get('consent_state_class');
        if (class_exists($class)) {
            return (bool) $class::has($category);
        }

        return (bool) static::config()->get('assume_consent_without_module');
    }
}
