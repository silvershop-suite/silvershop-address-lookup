<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SilverShop\AddressLookup\Provider\PdokProvider;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverStripe\Dev\SapphireTest;

/**
 * Parses a canned PDOK Locatieserver response against the real {@link PdokProvider} with a mocked Guzzle handler, and
 * checks the request shape (postcode + number in the query, fq=type:adres).
 */
class PdokProviderTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const DOC_RESPONSE = '{"response":{"numFound":1,"start":0,"docs":[{'
        . '"type":"adres","weergavenaam":"Dam 1, 1012JS Amsterdam","straatnaam":"Dam","huisnummer":1,'
        . '"huis_nlt":"1","postcode":"1012JS","woonplaatsnaam":"Amsterdam","gemeentenaam":"Amsterdam",'
        . '"provincienaam":"Noord-Holland","centroide_ll":"POINT(4.89320000 52.37300000)"}]}}';

    /**
     * @param array<int, mixed> $transactions
     */
    private function provider(Response $response, array &$transactions = []): PdokProvider
    {
        $mock = new MockHandler([$response]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($transactions));

        return new PdokProvider(['handler' => $stack]);
    }

    public function testResolvesStreetCityAndCoordinates(): void
    {
        $transactions = [];
        $provider = $this->provider(new Response(200, [], self::DOC_RESPONSE), $transactions);

        $address = $provider->lookup(new AddressQuery('NL', '1012 JS', '1'));

        $this->assertNotNull($address);
        $this->assertSame('Dam', $address->street);
        $this->assertSame('1', $address->houseNumber);
        $this->assertSame('Amsterdam', $address->city);
        $this->assertSame('Amsterdam', $address->municipality);
        $this->assertSame('Noord-Holland', $address->state);
        $this->assertSame('1012 JS', $address->postalCode, 'Postcode normalised to canonical NL form');
        $this->assertSame('NL', $address->countryCode);
        $this->assertSame(52.373, $address->lat);
        $this->assertSame(4.8932, $address->lng);
        $this->assertTrue($address->exact);
        $this->assertSame('pdok', $address->providerRef);

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('api.pdok.nl', $request->getUri()->getHost());
        // Exact filter on postcode AND house number (not a free-text `q`), decoded to ignore %-encoding differences.
        $raw = rawurldecode($request->getUri()->getQuery());
        $this->assertStringContainsString('fq=type:adres', $raw);
        $this->assertStringContainsString('fq=postcode:1012JS', $raw);
        $this->assertStringContainsString('fq=huisnummer:1', $raw);
    }

    public function testEmptyResultIsASoftMiss(): void
    {
        $provider = $this->provider(new Response(200, [], '{"response":{"numFound":0,"docs":[]}}'));

        $this->assertNull($provider->lookup(new AddressQuery('NL', '0000 ZZ', '999')));
    }

    public function testNonNlCountryMakesNoRequest(): void
    {
        $transactions = [];
        $provider = $this->provider(new Response(200, [], self::DOC_RESPONSE), $transactions);

        $this->assertNull($provider->lookup(new AddressQuery('DE', '10117', '1')));
        $this->assertCount(0, $transactions, 'PDOK is NL-only; no HTTP call for another country');
    }

    public function testMissingHouseNumberMakesNoRequest(): void
    {
        $transactions = [];
        $provider = $this->provider(new Response(200, [], self::DOC_RESPONSE), $transactions);

        $this->assertNull($provider->lookup(new AddressQuery('NL', '1012 JS', '')));
        $this->assertCount(0, $transactions);
    }

    public function testSupportsCountryAndMode(): void
    {
        $provider = $this->provider(new Response(200, [], self::DOC_RESPONSE));
        $this->assertTrue($provider->supportsCountry('NL'));
        $this->assertFalse($provider->supportsCountry('BE'));
        $this->assertSame('postcode_number', $provider->lookupMode('NL'));
        $this->assertSame('none', $provider->lookupMode('BE'));
        $this->assertNull($provider->consentCategory());
    }
}
