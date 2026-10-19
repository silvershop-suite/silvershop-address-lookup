<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Tests;

use SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Per-country routing in the registry: by_country -> default -> null, only ever returning a provider that is both
 * configured AND supports the country (otherwise the country falls through to default, then to the manual form).
 */
class AddressLookupProviderRegistryTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Register four differently-shaped fakes as Injector named services.
        Injector::inst()->registerService(new FakeLookupProvider('fnl', ['NL'], true), 'FakeNl');
        Injector::inst()->registerService(new FakeLookupProvider('fde', ['DE'], true), 'FakeDe');
        Injector::inst()->registerService(new FakeLookupProvider('fglobal', ['*'], true), 'FakeGlobal');
        Injector::inst()->registerService(new FakeLookupProvider('funconf', ['NL'], false), 'FakeUnconf');

        Config::modify()->set(AddressLookupProviderRegistry::class, 'providers', [
            'fnl' => '%$FakeNl',
            'fde' => '%$FakeDe',
            'fglobal' => '%$FakeGlobal',
            'funconf' => '%$FakeUnconf',
        ]);
    }

    private function registry(): AddressLookupProviderRegistry
    {
        return AddressLookupProviderRegistry::create();
    }

    public function testByCountryResolvesTheMappedProvider(): void
    {
        Config::modify()->set(AddressLookupProviderRegistry::class, 'by_country', ['NL' => 'fnl', 'DE' => 'fde']);
        Config::modify()->set(AddressLookupProviderRegistry::class, 'default', null);

        $this->assertSame('fnl', $this->registry()->forCountry('NL')?->getKey());
        $this->assertSame('fde', $this->registry()->forCountry('DE')?->getKey());
    }

    public function testCountryCodeIsCaseInsensitive(): void
    {
        Config::modify()->set(AddressLookupProviderRegistry::class, 'by_country', ['NL' => 'fnl']);

        $this->assertSame('fnl', $this->registry()->forCountry('nl')?->getKey());
    }

    public function testUnmappedCountryWithNoDefaultIsNull(): void
    {
        Config::modify()->set(AddressLookupProviderRegistry::class, 'by_country', ['NL' => 'fnl']);
        Config::modify()->set(AddressLookupProviderRegistry::class, 'default', null);

        $this->assertNull($this->registry()->forCountry('US'));
    }

    public function testUnmappedCountryFallsBackToDefault(): void
    {
        Config::modify()->set(AddressLookupProviderRegistry::class, 'by_country', ['NL' => 'fnl']);
        Config::modify()->set(AddressLookupProviderRegistry::class, 'default', 'fglobal');

        $this->assertSame('fglobal', $this->registry()->forCountry('US')?->getKey());
    }

    public function testMappedButUnsupportedCountryFallsThroughToDefault(): void
    {
        // FR is mapped to the NL-only provider; it cannot handle FR, so routing falls through to the global default.
        Config::modify()->set(AddressLookupProviderRegistry::class, 'by_country', ['FR' => 'fnl']);
        Config::modify()->set(AddressLookupProviderRegistry::class, 'default', 'fglobal');

        $this->assertSame('fglobal', $this->registry()->forCountry('FR')?->getKey());
    }

    public function testUnconfiguredProviderIsNeverReturned(): void
    {
        Config::modify()->set(AddressLookupProviderRegistry::class, 'by_country', ['NL' => 'funconf']);
        Config::modify()->set(AddressLookupProviderRegistry::class, 'default', null);

        $this->assertNull($this->registry()->forCountry('NL'));
    }

    public function testGetAndAllExposeRegisteredProviders(): void
    {
        $registry = $this->registry();
        $this->assertSame('fnl', $registry->get('fnl')?->getKey());
        $this->assertNull($registry->get('does-not-exist'));
        $this->assertArrayHasKey('fglobal', $registry->all());
        $this->assertCount(4, $registry->all());
    }
}
