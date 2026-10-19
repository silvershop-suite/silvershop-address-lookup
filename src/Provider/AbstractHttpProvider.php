<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Provider;

use GuzzleHttp\Client;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverShop\AddressLookup\ValueObject\AddressValidationResult;
use SilverShop\AddressLookup\ValueObject\ResolvedAddress;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use Throwable;

/**
 * Shared base for the HTTP-backed providers. Owns a hardened Guzzle client (SSL verify ON, short timeout, JSON
 * Accept) and a graceful GET-JSON helper that swallows network/parse errors into an empty result, so a provider's
 * {@see self::lookup()} degrades to a soft miss rather than a 500. Concrete providers implement only getKey/getName/
 * supportsCountry/lookup (+ suggest/validate where they have that capability).
 *
 * The Guzzle config is overridable via the `$http_options` config array (merged last), which is the test seam for a
 * mock handler. Keyless public APIs are always {@see self::isConfigured()} true; paid satellites override it.
 */
abstract class AbstractHttpProvider implements AddressLookupProvider
{
    use Injectable;
    use Configurable;

    /**
     * Request timeout in seconds. Kept short: a slow address API must never stall checkout.
     */
    private static int $timeout = 5;

    /**
     * Extra Guzzle options merged over the defaults (base_uri stays per-request). The test seam for injecting a
     * mock handler: Config::modify()->set(Provider::class, 'http_options', ['handler' => $stack]).
     *
     * @var array<string, mixed>
     */
    private static array $http_options = [];

    /**
     * The cookieconsent category gating this provider, or null (functional, no gate). Overridable per provider in
     * config for a future gated connector; the bundled providers leave it null.
     */
    private static ?string $consent_category = null;

    private ?Client $client = null;

    /**
     * @param array<string, mixed> $httpOptions extra Guzzle config merged last (a second test seam; normally empty
     *                                           so Injector can construct the provider with no arguments)
     */
    public function __construct(private readonly array $httpOptions = [])
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function lookupMode(string $countryCode): string
    {
        return $this->supportsCountry($countryCode)
            ? self::MODE_POSTCODE_NUMBER
            : self::MODE_NONE;
    }

    public function suggest(string $term, string $countryCode): array
    {
        return [];
    }

    public function validate(ResolvedAddress $address): AddressValidationResult
    {
        return AddressValidationResult::unknown();
    }

    public function consentCategory(): ?string
    {
        $category = static::config()->get('consent_category');

        return $category === null || $category === '' ? null : (string) $category;
    }

    /**
     * Each concrete provider resolves a structured query; the base only supplies the plumbing.
     */
    abstract public function lookup(AddressQuery $query): ?ResolvedAddress;

    /**
     * The shared, lazily-built Guzzle client. SSL verification stays ON (never disabled) and the timeout is short.
     */
    protected function httpClient(): Client
    {
        if (!$this->client instanceof Client) {
            $this->client = new Client(array_merge(
                [
                    'timeout' => (int) static::config()->get('timeout'),
                    'connect_timeout' => (int) static::config()->get('timeout'),
                    'verify' => true,
                    'http_errors' => false,
                    'headers' => [
                        'Accept' => 'application/json',
                        'User-Agent' => 'silvershop-address-lookup/1.0 (+https://github.com/silvershop)',
                    ],
                ],
                (array) static::config()->get('http_options'),
                $this->httpOptions,
            ));
        }

        return $this->client;
    }

    /**
     * GET a URL and decode a JSON object/array body, or return [] on any failure (non-2xx, timeout, transport error,
     * non-JSON). Never throws — a dead address API must leave checkout usable.
     *
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    protected function getJson(string $url, array $query = []): array
    {
        try {
            $response = $this->httpClient()->request('GET', $url, $query === [] ? [] : ['query' => $query]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return [];
            }
            $decoded = json_decode((string) $response->getBody(), true);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
