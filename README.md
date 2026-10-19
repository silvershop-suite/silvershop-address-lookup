# silvershop/address-lookup

Checkout address autocomplete for [SilverShop](https://github.com/silvershop/silvershop-core) (CMS 6), built on
[`silvershop/address-formats`](https://github.com/RVXD/silvershop-address-formats).

Enter a **postcode + house number** and the street + city fill in automatically — the standard Dutch/German checkout
pattern — via a **server-side lookup service** behind a **per-country provider seam**. The base module bundles three
**free, keyless** providers so NL + DACH work out of the box at zero cost:

| Provider | Key | Countries | Resolves | Cost |
| --- | --- | --- | --- | --- |
| **PDOK Locatieserver** | `pdok` | NL | postcode + number → **street + city** (+ lat/lng) | free, keyless |
| **OpenPLZ** | `openplz` | DE, AT, CH, LI | postcode → **city** (+ street when unambiguous) | free, keyless |
| **Zippopotam** | `zippopotam` | 60+ countries | postcode → **city / region** (+ lat/lng), no street | free, keyless |

Paid providers (MyParcel, Pro6PP, Loqate) ship as **satellite add-on modules** that register their own provider key
here; the base `suggest`s them.

## How it works

1. A provider implements `Provider\AddressLookupProvider` and declares, per country, which of three capabilities it
   offers: structured `lookup()` (postcode + number), free-text `suggest()` (typeahead), and `validate()`.
2. `Provider\AddressLookupProviderRegistry` maps countries to providers (`by_country`, with an optional `default`) and
   resolves the one that handles a given country — only ever returning a provider that is **configured** and
   **supports that country**, so anything else falls back to the plain manual form.
3. `Service\AddressLookupService` runs the lookup (registry → consent check → provider) and returns a normalised,
   immutable `ValueObject\ResolvedAddress`. Every provider call is wrapped so a fault degrades to a soft miss.
4. `Control\AddressLookupController` exposes **one** routed POST endpoint (`shop-address-lookup/lookup`).
5. `Extension\AddressLookupFormExtension` injects a per-country config + a vanilla, no-build bootstrap script into the
   checkout address form.

## Installation

```bash
composer require silvershop/address-lookup
```

Then run `dev/build?flush=all`. NL and DE autofill immediately — no keys, no configuration.

## Configuration

```yaml
SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry:
  providers:
    pdok: '%$SilverShop\AddressLookup\Provider\PdokProvider'
    openplz: '%$SilverShop\AddressLookup\Provider\OpenPlzProvider'
    zippopotam: '%$SilverShop\AddressLookup\Provider\ZippopotamProvider'
  default: ~                    # EMPTY → unmapped countries use the manual form (no surprise third-party call)
  by_country:
    NL: pdok
    BE: pdok                    # mapped for forward-compat with the MyParcel satellite; PDOK itself is NL-only, so
                                # until a BE-capable provider is installed BE uses the manual form
    DE: openplz
```

- **Enable the free global fallback.** Map any country to `zippopotam` (registered but unmapped by default), or set
  `default: zippopotam`, to autofill city/region from the postcode everywhere Zippopotam has coverage.
- **Swap in a paid satellite.** Install e.g. `silvershop/address-lookup-myparcel`, then remap `by_country: { NL: myparcel }`.

## Hardening

The endpoint is deliberately narrow and safe to expose:

- **POST only, PII in the JSON body** — the postcode + house number are never put in the URL path or query string.
- **Same-origin + CSRF** — the `Origin`/`Referer` host must match the site, and the SilverStripe `SecurityToken` is
  required (sent as the `X-SecurityID` header).
- **Rate limited** per IP *and* per session (`max_lookups_per_window` / `lookup_window_mins`).
- **SSL verify ON**, short upstream timeout (`AbstractHttpProvider.timeout`), and a graceful empty answer on any miss
  or fault so checkout stays usable.

## Consent

The three bundled providers are **functional/necessary** (EU government / EU processors used only to fulfil the
order) and declare **no consent category**, so they run without a cookie gate. A future provider can set a category:

```yaml
SilverShop\AddressLookup\Provider\SomeProvider:
  consent_category: marketing   # gated via silvershop/cookieconsent; default-deny when that module is absent
```

`Consent\ConsentResolver` is the single binding point to `silvershop/cookieconsent`; without a declared category it is
a pass-through.

## Frontend

The autofilled street + city stay **fully editable** — pre-filled, never locked — and the complete manual field set is
always present, so a wrong suggestion or a JS-off browser never blocks checkout. The bootstrap is scoped to
`.silvershop-address-fields` and cooperates with address-formats' `addressformat:changed` event. The inline script is
rendered from `templates/SilverShop/AddressLookup/Includes/AddressLookupScript.ss`; override that template in your
theme to customise it (or ship your own via `Requirements::javascript`).

## Writing a provider (satellite)

```php
class MyProvider extends \SilverShop\AddressLookup\Provider\AbstractHttpProvider
{
    public function getKey(): string { return 'myprovider'; }
    public function getName(): string { return 'My Provider'; }
    public function isConfigured(): bool { return (string) \SilverStripe\Core\Environment::getEnv('SS_MYPROVIDER_KEY') !== ''; }
    public function supportsCountry(string $countryCode): bool { return in_array($countryCode, ['NL', 'BE'], true); }
    public function lookup(\SilverShop\AddressLookup\ValueObject\AddressQuery $query): ?\SilverShop\AddressLookup\ValueObject\ResolvedAddress { /* … */ }
}
```

Register it in the satellite's own `_config` and remap the relevant countries:

```yaml
SilverShop\AddressLookup\Provider\AddressLookupProviderRegistry:
  providers:
    myprovider: '%$Vendor\MyProvider'
  by_country:
    NL: myprovider
```

## Requirements

- PHP ^8.3
- silverstripe/framework ^6, silverstripe/cms ^6
- silvershop/core ^6, silvershop/address-formats ^1.3
- guzzlehttp/guzzle ^7.5

## License

BSD-3-Clause.
