<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Tests;

use SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry;
use SilverShop\Model\Address;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Security\SecurityToken;

/**
 * The guarded frontend extension: it injects the per-country config + bootstrap only when a country actually resolves
 * to a configured provider, and otherwise leaves the plain manual form untouched (progressive enhancement).
 */
class AddressLookupFormExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected bool $tokenWasEnabled = false;

    protected function setUp(): void
    {
        parent::setUp();
        // The extension only reads the token value when it is enabled; disable it so the unit test needs no live
        // request/session for SecurityToken.
        $this->tokenWasEnabled = SecurityToken::is_enabled();
        SecurityToken::disable();

        Injector::inst()->registerService(new FakeLookupProvider('fake', ['NL'], true), 'FakeForm');
        Config::modify()->set(AddressLookupProviderRegistry::class, 'providers', ['fake' => '%$FakeForm']);
        Config::modify()->set(AddressLookupProviderRegistry::class, 'by_country', ['NL' => 'fake']);
        Config::modify()->set(AddressLookupProviderRegistry::class, 'default', null);
    }

    protected function tearDown(): void
    {
        if ($this->tokenWasEnabled) {
            SecurityToken::enable();
        }
        parent::tearDown();
    }

    private function script(Address $address): ?LiteralField
    {
        $field = $address->getFrontEndFields()->fieldByName('AddressLookupScript');

        return $field instanceof LiteralField ? $field : null;
    }

    public function testInjectsConfigForAConfiguredCountry(): void
    {
        $script = $this->script(Address::create());
        $this->assertNotNull($script, 'The config + bootstrap is injected when a provider is active');

        $content = $script->getContent();
        $this->assertStringContainsString('"NL"', $content, 'The active country is advertised');
        $this->assertStringContainsString('postcode_number', $content, 'With its lookup mode');
        $this->assertStringContainsString('shop-address-lookup', $content, 'And the lookup endpoint');
    }

    public function testNoInjectionWhenNoProviderConfigured(): void
    {
        // Strip every provider -> no country resolves -> the plain form is left alone.
        Config::modify()->set(AddressLookupProviderRegistry::class, 'providers', []);

        $this->assertNull($this->script(Address::create()));
    }

    public function testManualAddressFieldsAlwaysPresent(): void
    {
        // Progressive enhancement: the full manual field set is always there, injection or not.
        $fields = Address::create()->getFrontEndFields();
        $this->assertNotNull($fields->dataFieldByName('Address'));
        $this->assertNotNull($fields->dataFieldByName('City'));
        $this->assertNotNull($fields->dataFieldByName('PostalCode'));
    }
}
