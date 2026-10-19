<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\Control;

use SilverShop\AddressLookup\Service\AddressLookupService;
use SilverShop\AddressLookup\ValueObject\AddressQuery;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Cache\RateLimiter;
use SilverStripe\Security\SecurityToken;

/**
 * The one routed endpoint for checkout address lookups. Hardened against the defects the reference implementation
 * (Linberg2) shipped and the abuse a public proxy-to-a-third-party invites:
 *
 *  - POST only, with the PII (postcode + house number) in the JSON BODY — never the URL path or query string.
 *  - Same-origin check (Origin/Referer host must match the site) + CSRF (SecurityToken) so the endpoint can't be
 *    driven cross-site.
 *  - Per-IP AND per-session rate limit, bounding how hard the upstream providers can be hammered through us.
 *  - SSL verify stays ON and the upstream timeout is short (both in the provider HTTP base class).
 *  - Graceful: a miss, bad input or a provider fault all answer 200 {"ok":true,"address":null} so the form stays
 *    usable; nothing internal leaks.
 *
 * Routed at `shop-address-lookup` (see _config/routes.yml).
 */
class AddressLookupController extends Controller
{
    private static string $url_segment = 'shop-address-lookup';

    private static array $allowed_actions = [
        'lookup',
        'index',
    ];

    private static array $url_handlers = [
        'lookup' => 'lookup',
        '' => 'index',
    ];

    /**
     * Max lookups per rolling window, counted per IP AND per session. 0 for either disables the throttle.
     */
    private static int $max_lookups_per_window = 60;

    private static int $lookup_window_mins = 1;

    public function Link($action = null): string
    {
        return Controller::join_links(Director::baseURL(), 'shop-address-lookup', $action);
    }

    /**
     * The endpoint is POST-only; a bare GET exposes nothing.
     */
    public function index(HTTPRequest $request): HTTPResponse
    {
        return $this->jsonResponse(['ok' => false, 'error' => 'not_found'], 404);
    }

    /**
     * Resolve an address for a POSTed JSON query ({country, postalCode, houseNumber, suffix}). Answers
     * {"ok":true,"address":{…}|null}.
     */
    public function lookup(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->jsonResponse(['ok' => false, 'error' => 'method_not_allowed'], 405);
        }

        if (!$this->isSameOrigin($request)) {
            return $this->jsonResponse(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $body = $this->decodeBody($request);

        if (!$this->passesCsrf($request, $body)) {
            return $this->jsonResponse(['ok' => false, 'error' => 'csrf'], 403);
        }

        if ($this->isRateLimited($request)) {
            return $this->jsonResponse(['ok' => false, 'error' => 'throttled'], 429);
        }

        $query = AddressQuery::fromArray($body);
        if (!preg_match('/^[A-Z]{2}$/', $query->countryCode) || !$query->hasPostcode()) {
            // Not enough to act on — a soft, usable answer, not an error the UI must handle.
            return $this->jsonResponse(['ok' => true, 'address' => null]);
        }

        $address = AddressLookupService::create()->lookup($query);

        return $this->jsonResponse(['ok' => true, 'address' => $address?->toArray()]);
    }

    /**
     * Decode the JSON request body into a map, falling back to POST vars for a form-encoded submit.
     *
     * @return array<string, mixed>
     */
    protected function decodeBody(HTTPRequest $request): array
    {
        $raw = trim((string) $request->getBody());
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->postVars();
    }

    /**
     * Reject a cross-site request: when an Origin (preferred) or Referer header is present its host must match the
     * site host. A missing header leaves the decision to the CSRF token (some same-origin clients omit Origin).
     */
    protected function isSameOrigin(HTTPRequest $request): bool
    {
        $candidate = (string) ($request->getHeader('Origin') ?: $request->getHeader('Referer'));
        if ($candidate === '') {
            return true;
        }

        $host = (string) parse_url($candidate, PHP_URL_HOST);

        return $host !== '' && strcasecmp($host, (string) Director::host()) === 0;
    }

    /**
     * CSRF check. When the SecurityToken is enabled, the request must carry the session token — in the X-SecurityID
     * header, the JSON body `SecurityID`, or a request var. Disabled token (e.g. a headless setup) skips the check.
     *
     * @param array<string, mixed> $body
     */
    protected function passesCsrf(HTTPRequest $request, array $body): bool
    {
        $token = SecurityToken::inst();
        if (!$token->isEnabled()) {
            return true;
        }

        $submitted = (string) (
            $request->getHeader('X-SecurityID')
            ?: ($body[$token->getName()] ?? $body['SecurityID'] ?? '')
            ?: $request->requestVar($token->getName())
        );
        $expected = (string) $token->getValue();

        return $submitted !== '' && hash_equals($expected, $submitted);
    }

    /**
     * Over the throttle when either the per-IP or the per-session limiter is exhausted. Records a hit on each only
     * while still under the cap.
     */
    protected function isRateLimited(HTTPRequest $request): bool
    {
        $max = (int) static::config()->get('max_lookups_per_window');
        $window = (int) static::config()->get('lookup_window_mins');
        if ($max <= 0 || $window <= 0) {
            return false;
        }

        $limiters = [];
        $ip = (string) $request->getIP();
        if ($ip !== '') {
            $limiters[] = RateLimiter::create('address-lookup-ip-' . md5($ip), $max, $window);
        }
        $session = $request->getSession();
        $sessionId = (string) ($session->get('AddressLookupClientId') ?? '');
        if ($sessionId === '') {
            $sessionId = bin2hex(random_bytes(16));
            $session->set('AddressLookupClientId', $sessionId);
        }
        $limiters[] = RateLimiter::create('address-lookup-sess-' . md5($sessionId), $max, $window);

        foreach ($limiters as $limiter) {
            if (!$limiter->canAccess()) {
                return true;
            }
        }
        foreach ($limiters as $limiter) {
            $limiter->hit();
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function jsonResponse(array $data, int $status = 200): HTTPResponse
    {
        $response = HTTPResponse::create((string) json_encode($data), $status);
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        $response->addHeader('Cache-Control', 'no-store');

        return $response;
    }
}
