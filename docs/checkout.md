# Checkout

The engine provides the checkout services and APIs. Storefronts build the flow
and the UI on top of them: one page, several steps, or an express button. The
engine only enforces what a step needs (you can't pay without a total) and the
`ap.ecommerce.checkout.canTransitionTo` guard.

Every surface uses the same services. The REST endpoints, the GraphQL
mutations, and in-process storefronts (Livewire, Blade) all call
`StorefrontCartService` and `CheckoutService`, so they share one set of rules.
For the route list, request bodies, rate-limit policies, and idempotency rules,
see [api.md](api.md#rest-routes).

## Carts

`Services\StorefrontCartService` holds the shopper-facing cart operations:
`create()`, `addItem()`, `updateItem()`, `removeItem()`, `clear()`,
`applyCoupon()`, `removeCoupon()`, `updateDetails()`, `changeCurrency()`,
`quoteShipping()`, `selectShippingMethod()`, and `recalculate()`.

- **Prices come from the server.** Each line is priced through its product
  type's `priceLine()` in the cart's currency, and every line is re-priced on
  every change. A client never sends a unit price.
- **Only sellable products go in.** The product must be visible on the
  storefront, a variant must belong to its product, and the options go through
  the product type's `validateCartOptions()`. A variable product needs a
  variant, and a grouped product isn't sold on its own.
- **Limits.** A line can't ask for more than is in stock, and holds at most
  `StorefrontCartService::MAX_LINE_QUANTITY` (10,000) units. A cart holds at
  most `cart.max_lines` distinct lines (default 100, the `MAX_LINES` constant).
  Free items granted by promotions don't count toward that limit.
- **Locking.** Every change runs under a row lock on the cart, together with
  the totals refresh.
- **Closed carts.** A cart that became an order or expired can't be changed.

Expected failures throw `CartOperationException`. Over REST this is a 422
problem response with a field error such as `coupon-invalid` or
`item-locked`.

### Expiry

A cart expires after `cart.ttl_days` (default 30) without a change. Every
change pushes `expires_at` back. `ecommerce:prune-carts` deletes expired carts
daily. It also deletes converted carts once `cart.ttl_days` have passed. A
converted cart is kept that long so a repeated finalize still finds its order.
Each cart's stock holds are released before it is deleted.

### Currency

`create()` takes an ISO 4217 code. It defaults to the base currency and must
be one of the store's enabled currencies (`currency.enabled`).
`changeCurrency( $cart, 'EUR' )` re-prices every line in the new currency,
re-applies promotions, and drops the shipping rate and any payment session,
because their amounts were in the old currency. A line with no price in the new
currency refuses the change. `changeCurrency()` is a PHP API only; REST carts
keep the currency they were created with.

### Coupons

`applyCoupon()` re-prices the lines first, then checks the code against current
prices. A new code replaces the old one. A refused coupon throws with the code
`coupon-{status}`:

| Code | Meaning |
|---|---|
| `coupon-invalid` | Unknown code |
| `coupon-inactive` | The promotion is disabled or outside its date window |
| `coupon-exhausted` | No usage left, overall or for this customer |
| `coupon-not-eligible` | The cart doesn't meet the promotion's conditions |
| `coupon-not-combinable` | Lost to an exclusive promotion, or is exclusive and another promotion already applied |

By default, `promotions.verbose_coupon_errors` is `false`. In that mode,
`inactive` and `exhausted` are reported as `coupon-invalid`, so the API
doesn't reveal which codes exist. Turn the setting on for the specific
messages. Coupon attempts have their own rate-limit policy,
`ecommerce.coupon.attempt`.

An applied coupon that stops applying (for example, the cart drops below a
minimum) is removed at the next totals refresh. At order placement, a coupon
that stopped applying refuses the order.

### Shipping

`quoteShipping( $cart, $address )` returns the rates for a destination.
`selectShippingMethod( $cart, $address, $rateId )` re-quotes on the server and
stores the chosen rate in `carts.meta.shipping_rate`; the client never sends a
price. When a later change alters the lines or the destination, the rate is
quoted again. If the rate is no longer offered at the same price, it is dropped
and `carts.meta.shipping_rate_invalidated` is set. A promotion can make
shipping free; those promotions are listed in `carts.meta.free_shipping`.

### Totals and tax

Every change runs `refreshTotals()`:

1. **Subtotal**, through `ap.ecommerce.pricing.subtotal`.
2. **Promotions**: automatic ones plus the applied coupon. Free-item lines are
   added, resized, or removed to match, and each line's share of the discount
   is stored.
3. **Shipping**: free when a promotion grants it; otherwise the selected rate.
4. **Tax** through the active tax provider, once a destination is known. The
   destination is the shipping address, else the billing address, else the
   destination the shipping rate was quoted for.
5. **Total** = subtotal − discount + shipping + tax, through
   `ap.ecommerce.pricing.total`. In a tax-inclusive store
   (`tax.prices_include_tax`), tax is already in the prices and isn't added
   again.

The tax breakdown is stored in `carts.meta.tax`:

```json
{
  "estimated": false,
  "prices_include_tax": false,
  "breakdown": [ ... ]
}
```

`estimated` is `true` until a destination is known. While it is `true`, tax
is zero, so show the total as an estimate. If the tax provider errors,
`estimated` stays `true` and `error: true` is added.

Lines that can no longer be sold are listed in
`carts.meta.unsellable_item_ids`. This happens when the product was deleted, or
when its product type's satellite was uninstalled. Order placement refuses a
cart that has any.

## The current cart

`Services\CurrentCart` answers "which cart belongs to this shopper" for every
storefront:

- A signed-in shopper gets their customer's open cart. If they have none, the
  guest cart from their token is attached to their account.
- A guest gets the open cart their token names, as long as it doesn't belong
  to an account. A token left in a shared browser after sign-out doesn't open
  the account's cart.
- `resolve( $token, $user, create: true )` creates a cart when none is found.

```php
use ArtisanPackUI\Ecommerce\Services\CurrentCart;
use ArtisanPackUI\Ecommerce\Support\GuestCartCookie;

$cart = app( CurrentCart::class )->resolve( GuestCartCookie::tokenFrom( $request ), $request->user(), create: true );

if ( null === $cart->customer_id ) {
    GuestCartCookie::queue( $cart );
}
```

### The guest cookie

`Support\GuestCartCookie` is the cookie that carries a guest's 40-character
cart token. Every storefront shares the same name and lifetime: `cart.cookie`
(default `ecommerce_cart`) and `cart.cookie_lifetime` (minutes, default 30
days). The host's `EncryptCookies` middleware encrypts it like any other
cookie. Use `tokenFrom( $request )`, `queue( $cart )`, and `forget()` rather
than reading the cookie yourself.

Over REST, the token in the URL is the credential for a guest cart. A cart that
belongs to an account also needs that account's session or Sanctum token.

### Merge on login

When `cart.merge_on_login` is on (the default), the engine listens for
Laravel's `Login` event and merges the guest cart from the cookie into the
account cart. After the merge, the guest cookie is cleared. A failed merge is
logged and never interrupts the login.

`CartMergeService` follows these rules:

- Lines with the same product, variant, and options sum their quantities. The
  sum is capped at `MAX_LINE_QUANTITY` and at what is in stock. A destination
  line never shrinks.
- Lines that differ in variant or options stay separate. Guest lines past
  `cart.max_lines` are left out.
- Free promotion lines aren't carried over; the merged cart's own promotions
  decide them again.
- The guest cart's stock reservations move to the destination.
- Every line is re-priced from the catalog in the kept currency, never
  converted. A line with no price in that currency is dropped.
- The destination cart's token is rotated, and `CartMerged` is dispatched.

When the currencies differ, the merge waits for the shopper to choose. During a
login merge, `CurrentCart` stores a `PendingCartMerge` in the session. Read it
with `pendingMerge( $session )`, then call `resolvePendingMerge( $session,
$user, $resolution )` with a `CartMergeResolution`: `keep_guest_currency`,
`switch_to_account_currency`, or `cancel_merge`.

Over REST, `POST carts/{cart}/merge` merges the guest cart `{cart}` into the
signed-in shopper's cart. On a currency mismatch it answers 409
`cart-currency-mismatch`, with the two currencies under `merge`. Send it again
with `{ "resolution": "keep_guest_currency" }` (or another resolution).

## Checkout steps

`Services\CheckoutService` provides the building blocks. Each step can be
called again, and steps that need an earlier one (an address, for example)
start checkout themselves.

| Method | REST | What it does |
|---|---|---|
| `start( $cart )` | `POST checkout/{cart}/start` | Marks checkout started, holds stock, and moves to `addressing`. Returns a `CheckoutStart` with `adjustments` |
| `setEmail( $cart, $email )` | `POST checkout/{cart}/address` | Contact email |
| `setAddress( $cart, $shipping, $billing )` | `POST checkout/{cart}/address` | Addresses; moves to `shipping_selection`, or `payment_selection` when nothing ships |
| `shippingRates( $cart )` | `GET checkout/{cart}` | Rates for the cart's shipping address |
| `setShippingMethod( $cart, $rateId )` | `POST checkout/{cart}/shipping-method` | Re-quotes the rate on the server; moves to `payment_selection` |
| `availableGateways( $cart )` | `GET checkout/{cart}` | Payment gateways that can take this cart |
| `setPaymentGateway( $cart, $key )` | `POST checkout/{cart}/payment-gateway` | Chooses the gateway |
| `createPaymentSession( $cart, $context )` | `POST checkout/{cart}/session` | Creates the provider session; moves to `payment_pending` |
| `finalize( $cart, $reference, $context )` | `POST checkout/{cart}/finalize` | Places the order and captures the payment |

Every checkout response is the cart plus a `checkout` object: `state`,
`requires_shipping`, `shipping_rates`, `gateways` (`key`, `label`),
`guest_checkout`, and `account_creation`. `start` adds `adjustments`, and
`session` adds `payment`.

Failures a shopper can fix throw `CheckoutException`. Over REST these are 422
unless noted:

| Code | Status | When |
|---|---|---|
| `address-required` | 422 | No address was given |
| `gateway-required` | 422 | No gateway was chosen |
| `gateway-unavailable` | 422 | The gateway can't take this cart |
| `payment-not-required` | 422 | The total is zero; finalize directly |
| `payment-session-required` | 422 | Finalize called before a session exists |
| `account-required` | 403 | Guest checkout rules (see below) |
| `transition-blocked` | 409 | A `canTransitionTo` listener refused the move |
| `payment-reference-mismatch` | 409 | The `payment_reference` isn't this cart's session |
| `payment-canceled` | 409 | The provider session was cancelled; create a new one |
| `payment-in-progress` | 409 | Another finalize of the same order is running |
| `payment-not-allowed` | 409 | The order can no longer be paid |
| `payment-amount-mismatch` | 409 | The session doesn't match the order total |

Order placement adds its own errors. The most common is `totals-changed`: the
total moved after the session was created. The session is dropped; create a new
one and finalize again.

### Checkout states

The cart's `checkout_state` follows `Checkout\CheckoutState`:

`not_started` → `addressing` → `shipping_selection` → `payment_selection` →
`payment_pending` → `payment_confirmed` → `completed`, plus `failed`.

Forward moves run the `ap.ecommerce.checkout.canTransitionTo` filter with
`(true, Cart $cart, string $to)`. Return anything other than `true` to block
the move. Changes that undo an earlier step move the state back without
asking. Examples: a new line after a rate was chosen, or a changed total after
a payment session was made. `CheckoutState::rank()` and `isAfter()` compare
states. The engine itself doesn't set `payment_confirmed`.

### Stock reservations

`start()` holds the cart's stock through `Checkout\CheckoutReservations` for
`checkout.reservation_ttl_minutes` (default 15). Every tracked unit a line
needs is reserved, including a bundle's members. The cart's earlier holds are
released first, so calling `start()` again holds exactly what the cart has now.
Inventory rows are locked in id order before anything is counted, so two carts
can't both get the last unit.

When a line asks for more than is left, `start()` reduces the line, or removes
it when nothing is left. It reports each change in `adjustments`:

```json
[ { "item_id": 12, "product_id": 4, "requested": 3, "available": 1 } ]
```

Show these to the shopper. At order placement, a shortfall refuses the order
instead. `ecommerce:release-expired-reservations` frees expired holds every
minute.

## Payment sessions

`createPaymentSession()` creates the provider-side session the shopper confirms
in the browser. The session's reference is stored on the cart, and the session
is reused while the cart, the gateway, and the amount stay the same. Pass
`return_url` (it must be on your site) and, over REST, an `Idempotency-Key`
header. The `ap.ecommerce.checkout.paymentInitiated` action fires when a new
session is created.

The REST response's `checkout.payment` holds `reference`, `status`, `amount`,
and `client`. `client` tells the storefront how to render the payment step.

### Client rendering

Gateways whose payment step runs in the browser implement
`Contracts\RendersClientPayment::clientConfig( Cart $cart, PaymentSession
$session ): array`. Every storefront (Livewire, React, Vue) reads the same
shape:

```php
[
    'driver'          => 'stripe-payment-element', // which client component renders it
    'flow'            => 'embedded',               // or 'redirect'
    'publishable_key' => 'pk_…',                   // optional
    'client_secret'   => 'pi_…_secret_…',          // optional
    'redirect_url'    => null,                     // for redirect flows
    'options'         => [ … ],                    // driver-specific (locale, appearance, …)
]
```

Known drivers are `stripe-payment-element` (core), `paypal-buttons`,
`square-web-payments`, and `redirect`. A gateway without the contract is
rendered as `redirect` when its session has a redirect URL; otherwise it isn't
renderable and `client` is `null`. `Support\ClientPaymentConfig::for()` builds
the array. Hosts can change it with the `ap.ecommerce.payment.clientConfig`
filter, which gets `(?array $config, PaymentGateway $gateway, Cart $cart,
PaymentSession $session)`.

**PCI.** The array may only hold values the provider means to be public, such
as publishable keys and client secrets scoped to one payment. Card data never
reaches your server: the client component sends it straight to the provider.
Never add secret keys to `options` through the filter. The
`ecommerce:lint:pci-columns` command fails the build if a migration declares a
column for card data.

## Finalize

`finalize( $cart, $paymentReference, $context )` places the order and captures
the session the shopper confirmed. It is idempotent per cart. Once the cart is
an order, a repeat call returns that order, and resumes its payment if the
payment is still pending.

1. The guest checkout policy is checked.
2. The session must exist, match `$paymentReference` when one is given, and be
   in the cart's currency.
3. `Services\OrderPlacementService::place()` turns the cart into an order.
4. `Services\PaymentOrchestrator::finalize()` assesses fraud and captures.

A zero-total order is placed and marked paid with no gateway.

Over REST, the response is the order plus a `checkout` object with `status`,
`complete`, and `step_up_token`. The status code is 201 when the order is paid
and 200 otherwise.

| `checkout.status` | Cart state | What to do |
|---|---|---|
| `captured` | `completed` | Show the confirmation |
| `challenged` | `payment_pending` | Complete the step-up (3DS, redirect) with `step_up_token`, then finalize again |
| `failed` | `payment_pending` | The capture was declined or errored; the order stays `pending`, so the shopper can retry |
| `blocked` | `failed` | Fraud block; the order is `failed` and its reservations are released |

`ap.ecommerce.checkout.finalized` fires on `captured`, and
`ap.ecommerce.checkout.failed` fires on a terminal failure.

### Order placement

`OrderPlacementService::place()` runs every step in one transaction under the
cart's row lock. A failure leaves nothing behind, and two placements of the
same cart produce one order.

1. The cart must be open.
2. Lines are re-priced, promotions re-evaluated, the shipping rate re-quoted,
   and tax recalculated. A coupon that stopped applying refuses the order.
3. The cart must be complete: sellable lines, an email, a billing address (the
   shipping address can stand in), and, when something ships, a shipping
   address and a current rate. With `expected_total` in the context (finalize
   passes the session amount), the total must still match.
4. Stock is held; a shortfall refuses the order.
5. The currency, base currency, and FX rate are snapshotted.
6. The order attributes go through `ap.ecommerce.order.placing`. The number
   comes from the `OrderNumberGenerator` through `ap.ecommerce.order.number`.
   Each line is snapshotted with its own discount and tax.
7. Shipping, and tax not tied to a line, are split across the lines by the
   configured allocation strategy (`fulfillment.allocation_strategy`).
8. The cart's reservations become the order's, and they no longer expire.
9. Promotion usage is recorded. Usage limits are enforced under the lock.
10. The cart is cleared as `converted` and pointed at the order.
11. Each line's product type runs `onOrderPlaced()`.

After the commit, `ap.ecommerce.order.placed` fires, and `OrderPlaced`,
`CartCompleted`, `PromotionApplied` (once per promotion), and `CouponRedeemed`
are dispatched. The order is `pending`, with payment `pending`, until the
orchestrator captures.

### Payment orchestration

`PaymentOrchestrator` runs the same sequence for every gateway: resume the
session, assess fraud, capture.

- **Claim.** Under the order's row lock, the orchestrator checks the order can
  still be paid, then sets `payment_status` to `processing` with an attempt id.
  The claim lasts `PaymentOrchestrator::CLAIM_LEASE_SECONDS` (120). A
  concurrent finalize gets `PaymentInProgressException`. After that time, a
  claim left behind by a crash can be taken over. A repeat finalize after a
  terminal outcome returns that outcome without calling the gateway.
- **Resume.** The order's `payment_reference` session is fetched through
  `PaymentGateway::retrievePaymentSession()`, never re-created, so a retry
  can't charge twice. Its amount and currency must match the order.
- **Outcome.**
  - *Captured*: the order moves to `processing`, `payment_status` to `paid`,
    and its reservations are committed. If the order was cancelled while the
    capture ran, the capture is refunded.
  - *Challenged*: the order stays `pending` and the result carries the step-up
    token. Once the shopper completes the step-up, a fraud `challenge` on that
    session counts as satisfied.
  - *Blocked*: the authorization is voided (or the capture refunded), the
    order moves to `failed`, and the reasons go to `orders.meta.fraud_decision`.
    They are never shown to the customer.
  - *Failed*: the order stays `pending` for a retry.

`PaymentSucceeded`, `PaymentFailed`, `FraudChallenged`, and `FraudBlocked` are
dispatched after each change commits.

`resume( $order, $context )` settles the session already on an order. Use it
after a step-up, or to retry a capture. A fraud `challenge` on a session the
shopper already confirmed has no client-side fix, so the payment is held for
review with no step-up token. Only an in-process call can capture it:

```php
use ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator;

app( PaymentOrchestrator::class )->resume( $order, [ PaymentOrchestrator::ACCEPT_CHALLENGE => true ] );
```

The engine has no REST endpoint for this. `CheckoutService` removes
`accept_challenge` from any context it receives, so a shopper can't send it.
To reject a held payment, cancel the order.

### Stripe Radar

Set `fraud.provider` to `stripe-radar` to use Radar's verdict. The provider
reads `charge.outcome.risk_level` on the PaymentIntent's latest charge:

| Risk level | Decision |
|---|---|
| `normal`, `not_assessed`, `unknown` | approve |
| `elevated` | challenge |
| `highest` | block |

Radar scores a charge, and a PaymentIntent only has a charge after the shopper
confirms it. **Finalize only after the client has confirmed the intent.** A
session with no charge yet is challenged with the reason `no_charge`. That
happens whatever `fraud.fail_open` says, so the payment is held for review.
`fail_open` only covers Stripe being unreachable: `true` approves with a
`provider_error` reason, and `false` (the default) challenges. Sessions from
other gateways are approved with `not_applicable`.

With `gateways.stripe.capture_method` set to `manual`, the money is only
authorized when the shopper confirms, so the fraud check runs before anything
is captured. With `automatic` (the default), confirming captures, and a blocked
payment is refunded.

## Reconciliation

A shopper may close the tab after confirming, or pay with an asynchronous
method. Their order still gets placed, through `Services\PaymentReconciler`:

- **Webhooks.** A verified gateway webhook that carries a payment outcome
  queues `Jobs\ReconcilePaymentSession` (on `webhooks.connection` /
  `webhooks.queue`).
- **Polling.** `ecommerce:reconcile-payments` runs every 15 minutes. It asks the
  provider about `payment_pending` carts, and `pending` orders with a session,
  that have been quiet for `checkout.reconcile_after_minutes` (default 15) and
  are no more than 7 days old. `--minutes=` overrides the quiet period.

The reconciler acts on the outcome:

| Outcome | Result |
|---|---|
| `succeeded` | The cart is finalized through `CheckoutService::finalize()`, the same idempotent path the storefront uses. A finalize already running elsewhere is left alone |
| `failed` | The cart's stock holds are released, the cart moves back to `payment_selection`, and `ap.ecommerce.checkout.failed` fires. A placed order is left `pending` for a retry |
| `requires_action`, `refunded` | Nothing |

Because of this, a queue worker and the scheduler are part of checkout. See
[operations.md](operations.md).

## Guest checkout

`checkout.guest_checkout` decides what a shopper without an account can do:

| Value | Behavior |
|---|---|
| `allowed` (default) | Guests check out normally |
| `required_account` | Guests can fill in checkout, but the cart needs an account before paying (403 `account-required` at session and finalize) |
| `disabled` | Guests must sign in first (403 `account-required` when checkout starts) |

`checkout.account_creation` (default `true`) tells storefronts whether to
offer account creation at checkout. Both values are in every checkout response
as `guest_checkout` and `account_creation`.

These two keys, `checkout.reservation_ttl_minutes`, and
`cart.abandoned_after_minutes` are also store settings in the `checkout`
group, so an admin can change them at runtime. The config values are the
defaults. See [settings.md](settings.md).

Guests find their order afterwards by email and order number, or through the
signed link in their confirmation email. Set `checkout.order_view_url` to your
storefront's order page (with a `{token}` placeholder); otherwise the link
points at the REST `order-views/{token}` endpoint. See
[customers.md](customers.md).

## Abandoned carts

`ecommerce:flag-abandoned-carts` runs every 5 minutes. It flags a cart as
abandoned when checkout started, an email is known, the cart isn't an order and
hasn't expired, and nothing changed for `cart.abandoned_after_minutes`
(default 60). Each cart is flagged once: `abandoned_at` is set atomically, and
`ap.ecommerce.cart.abandoned` and `CartAbandoned` fire. `CartAbandoned` is
also delivered as the `cart.abandoned` webhook. Any later change to the cart
clears the flag.

## Example: REST

Every write needs an `Idempotency-Key` header (see
[api.md](api.md#idempotency)). Paths are relative to `/api/ecommerce/v1`.

```http
POST /carts
Idempotency-Key: 6b1f…
{ "currency": "USD" }
→ 201 { "data": { "token": "Qm3x…40 chars", "currency": "USD", … } }

POST /carts/Qm3x…/items
{ "product_id": 4, "quantity": 2 }

POST /checkout/Qm3x…/start
→ { "data": { … }, "checkout": { "state": "addressing", "adjustments": [], … } }

POST /checkout/Qm3x…/address
{
  "email": "sam@example.com",
  "shipping_address": {
    "first_name": "Sam", "last_name": "Lee",
    "address1": "1 Main St", "city": "Portland",
    "region_code": "OR", "postal_code": "97201", "country_code": "US"
  }
}
→ "checkout": { "state": "shipping_selection", "shipping_rates": [ { "id": "3:flat_rate", "label": "Standard", "amount": 500, … } ] }

POST /checkout/Qm3x…/shipping-method
{ "rate_id": "3:flat_rate" }

POST /checkout/Qm3x…/payment-gateway
{ "gateway": "stripe" }

POST /checkout/Qm3x…/session
{ "return_url": "https://shop.test/checkout/return" }
→ "checkout": { "state": "payment_pending", "payment": { "reference": "pi_…", "client": { "driver": "stripe-payment-element", "client_secret": "…", … } } }
```

The browser now confirms the payment with the provider (Stripe.js
`confirmPayment()`), using `client`. Then:

```http
POST /checkout/Qm3x…/finalize
{ "payment_reference": "pi_…" }
→ 201 { "data": { "order_number": "…", … }, "checkout": { "status": "captured", "complete": true, "step_up_token": null } }
```

On `challenged`, complete the step-up with `step_up_token` and send the same
finalize request again with a new idempotency key. A shipping rate `id` is
built from the shipping method id, the method key, and the carrier service, so
always send back an `id` from `shipping_rates` rather than building one.

## Example: PHP

An in-process storefront calls the same services:

```php
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\CurrentCart;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use ArtisanPackUI\Ecommerce\Support\GuestCartCookie;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;

$carts    = app( StorefrontCartService::class );
$checkout = app( CheckoutService::class );

$cart = app( CurrentCart::class )->resolve( GuestCartCookie::tokenFrom( $request ), $request->user(), create: true );
GuestCartCookie::queue( $cart );

$carts->addItem( $cart, productId: 4, variantId: null, quantity: 2 );

$start = $checkout->start( $cart );          // $start->adjustments: lines reduced for stock

$checkout->setEmail( $cart, 'sam@example.com' );
$checkout->setAddress( $cart, Address::fromArray( [
    'address1'     => '1 Main St',
    'city'         => 'Portland',
    'region_code'  => 'OR',
    'postal_code'  => '97201',
    'country_code' => 'US',
] ) );

$rate = $checkout->shippingRates( $cart )->first();
$checkout->setShippingMethod( $cart, $rate->id() );
$checkout->setPaymentGateway( $cart, 'stripe' );

$session = $checkout->createPaymentSession( $cart, [ 'return_url' => route( 'checkout.return' ) ] );
// Render the payment step from ClientPaymentConfig::for( $gateway, $cart, $session ).

// After the browser confirms:
$result = $checkout->finalize( $cart, $session->reference, [
    'ip_address' => $request->ip(),
    'user_agent' => $request->userAgent(),
] );

if ( $result->isComplete() ) {
    return redirect()->route( 'order.confirmation', $result->order );
}

if ( 'challenged' === $result->status() ) {
    // Complete the step-up with $result->stepUpToken(), then call finalize() again.
}
```
