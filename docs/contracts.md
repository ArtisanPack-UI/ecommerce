# Contracts

Every swappable piece of the engine is a PHP interface in
`ArtisanPackUI\Ecommerce\Contracts\` (parent plan §6.1, engine spec §4). A
satellite implements the interface, registers the implementation in a
registry (or rebinds it in the container), and proves it behaves by extending
the matching abstract contract-test suite.

## Contract reference

| Contract | Registered through | Core implementations (key) | Contract-test suite |
|---|---|---|---|
| [`ProductType`](../src/Contracts/ProductType.php) | `ProductTypeRegistry` | `SimpleProductType` (`simple`), `DigitalProductType` (`digital`); `MissingProductType` placeholder for unknown keys | `ProductTypeContractTest` |
| [`PaymentGateway`](../src/Contracts/PaymentGateway.php) | `PaymentGatewayRegistry` | `StripeGateway` (`stripe`, when `gateways.stripe.enabled`) | `PaymentGatewayContractTest` |
| [`FraudProvider`](../src/Contracts/FraudProvider.php) | `FraudProviderRegistry` | `AlwaysApproveFraudProvider` (`always-approve`), `StripeRadarFraudProvider` (`stripe-radar`, with Stripe); `ChainFraudProvider` (`chain`) runs a comma-separated `fraud.provider` list | `FraudProviderContractTest` |
| [`TaxProvider`](../src/Contracts/TaxProvider.php) | `TaxProviderRegistry` (active: `tax.provider`) | `ManualTaxProvider` (`manual`) | `TaxProviderContractTest` |
| [`ContextAwareTaxProvider`](../src/Contracts/ContextAwareTaxProvider.php) (extends `TaxProvider`) | `TaxProviderRegistry` | `ManualTaxProvider` | `TaxProviderContractTest` |
| [`ShippingRateProvider`](../src/Contracts/ShippingRateProvider.php) | `ShippingRateProviderRegistry` (empty by default; carrier satellites) | `ZoneShippingRateProvider` (`zones`, used internally for zone/method rates) | `ShippingRateProviderContractTest` |
| [`ShippingMethodType`](../src/Contracts/ShippingMethodType.php) | `ShippingMethodTypeRegistry` | `flat-rate`, `free-shipping`, `local-pickup`, `weight-based`, `price-based` (`src/Shipping/Methods/`) | none |
| [`ShippingLabelProvider`](../src/Contracts/ShippingLabelProvider.php) | `ShippingLabelProviderRegistry` (empty by default) | none | none |
| [`FulfillmentAllocationStrategy`](../src/Contracts/FulfillmentAllocationStrategy.php) | `FulfillmentAllocationStrategyRegistry` (active: `fulfillment.allocation_strategy`) | `ProportionalByLineTotalStrategy` (`proportional-by-line-total`) | `FulfillmentAllocationStrategyContractTest` |
| [`CurrencyRateProvider`](../src/Contracts/CurrencyRateProvider.php) | `CurrencyRateProviderRegistry` (active: `currency.provider`) | `ConfigRateProvider` (`config`), `FrankfurterRateProvider` (`frankfurter`) | none |
| [`CartStorage`](../src/Contracts/CartStorage.php) | Container singleton (rebind to swap) | `DatabaseCartStorage` | `CartStorageContractTest` |
| [`OrderNumberGenerator`](../src/Contracts/OrderNumberGenerator.php) | Container singleton | `RandomEightCharGenerator` | `OrderNumberGeneratorContractTest` |
| [`ReviewModerator`](../src/Contracts/ReviewModerator.php) | Container singleton | `NoopReviewModerator` (`noop`) | `ReviewModeratorContractTest` |
| [`PromotionCondition`](../src/Contracts/PromotionCondition.php) | `PromotionConditionRegistry` | `min-subtotal`, `cart-contains-product`, `cart-contains-product-type`, `customer-in-group`, `day-of-week`, `customer-first-order` | `PromotionConditionContractTest` |
| [`OrderAwarePromotionCondition`](../src/Contracts/OrderAwarePromotionCondition.php) (extends `PromotionCondition`) | `PromotionConditionRegistry` | `cart-contains-product-type`, `customer-first-order` | `PromotionConditionContractTest` |
| [`PromotionAction`](../src/Contracts/PromotionAction.php) | `PromotionActionRegistry` | `percent-off-cart`, `fixed-off-cart`, `percent-off-product`, `free-shipping`, `buy-x-get-y`, `add-free-item`, `tiered-discount` | `PromotionActionContractTest` |
| [`KanbanCardWidget`](../src/Contracts/KanbanCardWidget.php) | `KanbanCardWidgetRegistry` | `total`, `item-count`, `customer`, `shipping-method`, `tags`, `days-in-column`, `payment-status`, `fulfillment-status` | `KanbanCardWidgetContractTest` |
| [`KanbanAutomationTrigger`](../src/Contracts/KanbanAutomationTrigger.php) | `KanbanAutomationRegistry` | `send-email`, `dispatch-job`, `webhook`, `update-order-field`, `create-shipment`, `print-shipping-label` | `KanbanAutomationTriggerContractTest` |
| [`NotificationTemplate`](../src/Contracts/NotificationTemplate.php) | `NotificationTemplateRegistry` | The catalog in `NotificationCatalog` | `NotificationTemplateContractTest` |
| [`SatelliteUninstaller`](../src/Contracts/SatelliteUninstaller.php) | Declared on the satellite's `SatelliteRegistry` descriptor | none | none. See [satellite-lifecycle.md](satellite-lifecycle.md) |

All registries live in `ArtisanPackUI\Ecommerce\Registries\` and are container
singletons. Each has `register( string $key, string|object $entry, array $meta = [] )`,
`has()`, `get()`, `all()`, `meta()`, and `keys()`. `$entry` is either a class
name (resolved from the container on first use) or an instance. Registering
the same key twice throws in `local`/`testing` and logs a warning everywhere
else. `ProductTypeRegistry::get()` returns a `MissingProductType` for unknown
keys instead of throwing.

```php
// In your satellite's service provider boot():
$this->app->make( \ArtisanPackUI\Ecommerce\Registries\ShippingRateProviderRegistry::class )->register(
    'acme:shippo',
    ShippoRateProvider::class,
    [ 'label' => __( 'Shippo' ) ],
);

// Container-bound contracts are swapped by rebinding:
$this->app->singleton( \ArtisanPackUI\Ecommerce\Contracts\CartStorage::class, RedisCartStorage::class );
```

Registry keys are `kebab-case`, optionally prefixed with the publisher
(`acme:shippo`); see engine spec §2.5.

### Method signatures

```php
interface ProductType {
    public function key(): string;
    public function label(): string;
    public function icon(): ?string;
    public function validateCartOptions( Product $product, array $options ): array;
    public function priceLine( Product $product, array $options, int $quantity, string $currency ): Money;
    public function requiresFulfillment(): bool;
    public function isInventoryTracked(): bool;
    public function buildOrderSnapshot( CartItem $item ): array;
    public function onOrderPlaced( Order $order, OrderItem $orderItem ): void;
}

interface PaymentGateway {
    public function key(): string;
    public function label(): string;
    public function supportsRefunds(): bool;
    public function supportsPartialRefunds(): bool;
    public function supportsSavedInstruments(): bool;
    public function createPaymentSession( Cart $cart, array $context = [] ): PaymentSession;
    public function capturePayment( Order $order, PaymentSession $session ): PaymentResult;
    public function voidPendingPayment( Order $order ): void;
    public function refund( Order $order, Money $amount, ?string $reason = null ): RefundResult;
    public function handleWebhook( Request $request ): WebhookResult;
}

interface FraudProvider {
    public function key(): string;
    public function label(): string;
    public function assess( Cart $cart, Address $shipping, PaymentSession $session ): FraudDecision;
}

interface TaxProvider {
    public function key(): string;
    public function label(): string;
    public function calculate( Cart $cart, Address $destination ): TaxResult;
}

interface ContextAwareTaxProvider extends TaxProvider {
    public function calculateWithContext( Cart $cart, TaxContext $context ): TaxResult;
}

interface ShippingRateProvider {
    public function key(): string;
    public function label(): string;
    public function getRatesForCart( Cart $cart, Address $destination ): Collection; // Collection<ShippingRate>
}

interface ShippingMethodType {
    public function key(): string;
    public function label(): string;
    public function calculate( Cart $cart, Address $destination, array $config ): ?Money; // null = not available
}

interface ShippingLabelProvider {
    public function key(): string;
    public function buyLabel( Shipment $shipment ): ShippingLabel;
    public function voidLabel( ShippingLabel $label ): void;
    public function trackLabel( ShippingLabel $label ): TrackingStatus;
}

interface FulfillmentAllocationStrategy {
    public function key(): string;
    public function allocate( Order $order, iterable $items ): array; // [ orderItemId => [ 'shipping' => Money, 'tax' => Money ] ]
}

interface CurrencyRateProvider {
    public function key(): string;
    public function getRateE8( Currency $from, Currency $to ): int; // rate × 10^8
}

interface CartStorage {
    public function find( string $token ): ?Cart;
    public function findForCustomer( int $customerId ): ?Cart;
    public function persist( Cart $cart ): void;
    public function delete( Cart $cart ): void;
}

interface OrderNumberGenerator {
    public function generate( Order $order ): string;
}

interface ReviewModerator {
    public function key(): string;
    public function moderate( ProductReview $review ): string; // approve | reject | spam | pending
}

interface PromotionCondition {
    public function key(): string;
    public function label(): string;
    public function evaluate( Cart $cart, array $config ): bool;
}

interface OrderAwarePromotionCondition extends PromotionCondition {
    public function evaluateOrder( Order $order, array $config ): bool;
}

interface PromotionAction {
    public function key(): string;
    public function label(): string;
    public function apply( Cart $cart, DiscountLedger $ledger, array $config ): void;
}

interface KanbanCardWidget {
    public function key(): string;
    public function label(): string;
    public function render( Order $order, KanbanColumn $column ): array; // render payload, never HTML
    public function refreshSubscription( Order $order ): ?string;
}

interface KanbanAutomationTrigger {
    public function key(): string;
    public function label(): string;
    public function fire( Order $order, KanbanAutomation $automation, array $config ): void;
}

interface NotificationTemplate {
    public function key(): string;
    public function label(): string;
    public function channel(): string;
    public function category(): string;
    public function variables(): array;
    public function previewData(): array;
    public function defaultSubject(): ?string;
    public function defaultBody(): string;
}

interface SatelliteUninstaller {
    public function uninstall( SatelliteDescriptor $satellite, bool $purge ): void;
}
```

> **Not implemented:** the parent plan's `SearchIndexer` contract (§6.1). Product
> search runs through Laravel Scout (`artisanpack.ecommerce.search.driver`), and
> satellites feed extra fields with the `ap.ecommerce.product.searchableData`
> filter. See [search.md](search.md).

## Verifying a satellite

Contract compliance is advisory but public (parent plan §15.2). The engine
boots any satellite that implements a contract. Running
`vendor/bin/ecommerce-verify-satellite` (which runs
`ecommerce:verify-satellite` through the satellite's `vendor/bin/testbench`;
satellites usually alias it as a composer script) finds the implementations a
satellite registers and runs the matching suites below against them. It then
writes a JSON report that CI can sign and attach to a release, which earns the
satellite a "contract-verified" badge. See
[satellite-verification.md](satellite-verification.md) and the satellite
directory in [satellites.md](satellites.md).

## Contract test suites

Every engine contract that satellites can implement ships with a companion
abstract test class under `ArtisanPackUI\Ecommerce\Testing\Contracts\`. Extend
the abstract class from your satellite's test suite, wire the required
factory method(s), and PHPUnit / Pest will run every invariant the engine's
reference implementations are held to against your driver.

There are fourteen suites — one per engine spec §4 contract that satellites
implement: `ProductType`, `CartStorage`, `OrderNumberGenerator`,
`FulfillmentAllocationStrategy`, `PaymentGateway`, `TaxProvider`,
`ShippingRateProvider`, `FraudProvider`, `PromotionCondition`,
`PromotionAction`, `KanbanCardWidget`, `KanbanAutomationTrigger`,
`ReviewModerator`, and `NotificationTemplate`.

Phase 2 database-backed suites share the
[`InteractsWithEcommerceCarts`](../src/Testing/Contracts/Concerns/InteractsWithEcommerceCarts.php)
trait, which boots the engine against in-memory SQLite and exposes
`makePersistedCart( $lines, $currency, $cartAttributes )` for building
fixtures, plus `assertCartUntouched()`, which checks the cart and its lines
are unchanged both in storage and in memory (so an implementation can't
quietly edit `$cart->items` attributes without saving). Every suite that
uses the trait also asserts the implementation's `key()` follows the
registry-key format from engine spec §2.5 (`kebab-case`, optionally
`publisher:`-prefixed).

The trait boots only `EcommerceServiceProvider`. A satellite whose driver
needs its own provider, config, or migrations overrides
`getPackageProviders()` and merges the parent list:

```php
protected function getPackageProviders( $app ): array
{
    return [ ...parent::getPackageProviders( $app ), ShippoServiceProvider::class ];
}
```

A complete satellite suite, for example
`tests/Contracts/ShippoRateProviderContractTest.php` in a hypothetical
`acme/ecommerce-shippo` package:

```php
<?php

declare( strict_types=1 );

namespace Acme\Shippo\Tests\Contracts;

use Acme\Shippo\ShippoRateProvider;
use Acme\Shippo\ShippoServiceProvider;
use ArtisanPackUI\Ecommerce\Contracts\ShippingRateProvider;
use ArtisanPackUI\Ecommerce\Testing\Contracts\ShippingRateProviderContractTest;
use Illuminate\Support\Facades\Http;

final class ShippoRateProviderContractTest extends ShippingRateProviderContractTest
{
    protected function getPackageProviders( $app ): array
    {
        return [ ...parent::getPackageProviders( $app ), ShippoServiceProvider::class ];
    }

    protected function provider(): ShippingRateProvider
    {
        return $this->app->make( ShippoRateProvider::class );
    }

    protected function seedServiceableDestination(): void
    {
        Http::fake( [ 'api.goshippo.com/*' => Http::response( [ 'rates' => [ /* … fixture … */ ] ] ) ] );
    }
}
```

Every test in the parent suite then runs against `ShippoRateProvider`.

### `ProductTypeContractTest`

Contract: [`ProductType`](../src/Contracts/ProductType.php) (engine spec §4.1).

Extension points a satellite must provide:

- `productType(): ProductType` — the concrete product type under test.
- `makeProduct(): Product` — a persisted product the type accepts (with a
  matching price row for `sampleCurrency()` / `sampleUnitPriceMinor()`).
- `sampleCartOptions(): array` — raw options handed to `validateCartOptions()`
  (include stowaway keys the type is expected to strip).
- `sampleSanitizedCartOptions(): array` — exact payload the type must return
  after sanitizing `sampleCartOptions()`.
- `sampleCurrency(): string` — currency the fixtures are priced in.
- `sampleUnitPriceMinor(): int` — expected unit price in minor units.

Invariants verified:

- Registry key is a non-empty, stable string.
- Label is a non-empty string.
- `icon()` returns `string` or `null`.
- `validateCartOptions()` returns exactly the payload declared by
  `sampleSanitizedCartOptions()` — stowaway keys are stripped.
- `priceLine()` returns a `Money` value in the requested currency and
  multiplies unit price by quantity.
- `buildOrderSnapshot()` records the type's registry key under the `type`
  key of the returned array.
- `requiresFulfillment()` and `isInventoryTracked()` return booleans.

Reference implementation:
[`SimpleProductType`](../src/ProductTypes/SimpleProductType.php) — see
`tests/Feature/Contracts/SimpleProductTypeContractTest.php`.

### `CartStorageContractTest`

Contract: [`CartStorage`](../src/Contracts/CartStorage.php) (engine spec §4.8).

Extension points a satellite must provide:

- `storage(): CartStorage` — the concrete storage driver under test.

Invariants verified:

- `find()` returns `null` for an unknown token.
- `findForCustomer()` returns `null` when the customer has no cart.
- `persist()` then `find()` by token returns an equivalent cart (token,
  currency, and `meta` all round-trip).
- Persisting a cart with attached items round-trips the item collection
  (quantity, options).
- `findForCustomer()` returns the persisted cart for an authenticated
  customer.
- `delete()` evicts the cart — a subsequent `find()` returns `null`.

Reference implementation:
[`DatabaseCartStorage`](../src/Services/DatabaseCartStorage.php) — see
`tests/Feature/Contracts/DatabaseCartStorageContractTest.php`.

### `OrderNumberGeneratorContractTest`

Contract: [`OrderNumberGenerator`](../src/Contracts/OrderNumberGenerator.php)
(engine spec §4.9).

Extension points a satellite must provide:

- `generator(): OrderNumberGenerator` — the concrete generator under test.

Invariants verified:

- `generate()` returns a non-empty string.
- 100 sequential calls return 100 distinct values (statistical uniqueness).
- **Optional, opt-in via `deterministicCollisionSeam()`**: when a subclass
  supplies a generator configured to try a specific `persisted` candidate
  first then a `free` candidate, the generator returns `free` after
  `persisted` has been seeded on the `orders` table — proving `generate()`
  actually reads existing rows before returning. Concurrent-collision
  handling is NOT covered by the contract test: that is the DB unique
  index's job (plus placement's one-shot retry).

Reference implementation:
[`RandomEightCharGenerator`](../src/Services/RandomEightCharGenerator.php) —
see `tests/Feature/Contracts/RandomEightCharGeneratorContractTest.php`.

### `FulfillmentAllocationStrategyContractTest`

Contract:
[`FulfillmentAllocationStrategy`](../src/Contracts/FulfillmentAllocationStrategy.php)
(engine spec §4.6, plan §16.7).

Extension points a satellite must provide:

- `strategy(): FulfillmentAllocationStrategy` — the concrete strategy
  under test.

Invariants verified (per plan §16.7):

- Returns exactly one allocation per input item, keyed by `OrderItem::id`,
  with a `{ shipping: Money, tax: Money }` pair per entry.
- Per-item shipping allocations sum exactly to `Order::shipping_amount`.
- Per-item tax allocations sum exactly to `Order::tax_amount`.

Reference implementation:
[`ProportionalByLineTotalStrategy`](../src/Fulfillment/ProportionalByLineTotalStrategy.php)
— see `tests/Feature/Contracts/ProportionalByLineTotalStrategyContractTest.php`.

### `PaymentGatewayContractTest`

Contract: [`PaymentGateway`](../src/Contracts/PaymentGateway.php) (engine spec §4.2).

Extension points a satellite must provide:

- `gateway(): PaymentGateway` — the gateway under test.
- `makeOrder( string $currency, int $capturedAmount ): Order` — a captured order.
- `signedWebhookRequest(): Request` / `unsignedWebhookRequest(): Request`.
- `captureTerminalDeclineResult(): PaymentResult` / `captureRetryableResult(): PaymentResult`.
- Optional: override `gatewaySupportsRefunds()` to `false` to skip refund cases.

Invariants verified:

- Multiple partial refunds each report their own amount and sum exactly to
  the total refunded.
- Signed webhooks verify and carry an event id; unsigned ones do not verify
  and carry an error code.
- Terminal capture failures are not retryable; provider brownouts are.
- `capturePayment()` / `refund()` throw `PaymentCurrencyMismatchException`
  for money outside the order's currency.

Reference implementation: an in-memory gateway — see
`tests/Feature/Contracts/InMemoryPaymentGatewayContractTest.php`.

### `TaxProviderContractTest`

Contract: [`TaxProvider`](../src/Contracts/TaxProvider.php) (engine spec §4.5).

Extension points a satellite must provide:

- `provider(): TaxProvider` — the provider under test.
- Optional `seedTaxableJurisdiction(): void` — arrange (seed rates, stub the
  HTTP client) for `destination()` to be taxable.
- Optional `expectsTaxForFixture(): bool` — return `false` to skip the
  "actually taxes" case when a taxable fixture can't be arranged.
- Optional `destination(): Address` — defaults to Chicago, IL 60601.

Invariants verified:

- Every amount in the `TaxResult` is in the cart currency (checked for USD
  and EUR carts).
- `perLine` holds exactly one entry per cart item, keyed by cart-item id.
- `total` = Σ `perLine` + `shipping`; when a breakdown is returned,
  Σ breakdown amounts = `total`.
- No negative tax, overall or per line.
- The seeded jurisdiction levies positive tax, so a provider that always
  returns zero fails.
- An empty cart owes zero with an empty `perLine`.
- Calculation is deterministic and leaves the cart and its lines untouched
  (storage and memory).

Reference implementation: [`ManualTaxProvider`](../src/Tax/ManualTaxProvider.php)
— see `tests/Feature/Contracts/ManualTaxProviderContractTest.php`.

### `ShippingRateProviderContractTest`

Contract: [`ShippingRateProvider`](../src/Contracts/ShippingRateProvider.php)
(engine spec §4.3).

Extension points a satellite must provide:

- `provider(): ShippingRateProvider` — the provider under test.
- Optional `seedServiceableDestination(): void` — stub the carrier API /
  seed zones so `destination()` gets rates.
- Optional `destination(): Address`.

Invariants verified:

- A serviceable destination yields at least one rate.
- Every rate has a non-empty method key and label, and a non-negative amount
  in the cart currency (USD and EUR carts).
- Rate `id()`s are unique so a client can select one.
- An empty cart yields no rates.
- Quoting leaves the cart and its lines untouched (storage and memory).

Reference implementation:
[`ZoneShippingRateProvider`](../src/Shipping/ZoneShippingRateProvider.php) — see
`tests/Feature/Contracts/ZoneShippingRateProviderContractTest.php`.

### `FraudProviderContractTest`

Contract: [`FraudProvider`](../src/Contracts/FraudProvider.php) (engine spec §4.17).

Extension points a satellite must provide:

- `provider(): FraudProvider` — the provider under test (typically wired to a
  stubbed API client keyed off the session reference).
- Optional `lowRiskSession( Cart ): PaymentSession` — defaults to reference
  `pi_low_risk`.
- Optional `highRiskSession( Cart ): ?PaymentSession` — return a session the
  provider must escalate; the default `null` skips that case.
- Helper `paymentSessionFor( Cart, string $reference )` builds a session.

Invariants verified:

- Decisions are well-formed: verdict in `approve|challenge|block`, score
  0–100, string reasons.
- The low-risk fixture is approved.
- The high-risk fixture (when supplied) is challenged or blocked.
- Assessment leaves the cart and its lines untouched.

Reference implementations:
[`AlwaysApproveFraudProvider`](../src/Services/Fraud/AlwaysApproveFraudProvider.php)
and [`StripeRadarFraudProvider`](../src/Services/Fraud/StripeRadarFraudProvider.php)
— see `tests/Feature/Contracts/AlwaysApproveFraudProviderContractTest.php` and
`tests/Feature/Contracts/StripeRadarFraudProviderContractTest.php`.

### `PromotionConditionContractTest`

Contract: [`PromotionCondition`](../src/Contracts/PromotionCondition.php)
(engine spec §4.10).

Extension points a satellite must provide:

- `condition(): PromotionCondition` — the condition under test.
- `satisfiedCase(): array{Cart, array}` — a cart + config that must pass.
- `unsatisfiedCase(): array{Cart, array}` — a cart + config that must fail.

Invariants verified:

- The satisfying fixture passes and the unsatisfying fixture fails.
- Evaluation never throws — on an empty cart, empty config, or malformed
  config (wrong types, garbage values) it returns a `bool`.
- Evaluation leaves the cart and its lines untouched.

Reference implementations: `min-subtotal`, `cart-contains-product`,
`cart-contains-product-type`, `customer-in-group`, `day-of-week`,
`customer-first-order` under
[`src/Promotions/Conditions/`](../src/Promotions/Conditions/) — see
`tests/Feature/Contracts/*ConditionContractTest.php`.

### `PromotionActionContractTest`

Contract: [`PromotionAction`](../src/Contracts/PromotionAction.php)
(engine spec §4.11).

Extension points a satellite must provide:

- `action(): PromotionAction` — the action under test.
- `applicableCase(): array{Cart, array}` — a cart + config the action must
  affect.

Invariants verified:

- The applicable fixture produces an effect: a positive discount, free
  shipping, or a free item.
- An empty cart is never discounted; empty or malformed config never throws
  and grants no discount.
- Applying leaves the cart and its lines untouched in storage and memory
  (actions write only to the `DiscountLedger`).
- Applied five times to one ledger, the total stays within the subtotal and
  in the cart currency. `DiscountLedger` enforces this itself (it clamps
  every write and rejects other currencies), so this documents the
  contract rather than policing the action.

Reference implementations: `percent-off-cart`, `fixed-off-cart`,
`percent-off-product`, `free-shipping`, `buy-x-get-y`, `add-free-item`,
`tiered-discount` under [`src/Promotions/Actions/`](../src/Promotions/Actions/) —
see `tests/Feature/Contracts/*ActionContractTest.php`.

### `KanbanCardWidgetContractTest`

Contract: [`KanbanCardWidget`](../src/Contracts/KanbanCardWidget.php)
(engine spec §4.12). Fixtures come from
[`InteractsWithKanban`](../src/Testing/Contracts/Concerns/InteractsWithKanban.php),
which builds on `InteractsWithEcommerceCarts` and adds `makeKanbanOrder()`,
`makeKanbanColumnFor()`, and `makeKanbanAutomation()`.

Extension points a satellite must provide:

- `widget(): KanbanCardWidget` — the widget under test.
- Optionally `order(): Order` — a richer order to render.

Invariants verified:

- `render()` returns string `label` + `value`, a known `tone` when present,
  and string `icon` / `tooltip` / `href` when present — never HTML.
- A bare order (no lines, customer, addresses, or shipping method) renders
  without throwing.
- Rendering leaves the order untouched in storage and memory.
- `refreshSubscription()` returns `null` or a non-empty channel name.

Reference implementations: `total`, `item-count`, `customer`,
`shipping-method`, `tags`, `days-in-column`, `payment-status`,
`fulfillment-status` under [`src/Kanban/Widgets/`](../src/Kanban/Widgets/) —
see `tests/Feature/Contracts/*WidgetContractTest.php`.

### `KanbanAutomationTriggerContractTest`

Contract: [`KanbanAutomationTrigger`](../src/Contracts/KanbanAutomationTrigger.php)
(engine spec §4.13).

Extension points a satellite must provide:

- `trigger(): KanbanAutomationTrigger` — the trigger under test.
- `validConfig(): array` — a config the trigger must accept.
- `invalidConfig(): array` — a config it must reject with
  `InvalidArgumentException`.
- `assertFired( Order, KanbanAutomation ): void` — asserts the side effect of
  firing with the valid config. Fake Mail / Bus / Http in `setUp()`.

Invariants verified:

- The valid config produces the side effect.
- The invalid config throws `InvalidArgumentException` (the runner logs it
  and carries on with the board's other automations).
- Garbage config (objects, nested arrays in place of scalars) either works or
  throws `InvalidArgumentException` — never a `TypeError` or other crash.

Reference implementations: `send-email`, `dispatch-job`, `webhook`,
`update-order-field`, `create-shipment`, `print-shipping-label` under
[`src/Kanban/Triggers/`](../src/Kanban/Triggers/) — see
`tests/Feature/Contracts/*TriggerContractTest.php`.

### `ReviewModeratorContractTest`

Contract: [`ReviewModerator`](../src/Contracts/ReviewModerator.php)
(engine spec §4.16).

Extension points a satellite must provide:

- `moderator(): ReviewModerator` — the concrete moderator under test.
- Optional `reviews(): array<string, ProductReview>` — extra review
  fixtures the moderator treats specially.

Invariants verified:

- `key()` is a non-empty string.
- `moderate()` returns one of `approve`, `reject`, `spam`, `pending` for
  every fixture (typical, empty body, verified purchase, very long body).
- `moderate()` never changes or saves the review — `ReviewService` applies
  the verdict.

Reference implementation:
[`NoopReviewModerator`](../src/Reviews/NoopReviewModerator.php) — see
`tests/Feature/Contracts/NoopReviewModeratorContractTest.php`.

### `NotificationTemplateContractTest`

Contract: [`NotificationTemplate`](../src/Contracts/NotificationTemplate.php)
(engine spec §4.14).

Extension points a satellite must provide:

- `template(): NotificationTemplate` — the definition under test.

Invariants verified:

- `key()` is lowercase dot-separated segments; `label()`, `channel()`,
  `category()`, and `defaultBody()` are non-empty.
- Every entry in `variables()` is a dotted path (`Order.items.*.name`) whose
  root appears in `previewData()`.
- The default subject and body compile in the notification sandbox,
  reference only declared variables, and render the preview data.

Reference implementation: the engine catalog in
[`NotificationCatalog`](../src/Notifications/NotificationCatalog.php) — see
`tests/Feature/Contracts/OrderShippedTemplateContractTest.php` (every catalog
entry is also checked in `tests/Feature/Notifications/NotificationTemplateRendererTest.php`).

### Adding a new contract test suite

When a Phase 2+ contract lands, add:

1. `src/Testing/Contracts/<Contract>ContractTest.php` — abstract PHPUnit
   test class (`extends TestCase` or `extends Orchestra\Testbench\TestCase`
   if it needs a booted app / DB). Declare abstract extension points for
   whatever the satellite must supply, then assert the invariants the
   engine relies on.
2. Reference implementation in `src/`, plus a concrete test under
   `tests/Feature/Contracts/` or `tests/Unit/Contracts/` that extends the
   abstract contract test suite and returns the reference implementation
   from the required accessor.
3. A new subsection in this file linking to the contract, the invariants,
   and the reference implementation.
