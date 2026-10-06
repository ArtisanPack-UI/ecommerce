# Satellites

Every package in the ecommerce ecosystem that plugs into the engine is a
*satellite*. This page lists the planned satellites and whether each one is
**contract-verified**: its latest tagged release passed the engine's shared
contract suites and published a signed report (parent plan §15.2).

Verification is advisory. The engine boots any satellite that implements a
contract, verified or not. The badge makes the trust signal public so store
owners can choose; it never stops a satellite from running.

The badge is **author-attested**: the satellite's own CI runs the suites and
signs with the author's key, so it shows the author ran the engine's
contract suites against that release — not that ArtisanPack re-ran them.
See [what a badge does and doesn't prove](satellite-verification.md).

## Badges

| Badge | Meaning |
|---|---|
| ![contract-verified](https://img.shields.io/badge/contract-verified-brightgreen) | The latest tag has an `ecommerce-verify-report.json` release asset (or, for releases made with the v1.0.0 stub, `default.ecommerce-verify-report.json` or an asset labelled `.ecommerce-verify-report.json`) with `"verified": true`, and its `verify-report.sig` checks out against the satellite's registered public key. |
| ![contract-failing](https://img.shields.io/badge/contract-failing-red) | The latest tag has a signed report, but the report says `"verified": false`. The engine's signing tools refuse to sign a failing report, so a failing tag normally has no signed report and shows as unverified. |
| ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) | No valid signed report for the latest tag. The satellite may still work — it just hasn't proven it. |

### How a badge is granted

1. The satellite runs the reusable
   [`verify-satellite.yml`](../.github/workflows/verify-satellite.yml) workflow
   on every tag (see [Satellite verification](satellite-verification.md)).
2. On a tag, the workflow signs a passing report with Ed25519 in a separate
   job and hands the report and signature over as a workflow artifact. The
   satellite's own release job attaches both files to the GitHub release for
   that tag. The report is attached as `ecommerce-verify-report.json`.

   GitHub renames a release asset whose name starts with a dot, so a report
   attached as `.ecommerce-verify-report.json` is published as
   `default.ecommerce-verify-report.json`, with the original name as its
   label. That happened to releases made with the v1.0.0 workflow stub.
   Releases are immutable, so the docs site also accepts that asset: one
   named `default.ecommerce-verify-report.json`, or one labelled
   `.ecommerce-verify-report.json`.
3. The docs site downloads both assets for the satellite's **latest** tag and
   checks that:
   - the signature is valid for the satellite's registered public key
     (`ecommerce:verify-satellite --check-signature --public-key=…
     --output=<report> --signature=<sig>`; the signature covers the report's
     bytes, not its file name);
   - the report's `package` matches the listed package and its `version`
     matches the tag;
   - the report says `"verified": true`.
4. If every check passes, the listing shows the green badge. A new tag without
   a valid report drops the satellite back to "unverified".

First-party satellites sign with the ArtisanPack UI organization key:

```text
GGLkQlSDIt+Gz6J/K+kTpxwL/3doEQ4AvVlEfWFi69c=
```

Third-party authors add their satellite to this page with a pull request that
includes their base64 Ed25519 public key; that key is what the site checks
their signatures against.

### Filtering

The docs site lets store owners filter this list to verified-only
satellites. In this file, search for `contract-verified`.

## Building a satellite

A satellite plugs into the engine from its service provider's `boot()`. It
registers implementations in the engine's registries, hooks actions and
filters, and declares itself in `SatelliteRegistry` so it can be uninstalled
cleanly ([satellite-lifecycle.md](satellite-lifecycle.md)).
[contracts.md](contracts.md) lists every contract, its registry, and its
contract-test suite. The registries satellites use most:

| Registry | What a satellite registers | Docs |
|---|---|---|
| `ProductTypeRegistry` | Product types, optionally with `ExpandsInventory` and `ProvidesStorefrontOptions` | [contracts.md](contracts.md#contract-reference) |
| `PaymentGatewayRegistry` | Payment gateways, optionally with `RendersClientPayment` | [contracts.md](contracts.md#contract-notes) |
| `TaxProviderRegistry`, `ShippingRateProviderRegistry`, `ShippingMethodTypeRegistry`, `ShippingLabelProviderRegistry`, `FraudProviderRegistry`, `CurrencyRateProviderRegistry`, `FulfillmentAllocationStrategyRegistry` | Providers and strategies | [contracts.md](contracts.md#contract-reference) |
| `PromotionConditionRegistry`, `PromotionActionRegistry` | Promotion rules, with a config schema | [contracts.md](contracts.md#config-schemas-describesconfig) |
| `KanbanCardWidgetRegistry`, `KanbanAutomationRegistry` | Kanban card widgets and automation triggers | [kanban.md](kanban.md) |
| `NotificationTemplateRegistry`, `NotificationChannelRegistry` | Notification templates and channels | [notifications.md](notifications.md), [contracts.md](contracts.md#other-registries) |
| `SearchProviderRegistry`, `SearchIndexerRegistry` | Search providers and indexers | [search.md](search.md#writing-a-search-satellite) |
| `AccountMenuRegistry` | Links in the shopper's account menu (every storefront reads it, REST through `GET me/account-menu`) | [contracts.md](contracts.md#other-registries) |
| `AdminMenuRegistry` | Links in the admin navigation | [contracts.md](contracts.md#other-registries) |
| `ReportRegistry`, `SettingsRegistry` | Admin reports and store settings | [reports.md](reports.md), [settings.md](settings.md) |
| `SatelliteRegistry` | The satellite's own lifecycle descriptor | [satellite-lifecycle.md](satellite-lifecycle.md) |

Give every account or admin menu entry a `satellite` package name, so the
entry disappears while the satellite is uninstalled.

### Storefront signals

Storefronts call `ProductViews::record( $product, $customer )` when a product
page is shown, and REST storefronts post to `POST products/{product}/views`
(answers `202`). Both fire `ap.ecommerce.product.viewed` with
`(Product $product, ?Customer $customer)`. The customer is set only when the
shopper is signed in. The engine stores nothing itself, so a satellite such as
`ecommerce-recently-viewed` listens to the action to hear about views from
every storefront family. See [hooks.md](hooks.md).

### Search satellites

A search satellite moves the catalog to a dedicated engine. It sets the
Scout driver (`artisanpack.ecommerce.search.driver`), registers a
`SearchProvider` that uses the engine's filters, facets, and suggestions, and
asks the host to select it with `ECOMMERCE_SEARCH_PROVIDER`. An unregistered
key falls back to the core `default` provider. It may also register a
`SearchIndexer` for an index Scout doesn't manage; the engine pushes every
storefront product change to it. Verify the provider with
`SearchProviderContractTest` and override its `index()` hook to push the test
products to your engine. `GET search` uses the provider, but the GraphQL
`search` field still queries Scout directly. See [search.md](search.md).

## Catalogue

Status reflects the latest tagged release of each package. Packages without a
release yet are listed as unverified.

### Engine peers

| Package | Purpose | Contracts |
|---|---|---|
| `artisanpack-ui/shipping-labels` | Standalone label buying/printing; carrier drivers live in their own satellites. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |

### UI satellites

| Package | Purpose | Contracts |
|---|---|---|
| `artisanpack-ui/ecommerce-storefront-livewire` | Storefront pages/components in Livewire. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-storefront-react` | Storefront in React. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-storefront-vue` | Storefront in Vue. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-admin-livewire` | Admin surfaces in Livewire. | ![contract-verified](https://img.shields.io/badge/contract-verified-brightgreen) (v1.0.0, `--allow-empty`) |
| `artisanpack-ui/ecommerce-admin-react` | Admin surfaces in React. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-admin-vue` | Admin surfaces in Vue. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-kanban-livewire` | Kanban board UI in Livewire. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-kanban-react` | Kanban board UI in React. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-kanban-vue` | Kanban board UI in Vue. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |

### Product and catalog

| Package | Purpose | Contracts |
|---|---|---|
| `artisanpack-ui/ecommerce-configurable-products` | Base product + pluggable configurators (print size, framing, …). | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-subscriptions` | Recurring billing, trials, plan changes, dunning. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-memberships` | Gated content and tiers. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-gift-cards` | Purchasable and code-based gift cards. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-product-bundles` | Curated bundles with discount rules. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-wishlists` | Session + persistent wishlists. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-recently-viewed` | Recently-viewed tracking. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |

### Fulfillment and operations

| Package | Purpose | Contracts |
|---|---|---|
| `artisanpack-ui/shipping-labels-shippo` | Shippo label driver. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/shipping-labels-easypost` | EasyPost label driver. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/shipping-labels-usps` | USPS label driver. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/shipping-labels-shipstation` | ShipStation sync. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-printful` | Printful print-on-demand. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-printify` | Printify print-on-demand. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-inventory-warehouses` | Multi-location stock. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-returns` | RMA lifecycle. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |

### Marketing and analytics

| Package | Purpose | Contracts |
|---|---|---|
| `artisanpack-ui/ecommerce-abandoned-cart` | Cart recovery emails. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-loyalty-points` | Points ledger and redemption. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-referrals` | Referral codes and rewards. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-affiliate` | Affiliate program. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-klaviyo` | Klaviyo sync. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-mailchimp` | Mailchimp sync. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-reports-pro` | Cohorts, LTV, funnels. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |

### Business model

| Package | Purpose | Contracts |
|---|---|---|
| `artisanpack-ui/ecommerce-b2b` | Company accounts, purchase orders, quotes. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-marketplace` | Multi-vendor marketplace. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-pos` | In-person point of sale. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-wholesale-pricing` | Customer-group pricing tables. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-tax-taxjar` | TaxJar tax provider. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-tax-avalara` | Avalara tax provider. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-tax-stripe` | Stripe Tax provider. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |

### Payment gateways

| Package | Purpose | Contracts |
|---|---|---|
| `artisanpack-ui/ecommerce-paypal` | PayPal Checkout + webhooks. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-square` | Square payments. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-bnpl` | Buy now, pay later via Stripe. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |

### Search

| Package | Purpose | Contracts |
|---|---|---|
| `artisanpack-ui/ecommerce-search-meilisearch` | Meilisearch search UX. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-search-typesense` | Typesense search UX. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |
| `artisanpack-ui/ecommerce-search-algolia` | Algolia search UX. | ![unverified](https://img.shields.io/badge/contract-unverified-lightgrey) |

UI satellites (storefront, admin, kanban) mostly consume the REST API and
register few or no engine contracts. They verify with `--allow-empty` so a
passing run with nothing to test still produces a signed report.
