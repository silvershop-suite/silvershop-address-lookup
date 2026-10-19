<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use SilverShop\AddressLookup\Provider\OpenPlzProvider;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverStripe\Dev\SapphireTest;

/**
 * Parses canned OpenPLZ responses against the real {@link OpenPlzProvider}: street + city when the postcode maps to a
 * single street, city-only when ambiguous, the Localities fallback when there are no streets, the DACH country ->
 * dataset-segment mapping (incl. LI -> ch), and the region field across datasets (federalState/federalProvince).
 */
class OpenPlzProviderTest extends SapphireTest
{
    protected $usesDatabase = false;

    /**
     * @param array<int, Response> $responses
     * @param array<int, mixed> $transactions
     */
    private function provider(array $responses, array &$transactions = []): OpenPlzProvider
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($transactions));

        return new OpenPlzProvider(['handler' => $stack]);
    }

    public function testSingleStreetResolvesStreetAndCity(): void
    {
        $body = '[{"name":"Dorotheenstraße","postalCode":"10117","locality":"Berlin","suburb":"Mitte",'
            . '"federalState":{"key":"11","name":"Berlin"}}]';
        $transactions = [];
        $provider = $this->provider([new Response(200, [], $body)], $transactions);

        $address = $provider->lookup(new AddressQuery('DE', '10117', '1'));

        $this->assertNotNull($address);
        $this->assertSame('Dorotheenstraße', $address->street);
        $this->assertSame('Berlin', $address->city);
        $this->assertSame('Mitte', $address->municipality);
        $this->assertSame('Berlin', $address->state);
        $this->assertSame('DE', $address->countryCode);
        $this->assertTrue($address->exact);
        $this->assertSame('openplz', $address->providerRef);

        /** @var RequestInterface $request */
        $request = $transactions[0]['request'];
        $this->assertSame('/de/Streets', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $q);
        $this->assertSame('10117', $q['postalCode']);
    }

    public function testMultipleStreetsResolveCityOnly(): void
    {
        $body = '[{"name":"A-Straße","postalCode":"10117","locality":"Berlin","federalState":{"name":"Berlin"}},'
            . '{"name":"B-Straße","postalCode":"10117","locality":"Berlin","federalState":{"name":"Berlin"}}]';
        $provider = $this->provider([new Response(200, [], $body)]);

        $address = $provider->lookup(new AddressQuery('DE', '10117', '1'));

        $this->assertNotNull($address);
        $this->assertSame('', $address->street, 'Ambiguous street left blank');
        $this->assertSame('Berlin', $address->city);
        $this->assertFalse($address->exact);
    }

    public function testFallsBackToLocalitiesWhenNoStreets(): void
    {
        $localities = '[{"postalCode":"10117","name":"Berlin","municipality":{"name":"Berlin"},'
            . '"federalState":{"name":"Berlin"}}]';
        $transactions = [];
        $provider = $this->provider(
            [new Response(200, [], '[]'), new Response(200, [], $localities)],
            $transactions
        );

        $address = $provider->lookup(new AddressQuery('DE', '10117', '1'));

        $this->assertNotNull($address);
        $this->assertSame('', $address->street);
        $this->assertSame('Berlin', $address->city);
        $this->assertSame('Berlin', $address->municipality);
        $this->assertSame('Berlin', $address->state);
        $this->assertFalse($address->exact);

        $this->assertCount(2, $transactions);
        $this->assertSame('/de/Streets', $transactions[0]['request']->getUri()->getPath());
        $this->assertSame('/de/Localities', $transactions[1]['request']->getUri()->getPath());
    }

    public function testAustriaUsesAtSegmentAndFederalProvince(): void
    {
        $localities = '[{"postalCode":"1010","name":"Wien","municipality":{"name":"Wien"},'
            . '"federalProvince":{"name":"Wien"}}]';
        $transactions = [];
        $provider = $this->provider(
            [new Response(200, [], '[]'), new Response(200, [], $localities)],
            $transactions
        );

        $address = $provider->lookup(new AddressQuery('AT', '1010', '1'));

        $this->assertNotNull($address);
        $this->assertSame('Wien', $address->city);
        $this->assertSame('Wien', $address->state, 'AT region from federalProvince');
        $this->assertSame('/at/Streets', $transactions[0]['request']->getUri()->getPath());
    }

    public function testLiechtensteinUsesChSegment(): void
    {
        $body = '[{"name":"Städtle","postalCode":"9490","locality":"Vaduz"}]';
        $transactions = [];
        $provider = $this->provider([new Response(200, [], $body)], $transactions);

        $address = $provider->lookup(new AddressQuery('LI', '9490', '1'));

        $this->assertNotNull($address);
        $this->assertSame('Vaduz', $address->city);
        $this->assertSame('LI', $address->countryCode);
        $this->assertSame('/ch/Streets', $transactions[0]['request']->getUri()->getPath());
    }

    public function testSupportsCountryAndMissAndMode(): void
    {
        $provider = $this->provider([new Response(200, [], '[]'), new Response(200, [], '[]')]);

        foreach (['DE', 'AT', 'CH', 'LI'] as $code) {
            $this->assertTrue($provider->supportsCountry($code));
        }
        $this->assertFalse($provider->supportsCountry('FR'));
        $this->assertSame('postcode_number', $provider->lookupMode('DE'));

        // No streets and no localities -> soft miss.
        $this->assertNull($provider->lookup(new AddressQuery('DE', '00000', '1')));
    }
}
