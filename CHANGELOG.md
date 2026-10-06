# ArtisanPack UI Ecommerce Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- **Satellite verification report asset name**: GitHub renames a release asset whose name starts with a dot, so a report attached as `.ecommerce-verify-report.json` was published as `default.ecommerce-verify-report.json` (#186).
  - The reusable `verify-satellite` workflow now adds `ecommerce-verify-report.json` to the `ecommerce-verify-signature` artifact. It's the same signed bytes, and attaching it keeps its name.
  - The dotfile stays in the artifact for release steps written against v1.0.0.
  - The workflow stub attaches `ecommerce-verify-report.json` and pins `verify-satellite.yml@v1.0.1`.

## [1.0.0] - 2026-10-05

The first stable release of the headless commerce engine. Storefront, admin,
and integration packages plug in as satellites through its contracts,
registries, and hooks.

### Added

- **Catalog**:
  - simple, digital, variable, grouped, and bundled product types through `ProductTypeRegistry`;
  - variants, attributes, scheduled per-currency prices with compare-at prices, images, a category tree, and tags;
  - `ProductService` as the one write path.
- **Catalog reads for storefronts**:
  - `CatalogQuery` with filters, sorts, and facets;
  - display prices with and without tax (`PriceDisplayResolver`);
  - variant matching and the variant matrix (`VariantResolver`);
  - read-only stock status;
  - related products, upsells, and cross-sells;
  - add-to-cart field descriptions for grouped and bundled products (`ProvidesStorefrontOptions`);
  - the `ap.ecommerce.product.viewed` hook.
- **Search**:
  - the `SearchProvider` contract with facets and suggestions;
  - a default provider built on Laravel Scout and the catalog query;
  - the `SearchIndexer` contract;
  - a richer search document for dedicated engines.
- **Carts**:
  - server-side pricing;
  - options validation, stock checks, and a line cap;
  - expiry;
  - coupons;
  - shipping quotes and rate selection;
  - tax-inclusive and tax-exclusive totals;
  - free-item and free-shipping promotions;
  - the shopper's current cart, with the guest cart merged on login;
  - abandoned-cart flagging (`CartAbandoned`) and pruning.
- **Checkout**: the engine provides checkout services and APIs, and storefronts build the flow and UI.
  - `CheckoutService` (email, addresses, shipping method, payment gateway, payment session, finalize) and stock reservations;
  - `OrderPlacementService` (tax at placement, per-line allocation, coupon usage limits);
  - two-phase finalize and resume in `PaymentOrchestrator`;
  - reconciliation of payment outcomes from webhooks and `ecommerce:reconcile-payments`.
- **Payments**:
  - the `PaymentGateway` contract and registry;
  - Stripe Payment Intents (SAQ-A) with automatic or manual capture;
  - `RendersClientPayment` client configuration for embedded or redirect flows;
  - fraud screening with chainable providers and Stripe Radar.
- **Pricing and promotions**:
  - automatic and coupon promotions;
  - conditions: `min-subtotal`, `min-quantity`, `cart-contains-product`, `cart-contains-product-type`, `cart-contains-category`, `cart-contains-tag`, `customer-in-group`, `customer-first-order`, `customer-lifetime-value-over`, `day-of-week`, `date-range`, `currency-is`;
  - actions: `percent-off-cart`, `fixed-off-cart`, `percent-off-product`, `fixed-off-product`, `free-shipping`, `buy-x-get-y`, `add-free-item`, `tiered-discount`;
  - config schemas for admin forms;
  - multi-currency prices with FX providers (`config`, `frankfurter`), last-known-good rates, and `ecommerce:refresh-fx-rates`.
- **Tax and shipping**:
  - the `TaxProvider` contract with the manual tax-rate provider (postal patterns, ZIP+4, ranges, compound rates);
  - shipping zones, method types, and rate providers;
  - shipments with tracking;
  - the label provider contract;
  - local pickup with QR handoff.
- **Orders**:
  - a two-tier status machine with sub-statuses and a consistency audit;
  - edits with tax recalculation and rollback;
  - cancellation;
  - full and partial refunds with restocking;
  - notes, timeline, and fulfillment allocation.
- **Customers**:
  - guest and account customers, with verified-email linking;
  - addresses, notes, and lifetime stats;
  - guest-order claims;
  - GDPR delete-and-anonymize.
- **Customer self-service** (`me/*`):
  - profile and language;
  - addresses, orders, and claims;
  - digital downloads and license keys;
  - notification preferences;
  - an account menu through `AccountMenuRegistry`.
- **Guest order access**: guest order lookup by email and order number, and signed order links in guest confirmations.
- **Reviews**:
  - moderation through `ReviewModerator`, and rating aggregates with a histogram;
  - eligibility rules (`reviews.require_purchase`, `reviews.allow_multiple`), with automatic verified-purchase detection;
  - review photos through media-library.
- **Digital delivery and licenses**:
  - download tokens with limits and expiry, streaming, and owner downloads;
  - digital file versions and archiving;
  - license keys stored encrypted and hashed, with activation limits, validation, deactivation, and revocation.
- **Notifications**:
  - a catalog of order, shipping, review, digital, license, and stock notifications;
  - store-editable copy in a Twig sandbox;
  - customer preferences;
  - copy in the recipient's language;
  - a plain-text part and one-click unsubscribe.
- **Kanban**: order boards with auto-routing, automations and triggers, card widgets, and broadcasting.
- **Outbound webhooks**:
  - signed deliveries for domain events, with retries and backoff;
  - subscription auto-disable;
  - delivery history and replay, including replay of parked deliveries.
- **Domain events**:
  - `OrderPlaced`, `CartCompleted`, `CartAbandoned`, `CouponRedeemed`, `PromotionApplied`;
  - `ShipmentCreated`, `ShipmentDelivered`, `OrderFulfilled`;
  - `CustomerRegistered`, `CustomerUpdated`;
  - `ProductStockLow`, `ProductOutOfStock`;
  - `LicenseDeactivated`;
  - plus the payment, refund, review, license, and kanban events.
- **REST API** (`/api/ecommerce/v1`):
  - storefront, shopper, and admin endpoints;
  - cursor and page pagination with allow-listed filters, sorts, and includes;
  - idempotency keys and named rate-limit policies;
  - problem+json errors;
  - catalog caching with ETags.
- **GraphQL API**: an optional `ecommerce` schema on rebing/graphql-laravel, with depth, complexity, and batch limits and subscriptions.
- **OpenAPI 3.1** generation (`ecommerce:generate-openapi`), with query parameters and response schemas for every operation.
- **API authentication**:
  - Sanctum tokens with per-resource scopes;
  - signed service-to-service requests;
  - default-deny abilities with resource-level overrides;
  - cms-framework permissions, the shop-manager role, and `ecommerce:sync-permissions`.
- **Store administration**:
  - persisted store settings;
  - reports (sales summary, sales, top products, line items, tax collected, revenue by category, inventory, inventory levels, low stock) in the store time zone and net of refunds;
  - an activity log;
  - admin menu and notification channel registries.
- **Satellites**:
  - contracts and registries for product types, gateways, tax, shipping, labels, FX rates, fraud, promotions, kanban, notifications, search, reviews, cart storage, and order numbers;
  - contract test suites shipped in the package;
  - `ecommerce:verify-satellite` with signed reports;
  - satellite install, uninstall, reinstall, and orphan audits.
- **Localization**:
  - `en`, `es`, `fr`, and `de` catalogues, with regional-locale fallback;
  - locale-aware money formatting;
  - `Accept-Language` negotiation;
  - a translation linter for engine and satellite code.
- **Operations**:
  - a configurable single-server schedule (`schedule.*`);
  - ledger retention with `ecommerce:prune-ledgers`;
  - structured JSON logging with request ids carried into queued jobs;
  - optional Sentry context;
  - a demo store seeder;
  - a PCI column lint.
- **WordPress-style hooks** across the cart, order, pricing, tax, shipping, payment, product, customer, and API layers.
- **The `Ecommerce` facade and `ecommerce()` helper**, with `cart()`, `checkout()`, `orders()`, `payments()`, `catalog()`, `customers()`, and `inventory()` accessors.
- **Support for Laravel 12 and 13 on PHP 8.2 and later.**

### Changed

- Every engine table is prefixed with `ecommerce_`. Hosts that publish the migrations can opt out of the engine's own with `Ecommerce::ignoreMigrations()`.
- `stripe/stripe-php` and `rebing/graphql-laravel` are optional (suggested) dependencies. The engine boots and serves REST without them.
- `NotificationTemplate::defaultSubject()` and `defaultBody()` take an optional locale, so catalog copy is translated when it is sent.
- Validation errors report the failed rule's name as the error `code`, and every API error is `application/problem+json`.

### Security

- **Shopper-facing data:** API responses never expose staff-internal fields such as fraud verdicts, refund references, or storage paths to shoppers.
- **Token scopes:** refunds, cancellations, customer deletion, and settings writes each need a dedicated token scope.
- **Inbound webhooks:**
  - unknown providers and oversized bodies are refused without being stored;
  - unverified requests keep only a hash, the size, and the first kilobyte;
  - every request counts against a per-IP limit, and only verified deliveries count against the provider's allowance.
- **Outbound webhooks** resolve, pin, and re-check every IP address to block SSRF into private networks.
- **Idempotency and rate limits:**
  - money-moving GraphQL mutations need an `Idempotency-Key`;
  - guest idempotency keys must be long random strings;
  - guest order lookups lock out after repeated failures.
- **Stored secrets:** license keys are stored encrypted and matched by hash. Inbound webhook payloads are scrubbed when a customer is anonymized.
- **Signed service requests** are checked against a replay cache (`api.signature_cache_store`); the engine warns in production when that store isn't shared between servers.
- **Demo seeder:** `ecommerce:seed-demo --fresh` can't empty a production store without an explicit confirmation flag.
