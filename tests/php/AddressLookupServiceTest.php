<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Tests;

use SilverShop\AddressLookup\Consent\ConsentResolver;
use SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry;
use SilverShop\AddressLookup\Service\AddressLookupService;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * The service orchestration: the consent gate (functional providers pass; a gated provider is withheld until its
 * category is granted) and the soft-fail wrapper (a provider fault degrades to null, never an exception).
 */
class AddressLookupServiceTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const MISSING_CONSENT_CLASS = 'SilverShop\\AddressLookup\\Tests\\NoSuchConsentState';

    private function wire(FakeLookupProvider $provider): void
    {
        Injector::inst()->registerService($provider, 'SvcFake');
        Config::modify()->set(AddressLookupProviderRegistry::class, 'providers', ['fake' => '%$SvcFake']);
        Config::modify()->set(AddressLookupProviderRegistry::class, 'by_country', ['NL' => 'fake']);
        Config::modify()->set(AddressLookupProviderRegistry::class, 'default', null);
        // Point the consent resolver at a class that does not exist, so the fallback (default deny) branch is taken
        // deterministically regardless of whether cookieconsent happens to be installed in the clean-room.
        Config::modify()->set(ConsentResolver::class, 'consent_state_class', self::MISSING_CONSENT_CLASS);
        Config::modify()->set(ConsentResolver::class, 'assume_consent_without_module', false);
    }

    private function nlAddress(): ResolvedAddress
    {
        return new ResolvedAddress(street: 'Dam', city: 'Amsterdam', countryCode: 'NL', providerRef: 'fake');
    }

    public function testFunctionalProviderRuns(): void
    {
        $this->wire(new FakeLookupProvider('fake', ['NL'], true, resolved: $this->nlAddress()));

        $address = AddressLookupService::create()->lookup(new AddressQuery('NL', '1012 JS', '1'));
        $this->assertNotNull($address);
        $this->assertSame('Amsterdam', $address->city);
    }

    public function testGatedProviderWithheldWithoutConsent(): void
    {
        // A provider declaring a consent category, with no consent source + assume-off => withheld.
        $this->wire(new FakeLookupProvider('fake', ['NL'], true, category: 'marketing', resolved: $this->nlAddress()));

        $service = AddressLookupService::create();
        $this->assertNull($service->providerForCountry('NL'), 'Gated provider is not offered without consent');
        $this->assertSame('none', $service->modeForCountry('NL'));
        $this->assertNull($service->lookup(new AddressQuery('NL', '1012 JS', '1')));
    }

    public function testGatedProviderRunsWhenConsentAssumed(): void
    {
        $this->wire(new FakeLookupProvider('fake', ['NL'], true, category: 'marketing', resolved: $this->nlAddress()));
        Config::modify()->set(ConsentResolver::class, 'assume_consent_without_module', true);

        $address = AddressLookupService::create()->lookup(new AddressQuery('NL', '1012 JS', '1'));
        $this->assertNotNull($address);
        $this->assertSame('Amsterdam', $address->city);
    }

    public function testProviderFaultDegradesToNull(): void
    {
        $this->wire(new FakeLookupProvider('fake', ['NL'], true, throws: true));

        // The provider throws; the service must swallow it into a soft miss so checkout stays usable.
        $this->assertNull(AddressLookupService::create()->lookup(new AddressQuery('NL', '1012 JS', '1')));
    }

    public function testModeForConfiguredCountry(): void
    {
        $this->wire(new FakeLookupProvider('fake', ['NL'], true, resolved: $this->nlAddress()));

        $this->assertSame('postcode_number', AddressLookupService::create()->modeForCountry('NL'));
        $this->assertSame('none', AddressLookupService::create()->modeForCountry('FR'));
    }
}
