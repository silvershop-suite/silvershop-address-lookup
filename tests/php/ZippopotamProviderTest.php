<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SilverShop\AddressLookup\Provider\ZippopotamProvider;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverStripe\Dev\SapphireTest;

/**
 * Parses canned Zippopotam responses against the real {@link ZippopotamProvider}: city / state / lat-lng from a
 * postcode (no street), the NL 4-digit path normalisation, and a soft miss.
 */
class ZippopotamProviderTest extends SapphireTest
{
    protected $usesDatabase = false;

    /**
     * @param array<int, mixed> $transactions
     */
    private function provider(Response $response, array &$transactions = []): ZippopotamProvider
    {
        $mock = new MockHandler([$response]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($transactions));

        return new ZippopotamProvider(['handler' => $stack]);
    }

    public function testResolvesCityStateAndCoordinates(): void
    {
        $body = '{"post code":"90210","country":"United States","country abbreviation":"US","places":['
            . '{"place name":"Beverly Hills","longitude":"-118.4065","state":"California",'
            . '"state abbreviation":"CA","latitude":"34.0901"}]}';
        $transactions = [];
        $provider = $this->provider(new Response(200, [], $body), $transactions);

        $address = $provider->lookup(new AddressQuery('US', '90210'));

        $this->assertNotNull($address);
        $this->assertSame('', $address->street, 'Zippopotam has no street data');
        $this->assertSame('Beverly Hills', $address->city);
        $this->assertSame('California', $address->state);
        $this->assertSame('90210', $address->postalCode);
        $this->assertSame('US', $address->countryCode);
        $this->assertSame(34.0901, $address->lat);
        $this->assertSame(-118.4065, $address->lng);
        $this->assertFalse($address->exact);
        $this->assertSame('zippopotam', $address->providerRef);

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame('/us/90210', $request->getUri()->getPath());
    }

    public function testNlPostcodeNormalisedToFourDigitsInPath(): void
    {
        $body = '{"post code":"1011","country":"The Netherlands","country abbreviation":"NL","places":['
            . '{"place name":"Amsterdam","longitude":"4.9","state":"Noord-Holland","latitude":"52.37"}]}';
        $transactions = [];
        $provider = $this->provider(new Response(200, [], $body), $transactions);

        $address = $provider->lookup(new AddressQuery('NL', '1011 AB', '1'));

        $this->assertNotNull($address);
        $this->assertSame('Amsterdam', $address->city);
        $this->assertSame('/nl/1011', $transactions[0]['request']->getUri()->getPath());
    }

    public function testEmptyPlacesIsASoftMiss(): void
    {
        $provider = $this->provider(new Response(404, [], 'null'));

        $this->assertNull($provider->lookup(new AddressQuery('ZZ', '00000')));
    }

    public function testSupportsAnyTwoLetterCountry(): void
    {
        $provider = $this->provider(new Response(200, [], '{}'));

        $this->assertTrue($provider->supportsCountry('US'));
        $this->assertTrue($provider->supportsCountry('nz'));
        $this->assertFalse($provider->supportsCountry('USA'));
        $this->assertFalse($provider->supportsCountry(''));
        $this->assertNull($provider->consentCategory());
    }
}
