<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Extension;

use SilverShop\AddressFormats\AddressFormatRegistry;
use SilverShop\AddressLookup\Control\AddressLookupController;
use SilverShop\AddressLookup\Provider\AddressLookupProvider;
use SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry;
use SilverShop\AddressLookup\Service\AddressLookupService;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Security\SecurityToken;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Front-end wiring for checkout address autocomplete. Applied to {@link \SilverShop\Model\Address} and hooks the same
 * `updateFormFields` seam that silvershop/address-formats uses, so it runs after the per-country field rules are in
 * place. It injects a per-country config ({mode, endpoint, field map}) as inline JSON plus a vanilla, no-build
 * bootstrap script (the address-formats inline-script-via-LiteralField pattern — no theme edit, no asset pipeline).
 *
 * The script is scoped to `.silvershop-address-fields` and cooperates with address-formats' `addressformat:changed`
 * event: on a debounced change of PostalCode (+ HouseNumber where the country has one) it POSTs to the lookup
 * endpoint and pre-fills the street + city, left fully EDITABLE. JS-off, or a country with no configured provider,
 * leaves the plain manual form untouched.
 *
 * @property \SilverShop\Model\Address $owner
 */
class AddressLookupFormExtension extends Extension
{
    /**
     * Base field names the lookup fills, mapped to the resolved-address keys. Actual inputs may be component-
     * namespaced (ShippingAddress_PostalCode), which the client matches with a `[name$="_X"]` fallback.
     */
    private const FIELD_MAP = [
        'postalCode' => 'PostalCode',
        'houseNumber' => 'HouseNumber',
        'street' => 'Address',
        'city' => 'City',
        'state' => 'State',
    ];

    public function updateFormFields(FieldList $fields): void
    {
        $countries = $this->activeCountries();
        if ($countries === []) {
            // No country resolves to a configured provider -> leave the plain form alone.
            return;
        }

        $config = [
            'endpoint' => AddressLookupController::create()->Link('lookup'),
            'securityId' => SecurityToken::inst()->isEnabled() ? (string) SecurityToken::inst()->getValue() : '',
            'securityName' => SecurityToken::inst()->getName(),
            'defaultCountry' => (string) (SiteConfig::current_site_config()->getSingleCountry() ?: ''),
            'fields' => self::FIELD_MAP,
            'countries' => $countries,
            'i18nLooking' => _t(self::class . '.Looking', 'Looking up address…'),
        ];

        $json = json_encode($config, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $html = (string) ArrayData::create(['ConfigJson' => $json])
            ->renderWith('SilverShop/AddressLookup/Includes/AddressLookupScript');

        $fields->push(LiteralField::create('AddressLookupScript', $html));
    }

    /**
     * The countries that currently resolve to a configured, consent-allowed provider, each with its lookup mode. The
     * candidate set is every country address-formats knows about plus every `by_country` mapping.
     *
     * @return array<string, array<string, string>>
     */
    private function activeCountries(): array
    {
        $service = AddressLookupService::create();

        $candidates = array_map(
            'strtoupper',
            array_keys((array) AddressLookupProviderRegistry::config()->get('by_country'))
        );
        if (class_exists(AddressFormatRegistry::class)) {
            $candidates = array_merge($candidates, AddressFormatRegistry::singleton()->configuredCountries());
        }

        $active = [];
        foreach (array_unique($candidates) as $code) {
            $code = strtoupper((string) $code);
            $mode = $service->modeForCountry($code);
            if ($mode !== AddressLookupProvider::MODE_NONE) {
                $active[$code] = ['mode' => $mode];
            }
        }

        return $active;
    }
}
