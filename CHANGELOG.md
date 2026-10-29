# ArtisanPack UI Ecommerce Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.2.0] - 2026-10-29

Kanban boards keep a manual card order, read their cards through one
filterable query, broadcast every change as an id-only board event, take
per-instance card widget settings, and edit columns through one service, so
the board satellites need no kanban logic of their own.

### Added

- Manual card order on kanban boards (#198). `ecommerce_order_board_assignments` gains a `position` (existing cards are numbered in the order they reached their column). `KanbanBoardService::move()` takes an optional `Kanban\KanbanCardPlacement` (`top()`, `bottom()`, `before( $orderId )`, `after( $orderId )`); a same-column move with a placement reorders the card without a status change, WIP check, or automations, and fires `ap.ecommerce.kanban.cardReordered` and `Events\KanbanCardReordered`. `ap.ecommerce.kanban.cardMoving` receives the placement as `placement` and may change it.
- `artisanpack.ecommerce.kanban.new_card_position` (`bottom` / `top`, env `ECOMMERCE_KANBAN_NEW_CARD_POSITION`): where cards that join a board land in their column (#198).
- REST `POST kanban/cards/{order}/move` accepts `position` (`top` / `bottom`), `before_order_id`, or `after_order_id`; card resources expose `position` (#198).
- `Kanban\KanbanCardQuery` (its `filter()` and `column()` return a narrowed copy, so one query can be reused) and `Kanban\KanbanCardFilters`: a board's cards filtered by search (order number, email, billing / shipping / customer name), customer, tags (any / all), products (and their variants), placed date range, stale cards, and payment and fulfillment status, in manual order, cursor-paginated, with filtered and total counts per column. Satellites add filters through `ap.ecommerce.kanban.cardQuery` (#199).
- `Kanban\KanbanCardTags::forBoard()`: the distinct tags on a board's cards, for a filter bar's options (#199).
- `Order::kanbanCardRelations()` (filterable through `ap.ecommerce.kanban.cardRelations`): what card widgets read, eager-loaded with the cards (#199).
- REST `GET kanban/boards/{board}/cards` accepts the card filters as `filter[...]`; `GET kanban/boards/{board}` adds `filtered_card_count` to each column when they are passed (#199).
- Board events on `private-ecommerce.kanban.board.{board}` (#200): with `kanban.broadcast` on, cards added, removed, moved, reordered, and updated, board and column configuration changes, and automations that fired or failed are broadcast as `kanbanBoardEvent` (`Broadcasting\KanbanBoardBroadcast`) with an id-only payload: `{ type, board_id, order_id, column_id, from_column_id, to_column_id, position, actor_user_id, occurred_at }`. Broadcasts go out after commit on `kanban.broadcast_connection` / `kanban.broadcast_queue` (env `ECOMMERCE_KANBAN_BROADCAST_CONNECTION` / `_QUEUE`); a broadcaster failure is logged and never fails the write. The existing `kanbanCardMoved` broadcast stays.
- `Events\KanbanBoardConfigChanged` and `Events\KanbanAutomationFailed` (#200).
- `Kanban\KanbanCardRefresher::refresh( $order, ?$widgetKey )` broadcasts `card-updated` to each active board an order is on, after commit and debounced per request (#201). The core calls it when an order's payment or fulfillment status, totals, or `meta.tags` change, on order edits, and on new shipments; satellites call it for their own widgets.
- Per-instance card widget config (#202): column `card_widgets` and board `settings.card_widgets` accept `{ key, config }` entries next to plain keys (`Kanban\KanbanWidgetList`), validated against the widget's `configSchema()` with undeclared keys rejected (lists hold at most 50 entries and each config at most 50 settings). Widgets opt in with `Contracts\ConfigurableKanbanCardWidget::renderWithConfig()`; `AbstractKanbanCardWidget` implements it and exposes `$this->config()`. Config merges as schema defaults → `kanban_card_widgets.default_config` → instance config, and a stored `kanban_card_widgets.label` replaces the widget's label. `days-in-column` takes `stale_after_days` and `tags` takes `max_tags`.
- `Services\KanbanCardWidgetService`: a board's default widget list and the store-wide widget overrides; REST `PUT` / `DELETE kanban/widgets/{key}`; a change tells every active board to refresh and fires `ap.ecommerce.kanban.widgetOverrideSaved` / `widgetOverrideReset` (#202). An override stores a label only when one is given (an empty label clears it), so a config-only override keeps the widget's own translated label; `ecommerce_kanban_card_widgets.label` becomes nullable, and widget resources add `label_override` (null when the widget's own label is used). `KanbanCardRenderer::widgetEntries()`, `widgetSource()` (`column` / `board` / `default`), and `config()`.
- `Services\KanbanColumnService` with `create()`, `update()`, `reorder()`, and `delete()` (#203): the column rules in one place, a bulk reorder in one transaction (REST `PUT kanban/boards/{board}/columns/order`), deleting a column with cards refused with `Exceptions\KanbanColumnNotEmptyException` (carrying the card count) or its cards moved to a named column first (REST `DELETE kanban/columns/{column}` takes `move_cards_to`, which also needs `kanbanCard.move`; at most `KanbanColumnService::MAX_CARDS_MOVED_ON_DELETE` cards), and `ap.ecommerce.kanban.column{Created,Updated,Reordered,Deleted}` actions.

### Changed

- REST `GET kanban/boards/{board}/cards` lists cards in their manual order (position, then when they joined the board) by default; pass `sort=assigned_at` for the old order (#198).
- `KanbanBoardService` and `KanbanRoutingService` take a `Kanban\KanbanCardPositions` constructor argument, and `KanbanBoardService::filteredTarget()` returns the target column and placement as a pair. Card moves now lock the source and target columns before the card (#198).
- `KanbanCardMoved`, `KanbanCardReordered`, `KanbanBoardAssignmentAdded`, and `KanbanBoardAssignmentRemoved` carry an optional `actorUserId` (#200).
- The REST column endpoints write through `KanbanColumnService`; their behavior is unchanged (#203). Column resources keep `card_widgets` as a list of widget keys and add `card_widget_entries` (`{ key, config }` pairs, GraphQL `[JSON!]!`) (#202).
- `KanbanCardRenderer` is bound scoped, so widget overrides load once per request (#202).
- A broadcaster failure while broadcasting `kanbanCardMoved` is logged instead of failing the move (#200).

## [1.1.0] - 2026-10-12

Storefront listings, carts, and variable product pages now price and stock
products in a fixed number of queries, the OpenAPI spec takes its version
from the release tag, and the engine installs on MySQL.

### Added

- `Product::withDisplayData()` scope and `Product::displayRelations()`: eager-load what a display price and stock state need (prices, stock rows, variants with theirs) (#195).
- `Tax\TaxRateCandidates` (scoped): active tax rates are read once per request per tax class and country, and re-read after a `TaxRate` is saved or deleted (#195).
- `StockLevels::itemFor()`, `available()`, and `sellable()` take an optional `$preferLoaded` flag to read a loaded `inventoryItems` relation. Only `StockStatus` uses it; reservations, checkout, and refunds always read fresh rows (#195).
- `ecommerce:generate-openapi --spec-version=` sets the spec's `info.version` (a leading `v` is dropped; anything that isn't a semantic version is refused). The release workflow passes the tag (#189).
- `OpenApiGenerator::generate()` takes an optional version, and `OpenApiGenerator::normalizeVersion()` reads a version or tag.

### Changed

- `ProductPriceResolver`, `PriceDisplayResolver`, `StockStatus`, and product-type variant lookup use relations the caller already loaded instead of querying per product or variant; `VariantResolver::matrix()` loads variant prices and stock once. Nothing changes for callers that load nothing (#195).
- Without `--spec-version`, `info.version` comes from `composer.json`, then the installed engine version. It falls back to `0.0.0-dev` (`OpenApiGenerator::FALLBACK_VERSION`), not a hard-coded release number that had to be bumped by hand each release.

### Fixed

- **MySQL install**: the `product_variant_id` foreign key on `ecommerce_product_variant_option_values` is named `ecommerce_pvov_variant_fk`. Laravel's default name was 66 characters, past MySQL's 64-character limit, so `migrate` failed on MySQL. Existing SQLite and Postgres installs are unaffected. A test now compiles every migration as MySQL and fails on any identifier over 64 characters (#191).

## [1.0.1] - 2026-10-06

A patch release for satellite verification: signed reports now reach a
release under a name GitHub keeps.

### Fixed

- **Satellite verification report asset name**: GitHub renames a release asset whose name starts with a dot, so a report attached as `.ecommerce-verify-report.json` was published as `default.ecommerce-verify-report.json` (#186).
  - The reusable `verify-satellite` workflow now adds `ecommerce-verify-report.json` to the `ecommerce-verify-signature` artifact. It's the same signed bytes, and attaching it keeps its name.
  - The dotfile stays in the artifact for release steps written against v1.0.0.
  - The workflow stub attaches `ecommerce-verify-report.json` and pins `verify-satellite.yml@v1.0.1`.
- The workflow stub pins `softprops/action-gh-release` to a full commit SHA, since its release step runs with `contents: write`.
- The OpenAPI spec reports the release's version (`info.version`).

### Changed

- **Satellites page** (`docs/satellites.md`):
  - The contract-verified rule looks for `ecommerce-verify-report.json`. It still accepts the `default.ecommerce-verify-report.json` asset that releases made with the v1.0.0 stub carry.
  - Publishes the ArtisanPack UI organization's signing key.
  - Lists `artisanpack-ui/ecommerce-admin-livewire` as contract-verified.
- **Satellite verification guide** (`docs/satellite-verification.md`): the CI example uses the new asset name and engine tag, and shows how to check a published release's signature.

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
