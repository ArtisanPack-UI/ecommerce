# Contract test suites

Every engine contract that satellites can implement ships with a companion
abstract test class under `ArtisanPackUI\Ecommerce\Testing\Contracts\`. Extend
the abstract class from your satellite's test suite, wire the required
factory method(s), and PHPUnit / Pest will run every invariant the engine's
reference implementations are held to against your driver.

There are twelve suites — one per engine spec §4 contract that satellites
implement: `ProductType`, `CartStorage`, `OrderNumberGenerator`,
`FulfillmentAllocationStrategy`, `PaymentGateway`, `TaxProvider`,
`ShippingRateProvider`, `FraudProvider`, `PromotionCondition`,
`PromotionAction`, `KanbanCardWidget`, and `KanbanAutomationTrigger`.

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

## `ProductTypeContractTest`

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

## `CartStorageContractTest`

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

## `OrderNumberGeneratorContractTest`

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

## `FulfillmentAllocationStrategyContractTest`

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

## `PaymentGatewayContractTest`

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

## `TaxProviderContractTest`

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

## `ShippingRateProviderContractTest`

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

## `FraudProviderContractTest`

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

## `PromotionConditionContractTest`

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

## `PromotionActionContractTest`

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

## `KanbanCardWidgetContractTest`

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

## `KanbanAutomationTriggerContractTest`

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

## Adding a new contract test suite

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
