# Contract test suites

Every engine contract that satellites can implement ships with a companion
abstract test class under `ArtisanPackUI\Ecommerce\Testing\Contracts\`. Extend
the abstract class from your satellite's test suite, wire the required
factory method(s), and PHPUnit / Pest will run every invariant the engine's
reference implementations are held to against your driver.

Phase 1 ships four suites — one per Phase 1 contract listed in engine
spec §4.

## `ProductTypeContractTest`

Contract: [`ProductType`](../src/Contracts/ProductType.php) (engine spec §4.1).

Extension points a satellite must provide:

- `productType(): ProductType` — the concrete product type under test.
- `makeProduct(): Product` — a persisted product the type accepts (with a
  matching price row for `sampleCurrency()` / `sampleUnitPriceMinor()`).
- `sampleCartOptions(): array` — a valid cart-line payload.
- `sampleCurrency(): string` — currency the fixtures are priced in.
- `sampleUnitPriceMinor(): int` — expected unit price in minor units.

Invariants verified:

- Registry key is a non-empty, stable string.
- Label is a non-empty string.
- `icon()` returns `string` or `null`.
- `validateCartOptions()` returns an array (sanitised options).
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
- 100 sequential calls return 100 distinct values.
- After seeding an existing row with a previously-generated number, 25
  further calls never return the seeded number (collision-safe).

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

## Adding a new contract test suite

When a Phase 2+ contract lands, add:

1. `src/Testing/Contracts/<Contract>ContractTest.php` — abstract PHPUnit
   test class (`extends TestCase` or `extends Orchestra\Testbench\TestCase`
   if it needs a booted app / DB). Declare abstract extension points for
   whatever the satellite must supply, then assert the invariants the
   engine relies on.
2. Reference implementation in `src/` — must extend the abstract test suite
   from `tests/Feature/Contracts/` (or `tests/Unit/Contracts/`).
3. A new subsection in this file linking to the contract, the invariants,
   and the reference implementation.
