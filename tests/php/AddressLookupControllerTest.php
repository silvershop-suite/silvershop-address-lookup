<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Tests;

use SilverShop\AddressLookup\Control\AddressLookupController;
use SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\SecurityToken;

/**
 * The POST contract of the one routed endpoint: POST-only (PII in the body, never the URL), same-origin + CSRF,
 * graceful soft answers, and the stable {ok, address} JSON shape. The per-country routing itself is covered by the
 * registry/service tests; here a fake provider supplies a canned address so the controller glue is isolated.
 */
class AddressLookupControllerTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected bool $tokenWasEnabled = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenWasEnabled = SecurityToken::is_enabled();
        SecurityToken::disable();

        $resolved = new ResolvedAddress(
            street: 'Dam',
            houseNumber: '1',
            postalCode: '1012 JS',
            city: 'Amsterdam',
            countryCode: 'NL',
            exact: true,
            providerRef: 'fake',
        );
        Injector::inst()->registerService(
            new FakeLookupProvider('fake', ['NL'], true, resolved: $resolved),
            'FakeAddr'
        );
        Config::modify()->set(AddressLookupProviderRegistry::class, 'providers', ['fake' => '%$FakeAddr']);
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

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    private function postJson(array $payload, array $headers = [], string $method = 'POST'): HTTPResponse
    {
        $request = new HTTPRequest($method, '/shop-address-lookup/lookup');
        $request->setBody((string) json_encode($payload));
        $request->addHeader('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            $request->addHeader($name, $value);
        }
        $request->setSession(new Session([]));
        Injector::inst()->registerService($request, HTTPRequest::class);

        $controller = AddressLookupController::create();
        $controller->setRequest($request);

        return $controller->lookup($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(HTTPResponse $response): array
    {
        $json = json_decode((string) $response->getBody(), true);

        return is_array($json) ? $json : [];
    }

    public function testValidPostReturnsResolvedAddress(): void
    {
        $response = $this->postJson(['country' => 'NL', 'postalCode' => '1012 JS', 'houseNumber' => '1']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeader('Content-Type'));
        $body = $this->decode($response);
        $this->assertTrue($body['ok']);
        $this->assertSame('Dam', $body['address']['street']);
        $this->assertSame('Amsterdam', $body['address']['city']);
        $this->assertSame('NL', $body['address']['countryCode']);
    }

    public function testRejectsGet(): void
    {
        $response = $this->postJson(['country' => 'NL', 'postalCode' => '1012 JS', 'houseNumber' => '1'], [], 'GET');

        $this->assertSame(405, $response->getStatusCode());
        $this->assertFalse($this->decode($response)['ok']);
    }

    public function testPiiInUrlQueryIsNotAcceptedGet(): void
    {
        // A GET carrying the postcode in the query string (the Linberg2 anti-pattern) is refused outright.
        $getVars = ['postalCode' => '1012JS', 'houseNumber' => '1'];
        $request = new HTTPRequest('GET', '/shop-address-lookup/lookup', $getVars);
        $request->setSession(new Session([]));
        Injector::inst()->registerService($request, HTTPRequest::class);
        $controller = AddressLookupController::create();
        $controller->setRequest($request);

        $this->assertSame(405, $controller->lookup($request)->getStatusCode());
    }

    public function testIndexIsNotFound(): void
    {
        $request = new HTTPRequest('GET', '/shop-address-lookup');
        $request->setSession(new Session([]));
        $controller = AddressLookupController::create();
        $controller->setRequest($request);

        $this->assertSame(404, $controller->index($request)->getStatusCode());
    }

    public function testInsufficientInputIsASoftOk(): void
    {
        $response = $this->postJson(['country' => 'NL']); // no postcode

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertTrue($body['ok']);
        $this->assertNull($body['address']);
    }

    public function testUnmappedCountryReturnsNullAddress(): void
    {
        $response = $this->postJson(['country' => 'FR', 'postalCode' => '75001', 'houseNumber' => '1']);

        $this->assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertTrue($body['ok']);
        $this->assertNull($body['address'], 'No provider routes FR here -> manual form');
    }

    public function testCrossOriginIsForbidden(): void
    {
        $response = $this->postJson(
            ['country' => 'NL', 'postalCode' => '1012 JS', 'houseNumber' => '1'],
            ['Origin' => 'https://evil.example']
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('forbidden', $this->decode($response)['error']);
    }

    public function testCsrfRejectedWhenTokenMissing(): void
    {
        SecurityToken::enable();

        $response = $this->postJson(['country' => 'NL', 'postalCode' => '1012 JS', 'houseNumber' => '1']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('csrf', $this->decode($response)['error']);
    }

    public function testCsrfAcceptedWithToken(): void
    {
        SecurityToken::enable();

        // Seed the request service so SecurityToken reads the same session the controller will.
        $request = new HTTPRequest('POST', '/shop-address-lookup/lookup');
        $request->setBody((string) json_encode(['country' => 'NL', 'postalCode' => '1012 JS', 'houseNumber' => '1']));
        $request->addHeader('Content-Type', 'application/json');
        $request->setSession(new Session([]));
        Injector::inst()->registerService($request, HTTPRequest::class);

        $request->addHeader('X-SecurityID', (string) SecurityToken::inst()->getValue());

        $controller = AddressLookupController::create();
        $controller->setRequest($request);
        $response = $controller->lookup($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->decode($response)['ok']);
    }
}
