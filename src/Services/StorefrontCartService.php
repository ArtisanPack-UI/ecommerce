<?php

/**
 * StorefrontCartService.
 *
 * The shopper-facing cart operations behind the REST cart endpoints
 * (engine spec §9.2), the matching GraphQL mutations (§10.3), and the
 * in-process storefronts. Every surface calls this class, so they share one
 * set of rules:
 *
 * - only storefront-visible products can be added, a variant must belong to
 *   its product, and each line's options go through its product type's
 *   `validateCartOptions()` (a variable product needs a variant, a grouped
 *   product isn't sold on its own, unknown options are dropped);
 * - the unit price is always resolved server-side through the product
 *   type's `priceLine()` in the cart's currency — never taken from the
 *   client — and every line is re-priced on every change;
 * - a line can't ask for more than is in stock (bundles check their
 *   members; the cart's own checkout reservations don't count against it),
 *   holds at most {@see self::MAX_LINE_QUANTITY} units, and a cart holds at
 *   most `cart.max_lines` distinct lines;
 * - every change runs under a row lock on the cart, together with
 *   {@see self::refreshTotals()}: subtotal, promotions (including free
 *   items and free shipping), the chosen shipping rate, and tax once a
 *   destination is known;
 * - carts that became orders or expired can't be changed.
 *
 * Expected failures throw {@see CartOperationException}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Checkout\CheckoutState;
use ArtisanPackUI\Ecommerce\Contracts\ShippingRateProvider;
use ArtisanPackUI\Ecommerce\Events\CartUpdated;
use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Inventory\StockLevels;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Shipping\ZoneShippingRateProvider;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\PromotionResult;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingRate;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Money\Currency;
use Money\Money;
use RuntimeException;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class StorefrontCartService
{
    /**
     * Cart meta key holding the applied coupon code.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COUPON_META_KEY = 'coupon_code';

    /**
     * `carts.meta` key listing the ids of lines that can no longer be sold
     * (see {@see self::unsellableItems()}), so storefronts can flag them and
     * checkouts can refuse before converting the cart.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const UNSELLABLE_META_KEY = 'unsellable_item_ids';

    /**
     * `carts.meta` key holding the selected shipping rate
     * ({@see ShippingRate::toArray()}), set by {@see self::selectShippingMethod()}.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SHIPPING_RATE_META_KEY = 'shipping_rate';

    /**
     * `carts.meta` flag set when a change to the cart dropped the selected
     * shipping rate (it is no longer offered, or its price changed).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SHIPPING_INVALIDATED_META_KEY = 'shipping_rate_invalidated';

    /**
     * `carts.meta` key listing the promotions that made shipping free.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const FREE_SHIPPING_META_KEY = 'free_shipping';

    /**
     * `carts.meta` key holding the tax breakdown and whether it is an
     * estimate (`estimated: true` until a destination is known).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const TAX_META_KEY = 'tax';

    /**
     * Most units one cart line can hold.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_LINE_QUANTITY = 10_000;

    /**
     * Default for `cart.max_lines`.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_LINES = 100;

    /**
     * @since 1.0.0
     *
     * @param  CartService           $carts       Cart persistence + hooks.
     * @param  ProductPriceResolver  $prices      Server-side price lookup.
     * @param  PromotionEngine       $promotions  Discount evaluation.
     * @param  TaxService            $taxes       Tax calculation.
     * @param  StockLevels           $stock       Read-only stock levels.
     * @param  StoreCurrencies       $currencies  Enabled currencies.
     */
    public function __construct(
        protected CartService $carts,
        protected ProductPriceResolver $prices,
        protected PromotionEngine $promotions,
        protected TaxService $taxes,
        protected StockLevels $stock,
        protected StoreCurrencies $currencies,
    ) {
    }

    /**
     * Creates a cart.
     *
     * @since 1.0.0
     *
     * @param  string|null    $currency  ISO 4217 code (defaults to the base currency); must be enabled.
     * @param  string|null    $email     Shopper email.
     * @param  Customer|null  $customer  Signed-in shopper's customer record.
     * @param  string|null    $locale    Shopper's locale (defaults to the app locale).
     *
     * @throws CartOperationException When the currency isn't one the store sells in.
     *
     * @return Cart
     */
    public function create( ?string $currency = null, ?string $email = null, ?Customer $customer = null, ?string $locale = null ): Cart
    {
        if ( null !== $currency && ! $this->currencies->isEnabled( $currency ) ) {
            throw new CartOperationException( 'currency', 'currency-unavailable', __( 'The store does not sell in :currency.', [ 'currency' => strtoupper( $currency ) ] ) );
        }

        $attributes = array_filter( [
            'currency'    => null === $currency ? null : strtoupper( $currency ),
            'email'       => $email ?? $customer?->email,
            'customer_id' => $customer?->getKey(),
            'locale'      => $locale ?? app()->getLocale(),
        ], static fn ( mixed $value ): bool => null !== $value );

        return $this->carts->create( $attributes );
    }

    /**
     * Adds (or increments) a line, priced server-side.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart       Cart.
     * @param  int                   $productId  Product id.
     * @param  int|null              $variantId  Variant id.
     * @param  int                   $quantity   Quantity (≥ 1).
     * @param  array<string, mixed>  $options    Line options.
     *
     * @throws CartOperationException When the product/variant is unavailable, unpriced, out of stock, or the options are invalid.
     *
     * @return CartItem
     */
    public function addItem( Cart $cart, int $productId, ?int $variantId, int $quantity, array $options = [] ): CartItem
    {
        [ $product, $variant ] = $this->sellable( $productId, $variantId );
        $options               = $this->validOptions( $product, $variant, $options );

        return $this->mutate( $cart, function ( Cart $locked ) use ( $product, $variant, $quantity, $options ): CartItem {
            $hash     = CartItem::hashOptions( $options );
            $existing = $locked->items()
                ->where( 'product_id', $product->id )
                ->where( 'product_variant_id', $variant?->id )
                ->where( 'options_hash', $hash )
                ->first();

            if ( null === $existing && $this->paidLineCount( $locked ) >= $this->maxLines() ) {
                throw new CartOperationException( 'items', 'line-limit', __( 'A cart can hold at most :max different items.', [ 'max' => $this->maxLines() ] ) );
            }

            $total = (int) ( $existing?->quantity ?? 0 ) + $quantity;

            if ( $total > self::MAX_LINE_QUANTITY ) {
                throw new CartOperationException( 'quantity', 'quantity-limit', __( 'A cart line can hold at most :max units.', [ 'max' => self::MAX_LINE_QUANTITY ] ) );
            }

            $this->assertInStock( $locked, $product, $variant, $total );

            $price = $this->priceFor( $locked, $product, $variant, $options );

            try {
                $item = $this->carts->addItem( $locked, [
                    'product_id'          => $product->id,
                    'product_variant_id'  => $variant?->id,
                    'quantity'            => $quantity,
                    'unit_price_amount'   => (int) $price->getAmount(),
                    'unit_price_currency' => $price->getCurrency()->getCode(),
                    'options'             => $options,
                ] );
            } catch ( InvalidArgumentException ) {
                $item = null;
            }

            if ( null === $item ) {
                throw new CartOperationException( 'product_id', 'line-rejected', __( 'That item could not be added to the cart.' ) );
            }

            // A merged line keeps its old unit price in CartService; re-price
            // it so a quantity bump can't lock in an expired sale price.
            return $this->reprice( $locked, $item, $price );
        } );
    }

    /**
     * Sets a line's quantity (0 removes it).
     *
     * @since 1.0.0
     *
     * @param  Cart      $cart      Cart.
     * @param  CartItem  $item      Line (must belong to the cart).
     * @param  int       $quantity  New quantity.
     *
     * @throws CartOperationException When the line is not in the cart, came with a promotion, or isn't in stock.
     *
     * @return CartItem|null The updated line, or null when it was removed.
     */
    public function updateItem( Cart $cart, CartItem $item, int $quantity ): ?CartItem
    {
        $this->assertOwnsItem( $cart, $item );
        $this->assertNotFreeItem( $item );

        if ( $quantity > self::MAX_LINE_QUANTITY ) {
            throw new CartOperationException( 'quantity', 'quantity-limit', __( 'A cart line can hold at most :max units.', [ 'max' => self::MAX_LINE_QUANTITY ] ) );
        }

        return $this->mutate( $cart, function ( Cart $locked ) use ( $item, $quantity ): ?CartItem {
            if ( $quantity <= 0 ) {
                $this->carts->removeItem( $locked, $item );

                return null;
            }

            [ $product, $variant ] = $this->sellable( (int) $item->product_id, null === $item->product_variant_id ? null : (int) $item->product_variant_id );

            $this->assertInStock( $locked, $product, $variant, $quantity );

            $price   = $this->priceFor( $locked, $product, $variant, (array) ( $item->options ?? [] ) );
            $updated = $this->carts->updateItemQuantity( $locked, $item, $quantity );

            return null === $updated ? null : $this->reprice( $locked, $updated, $price );
        } );
    }

    /**
     * Removes a line.
     *
     * @since 1.0.0
     *
     * @param  Cart      $cart  Cart.
     * @param  CartItem  $item  Line (must belong to the cart).
     *
     * @throws CartOperationException When the line is not in the cart or came with a promotion.
     *
     * @return void
     */
    public function removeItem( Cart $cart, CartItem $item ): void
    {
        $this->assertOwnsItem( $cart, $item );
        $this->assertNotFreeItem( $item );

        $this->mutate( $cart, fn ( Cart $locked ) => $this->carts->removeItem( $locked, $item ) );
    }

    /**
     * Applies a coupon code, replacing any previously applied code. The
     * lines are re-priced first, so the coupon is checked against current
     * prices.
     *
     * @since 1.0.0
     *
     * @param  Cart    $cart  Cart.
     * @param  string  $code  Customer-entered code.
     *
     * @throws CartOperationException When the coupon does not apply to this cart.
     *
     * @return Cart
     */
    public function applyCoupon( Cart $cart, string $code ): Cart
    {
        return $this->mutate( $cart, function ( Cart $locked ) use ( $code ): Cart {
            $this->repriceLines( $locked );

            $result = $this->evaluatePromotions( $locked, $code );

            if ( ! $result->couponApplied() ) {
                $status = $this->publicCouponStatus( $result->couponStatus ?? PromotionResult::COUPON_INVALID );

                throw new CartOperationException( 'code', 'coupon-' . $status, $this->couponMessage( $status ) );
            }

            $meta                          = (array) ( $locked->meta ?? [] );
            $meta[ self::COUPON_META_KEY ] = Coupon::normalize( $code );
            $locked->meta                  = $meta;

            return $locked;
        } );
    }

    /**
     * Removes the applied coupon when it matches `$code`.
     *
     * @since 1.0.0
     *
     * @param  Cart    $cart  Cart.
     * @param  string  $code  Code to remove.
     *
     * @throws CartOperationException When that code is not applied.
     *
     * @return Cart
     */
    public function removeCoupon( Cart $cart, string $code ): Cart
    {
        return $this->mutate( $cart, function ( Cart $locked ) use ( $code ): Cart {
            $meta = (array) ( $locked->meta ?? [] );

            if ( ( $meta[ self::COUPON_META_KEY ] ?? null ) !== Coupon::normalize( $code ) ) {
                throw new CartOperationException( 'code', 'coupon-not-applied', __( 'That coupon is not applied to this cart.' ) );
            }

            unset( $meta[ self::COUPON_META_KEY ] );
            $locked->meta = $meta;

            return $locked;
        } );
    }

    /**
     * Empties the cart (see {@see CartService::clear()}, which fires
     * `ap.ecommerce.cart.cleared`). The selected shipping rate goes with
     * the lines; an applied coupon stays until the next totals refresh
     * finds it no longer applies.
     *
     * Only open carts can be cleared here. A checkout emptying a cart it
     * just converted (or a job dropping an expired one) calls
     * {@see CartService::clear()} directly with `converted` / `expired`.
     *
     * @since 1.0.0
     *
     * @param  Cart    $cart    Cart.
     * @param  string  $reason  Why the cart was cleared (defaults to `cleared`).
     *
     * @throws CartOperationException When the cart is closed.
     *
     * @return Cart
     */
    public function clear( Cart $cart, string $reason = 'cleared' ): Cart
    {
        return $this->mutate( $cart, function ( Cart $locked ) use ( $reason ): Cart {
            $cleared = $this->carts->clear( $locked, $reason );

            // The totals refresh that follows drops the shipping selection,
            // since the cart is now empty.
            $locked->setRawAttributes( $cleared->getAttributes(), true );

            return $locked;
        } );
    }

    /**
     * Sets the cart's email and addresses (the cart-level details a
     * checkout collects). An address that changes after a shipping rate was
     * chosen sends the rate through the same re-quote as a line change.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart.
     * @param  array<string, mixed>  $details  Any of `email`, `shipping_address`, `billing_address` (arrays, or null to clear).
     *
     * @throws CartOperationException When an address is invalid or the cart is closed.
     *
     * @return Cart
     */
    public function updateDetails( Cart $cart, array $details ): Cart
    {
        return $this->mutate( $cart, function ( Cart $locked ) use ( $details ): Cart {
            if ( array_key_exists( 'email', $details ) ) {
                $email = null === $details['email'] ? null : mb_strtolower( trim( (string) $details['email'] ) );

                if ( null !== $email && false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
                    throw new CartOperationException( 'email', 'email-invalid', __( 'Enter a valid email address.' ) );
                }

                $locked->email = $email;
            }

            foreach ( [ 'shipping_address', 'billing_address' ] as $field ) {
                if ( array_key_exists( $field, $details ) ) {
                    $locked->{$field} = null === $details[ $field ] ? null : self::normalizeAddress( (array) $details[ $field ], $field );
                }
            }

            return $locked;
        } );
    }

    /**
     * Gives a guest cart to `$customer` (a shopper who signed in): the cart
     * takes the customer's id and, when it has none, their email. The token
     * is rotated, so a copy of the guest token kept elsewhere no longer
     * opens a cart that now belongs to an account — callers hand the new
     * token back to the shopper.
     *
     * @since 1.0.0
     *
     * @param  Cart      $cart      Cart.
     * @param  Customer  $customer  Customer.
     *
     * @throws CartOperationException When the cart is closed or belongs to another customer.
     *
     * @return Cart The cart, with its new token.
     */
    public function attachCustomer( Cart $cart, Customer $customer ): Cart
    {
        $attached = $this->mutate( $cart, function ( Cart $locked ) use ( $customer ): bool {
            if ( null !== $locked->customer_id && (int) $locked->customer_id !== (int) $customer->id ) {
                throw new CartOperationException( 'cart', 'cart-owned', __( 'This cart belongs to another account.' ) );
            }

            if ( (int) $locked->customer_id === (int) $customer->id ) {
                return false;
            }

            $locked->customer_id = $customer->id;
            $locked->email ??= $customer->email;

            return true;
        } );

        if ( ! $attached ) {
            return $cart;
        }

        $rotated = $this->carts->rotateToken( $cart );
        $cart->setRawAttributes( $rotated->getAttributes(), true );

        Event::dispatch( new CartUpdated( $cart, [ 'action' => 'customer_attached', 'customer_id' => $customer->id ] ) );

        return $cart;
    }

    /**
     * Re-prices every line and recomputes the totals under the cart's lock,
     * without another change — for callers that changed lines directly
     * (a merge). Lines in another currency that have no price in the cart's
     * currency are removed.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @throws CartOperationException When the cart is closed.
     *
     * @return Cart
     */
    public function recalculate( Cart $cart ): Cart
    {
        $this->mutate( $cart, static fn ( Cart $locked ): Cart => $locked );

        return $cart;
    }

    /**
     * The promotion evaluation behind the cart's current discount (its paid
     * lines and applied coupon), for recording usage at placement.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return PromotionResult
     */
    public function promotionResult( Cart $cart ): PromotionResult
    {
        $coupon = ( (array) ( $cart->meta ?? [] ) )[ self::COUPON_META_KEY ] ?? null;

        return $this->evaluatePromotions( $cart, is_string( $coupon ) ? $coupon : null );
    }

    /**
     * Whether any line needs shipping (its product type requires fulfillment).
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return bool
     */
    public function requiresShipping( Cart $cart ): bool
    {
        return $cart->loadMissing( 'items.product' )->items->contains(
            static fn ( CartItem $item ): bool => null !== $item->product && ! $item->product->typeIsMissing() && $item->product->productType()->requiresFulfillment(),
        );
    }

    /**
     * Re-prices the cart in another enabled currency: every line is priced
     * again in `$currency`, promotions are re-applied, and the shipping rate
     * and any payment session are dropped (their amounts were in the old
     * currency). Fires {@see CartUpdated}.
     *
     * @since 1.0.0
     *
     * @param  Cart    $cart      Cart.
     * @param  string  $currency  ISO 4217 code of an enabled currency.
     *
     * @throws CartOperationException When the currency isn't enabled or a line has no price in it.
     *
     * @return Cart
     */
    public function changeCurrency( Cart $cart, string $currency ): Cart
    {
        $currency = strtoupper( trim( $currency ) );

        if ( ! $this->currencies->isEnabled( $currency ) ) {
            throw new CartOperationException( 'currency', 'currency-unavailable', __( 'The store does not sell in :currency.', [ 'currency' => $currency ] ) );
        }

        $changed = $this->mutate( $cart, function ( Cart $locked ) use ( $currency ): bool {
            if ( $currency === strtoupper( (string) $locked->currency ) ) {
                return false;
            }

            foreach ( [ 'currency', 'subtotal_currency', 'discount_currency', 'tax_currency', 'shipping_currency', 'total_currency' ] as $column ) {
                $locked->{$column} = $currency;
            }

            foreach ( $locked->items()->with( [ 'product', 'variant' ] )->get() as $item ) {
                $unit = 0;

                if ( ! $item->isFreeItem() ) {
                    if ( null === $item->product ) {
                        throw new CartOperationException( 'currency', 'price-unavailable', __( 'Some items in your cart are no longer available.' ) );
                    }

                    $unit = (int) $this->linePrice( $locked, $item, $this->priceFor( $locked, $item->product, $item->variant, (array) ( $item->options ?? [] ) ) )->getAmount();
                }

                $item->forceFill( [
                    'unit_price_amount'      => $unit,
                    'unit_price_currency'    => $currency,
                    'line_subtotal_amount'   => $unit * (int) $item->quantity,
                    'line_subtotal_currency' => $currency,
                    'line_total_amount'      => $unit * (int) $item->quantity,
                    'line_total_currency'    => $currency,
                ] )->save();
            }

            $meta = (array) ( $locked->meta ?? [] );
            unset( $meta[ self::SHIPPING_RATE_META_KEY ], $meta[ self::FREE_SHIPPING_META_KEY ] );

            $locked->meta                = $meta;
            $locked->shipping_amount     = 0;
            $locked->payment_reference   = null;
            $locked->payment_gateway_key = null;

            if ( CheckoutState::isAfter( (string) $locked->checkout_state, CheckoutState::ADDRESSING ) && CheckoutState::rank( (string) $locked->checkout_state ) < CheckoutState::rank( CheckoutState::COMPLETED ) ) {
                $locked->checkout_state = CheckoutState::SHIPPING_SELECTION;
            }

            return true;
        } );

        if ( $changed ) {
            Event::dispatch( new CartUpdated( $cart, [ 'action' => 'currency_changed', 'currency' => $currency ] ) );
        }

        return $cart;
    }

    /**
     * Shipping rates on offer for the cart and `$destination` (a quote; the
     * cart isn't changed).
     *
     * @since 1.0.0
     *
     * @param  Cart                       $cart         Cart.
     * @param  Address                    $destination  Destination.
     * @param  ShippingRateProvider|null  $provider     Rate source (defaults to the zone-based provider).
     *
     * @return \Illuminate\Support\Collection<int, ShippingRate>
     */
    public function quoteShipping( Cart $cart, Address $destination, ?ShippingRateProvider $provider = null ): \Illuminate\Support\Collection
    {
        $provider ??= app( ZoneShippingRateProvider::class );
        $currency   = strtoupper( (string) $cart->currency );

        return collect( $provider->getRatesForCart( $cart->loadMissing( 'items' ), $destination )->all() )
            ->filter( static fn ( ShippingRate $rate ): bool => $rate->amount->getCurrency()->getCode() === $currency && ! $rate->amount->isNegative() )
            ->values();
    }

    /**
     * Selects one of the shipping rates quoted for the cart.
     *
     * The rate is re-quoted server-side for `$destination` and matched on
     * {@see ShippingRate::id()} — the identifier a client echoes back — so
     * a client can't submit its own amount. The rate's amount becomes the
     * cart's `shipping_amount` and the rate is kept under
     * {@see self::SHIPPING_RATE_META_KEY}, with the country, region, and
     * postal code it was quoted for under `destination` (no street or name,
     * since cart meta is returned by the cart API). Fires
     * `ap.ecommerce.shipping.methodSelected` (action) once the change is
     * saved.
     *
     * When the lines or the address change afterwards, the totals refresh
     * quotes again: the rate stays only if it is still offered at the same
     * price; otherwise it is dropped and the checkout steps back to shipping
     * selection.
     *
     * @since 1.0.0
     *
     * @param  Cart                       $cart         Cart.
     * @param  Address                    $destination  Destination the rates are quoted for.
     * @param  string                     $rateId       {@see ShippingRate::id()} of the chosen rate.
     * @param  ShippingRateProvider|null  $provider     Rate source (defaults to the zone-based provider).
     *
     * @throws CartOperationException When the cart is closed or the rate is not offered for it.
     *
     * @return Cart
     */
    public function selectShippingMethod( Cart $cart, Address $destination, string $rateId, ?ShippingRateProvider $provider = null ): Cart
    {
        $rate = $this->mutate( $cart, function ( Cart $locked ) use ( $destination, $rateId, $provider ): ShippingRate {
            $rate = $this->quoteShipping( $locked, $destination, $provider )
                ->first( static fn ( ShippingRate $rate ): bool => $rate->id() === $rateId );

            if ( null === $rate ) {
                throw new CartOperationException( 'shipping_rate', 'shipping-rate-unavailable', __( 'That shipping option is not available for this cart.' ) );
            }

            $meta                                 = (array) ( $locked->meta ?? [] );
            $meta[ self::SHIPPING_RATE_META_KEY ] = $rate->toArray() + [
                'destination' => [
                    'country_code' => strtoupper( $destination->countryCode ),
                    'region_code'  => $destination->regionCode,
                    'postal_code'  => $destination->postalCode,
                ],
            ];
            unset( $meta[ self::SHIPPING_INVALIDATED_META_KEY ] );

            $locked->meta            = $meta;
            $locked->shipping_amount = (int) $rate->amount->getAmount();

            return $rate;
        } );

        // The refresh above recorded the quote's fingerprint.
        $method = null === $rate->shippingMethodId ? null : ShippingMethod::query()->find( $rate->shippingMethodId );

        doAction( 'ap.ecommerce.shipping.methodSelected', $rate, $cart, $method );

        return $cart;
    }

    /**
     * Recomputes the cart's totals from its lines:
     *
     * 1. subtotal (through `ap.ecommerce.pricing.subtotal`);
     * 2. promotions — automatic ones plus the applied coupon (dropped when
     *    it stopped applying); free-item lines are added, resized, or
     *    removed to match; each line's share of the discount is stored;
     * 3. shipping — free when a promotion grants it; a selected rate is
     *    re-quoted when the lines or destination changed, and dropped if it
     *    is no longer offered at the same price;
     * 4. tax — through the active tax provider once a destination is known
     *    (the shipping address, else the billing address, else the
     *    destination the shipping rate was quoted for), with each line's
     *    own discount; until then it is zero and flagged `estimated`;
     * 5. total = subtotal − discount + shipping + tax (tax is already in the
     *    prices of a tax-inclusive store), through `ap.ecommerce.pricing.total`.
     *
     * A filter return that isn't a non-negative {@see Money} in the cart's
     * currency is ignored.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return Cart
     */
    public function refreshTotals( Cart $cart ): Cart
    {
        $cart->load( 'items.product', 'items.variant' );

        $currency = new Currency( strtoupper( (string) $cart->currency ) );
        $meta     = (array) ( $cart->meta ?? [] );

        $unsellable = $this->unsellableItems( $cart )->modelKeys();

        if ( [] === $unsellable ) {
            unset( $meta[ self::UNSELLABLE_META_KEY ] );
        } else {
            $meta[ self::UNSELLABLE_META_KEY ] = $unsellable;
        }

        // An empty cart is never quoted shipping, so a selection made before
        // the last line was removed no longer applies.
        if ( $cart->items->isEmpty() ) {
            unset( $meta[ self::SHIPPING_RATE_META_KEY ], $meta[ self::FREE_SHIPPING_META_KEY ] );
            $cart->shipping_amount = 0;
        }

        $cart->meta = $meta;

        // Promotions, with free-item lines kept in step.
        $coupon = $meta[ self::COUPON_META_KEY ] ?? null;
        $result = $this->evaluatePromotions( $cart, is_string( $coupon ) ? $coupon : null );

        if ( is_string( $coupon ) && ! $result->couponApplied() ) {
            unset( $meta[ self::COUPON_META_KEY ] );
        }

        if ( $this->syncFreeItems( $cart, $result ) ) {
            $cart->load( 'items.product', 'items.variant' );
        }

        $subtotal              = new Money( (int) $cart->items->sum( 'line_subtotal_amount' ), $currency );
        $cart->subtotal_amount = (int) $this->validAmount( applyFilters( 'ap.ecommerce.pricing.subtotal', $subtotal, $cart ), $subtotal )->getAmount();

        $lineDiscounts = array_map( static fn ( Money $amount ): int => (int) $amount->getAmount(), $result->ledger->lineDiscounts() );

        foreach ( $cart->items as $item ) {
            $discount = (int) ( $lineDiscounts[ $item->id ] ?? 0 );

            if ( $discount !== (int) $item->discount_amount ) {
                $item->forceFill( [ 'discount_amount' => $discount ] )->save();
            }
        }

        $cart->discount_amount = (int) $result->discountTotal()->getAmount();

        // Shipping.
        $freeShipping = $result->hasFreeShipping() && $cart->items->isNotEmpty();

        if ( $freeShipping ) {
            $meta[ self::FREE_SHIPPING_META_KEY ] = array_values( array_map(
                static fn ( $promotion ): int => (int) $promotion->id,
                array_filter( $result->applied, static fn ( $promotion ): bool => $result->ledger->grantedFreeShipping( (int) $promotion->id ) ),
            ) );
        } else {
            unset( $meta[ self::FREE_SHIPPING_META_KEY ] );
        }

        $cart->meta = $meta;
        $meta       = $this->revalidateShipping( $cart, $meta );

        $cart->shipping_amount = $freeShipping ? 0 : (int) ( $meta[ self::SHIPPING_RATE_META_KEY ]['amount'] ?? ( isset( $meta[ self::SHIPPING_RATE_META_KEY ] ) ? $cart->shipping_amount : 0 ) );
        $cart->meta            = $meta;

        // Tax.
        $meta = $this->applyTax( $cart, $meta, $lineDiscounts );

        $inclusive = (bool) ( $meta[ self::TAX_META_KEY ]['prices_include_tax'] ?? false );
        $total     = new Money( max(
            0,
            $cart->subtotal_amount - $cart->discount_amount + (int) $cart->shipping_amount + ( $inclusive ? 0 : (int) $cart->tax_amount ),
        ), $currency );
        $breakdown = [
            'subtotal' => new Money( (int) $cart->subtotal_amount, $currency ),
            'discount' => new Money( (int) $cart->discount_amount, $currency ),
            'tax'      => new Money( (int) $cart->tax_amount, $currency ),
            'shipping' => new Money( (int) $cart->shipping_amount, $currency ),
        ];

        $cart->meta         = $meta;
        $cart->total_amount = (int) $this->validAmount( applyFilters( 'ap.ecommerce.pricing.total', $total, $cart, $breakdown ), $total )->getAmount();
        $cart->save();

        return $cart;
    }

    /**
     * Lines that can no longer be sold: their product was deleted, or its
     * product type's satellite was uninstalled after the line was added.
     * Adding such a product is already refused; lines added before the
     * satellite went away stay in the cart (as lines of unpublished
     * products do) but are listed here — and under
     * {@see self::UNSELLABLE_META_KEY} in `carts.meta` after every totals
     * refresh — so a checkout can refuse to convert the cart.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return Collection<int, CartItem>
     */
    public function unsellableItems( Cart $cart ): Collection
    {
        return $cart->loadMissing( 'items.product' )->items
            ->filter( static fn ( CartItem $item ): bool => null === $item->product || $item->product->typeIsMissing() )
            ->values();
    }

    /**
     * The validated, normalized address array the cart stores, or a
     * shopper-facing error.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $address  Address fields.
     * @param  string                $field    Field name, for errors.
     *
     * @throws CartOperationException When required fields are missing or the country is invalid.
     *
     * @return array<string, string|null>
     */
    public static function normalizeAddress( array $address, string $field = 'address' ): array
    {
        $country = strtoupper( trim( (string) ( $address['country_code'] ?? '' ) ) );

        if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
            throw new CartOperationException( $field . '.country_code', 'country-invalid', __( 'Choose a country.' ) );
        }

        $normalized = Address::fromArray( array_merge( $address, [ 'country_code' => $country ] ) )->toArray();

        foreach ( $normalized as $key => $value ) {
            $normalized[ $key ] = null === $value ? null : mb_substr( trim( (string) $value ), 0, 255 );
        }

        return $normalized;
    }

    /**
     * Most distinct lines a cart may hold (`cart.max_lines`).
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function maxLines(): int
    {
        $configured = (int) config( 'artisanpack.ecommerce.cart.max_lines', self::MAX_LINES );

        return $configured > 0 ? $configured : self::MAX_LINES;
    }

    /**
     * Rejects changes to a cart that became an order or expired.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @throws CartOperationException When the cart is closed.
     *
     * @return void
     */
    public function assertOpen( Cart $cart ): void
    {
        if ( null !== $cart->completed_order_id || ( null !== $cart->expires_at && $cart->expires_at->isPast() ) ) {
            throw new CartOperationException( 'cart', 'cart-closed', __( 'This cart can no longer be changed.' ) );
        }
    }

    /**
     * Runs a cart change under a row lock on the cart, then re-prices the
     * lines and recomputes the totals in the same transaction — so
     * concurrent changes to one cart serialize and the stored totals always
     * match the stored lines. The cart's expiry is pushed back, a flagged
     * abandonment is cleared, and the caller's `$cart` instance is synced
     * with the result.
     *
     * @since 1.0.0
     *
     * @template T
     *
     * @param  Cart                $cart    Cart.
     * @param  Closure(Cart): T    $change  Change, given the locked cart.
     *
     * @throws CartOperationException When the cart is closed or the change is rejected.
     *
     * @return T
     */
    protected function mutate( Cart $cart, Closure $change ): mixed
    {
        return DB::transaction( function () use ( $cart, $change ): mixed {
            $locked = Cart::query()->lockForUpdate()->findOrFail( $cart->getKey() );

            $this->assertOpen( $locked );

            $previousTotal = (int) $locked->total_amount;
            $result        = $change( $locked );

            $this->repriceLines( $locked );
            $this->refreshTotals( $locked );

            // A payment session was made for the old total; checkout must
            // make a new one before taking payment.
            if ( null !== $locked->payment_reference && (int) $locked->total_amount !== $previousTotal && CheckoutState::PAYMENT_PENDING === $locked->checkout_state ) {
                $locked->checkout_state = CheckoutState::PAYMENT_SELECTION;
            }

            $locked->expires_at   = $this->carts->expiryFrom( Carbon::now() );
            $locked->abandoned_at = null;
            $locked->save();

            $cart->setRawAttributes( $locked->getAttributes(), true );
            $cart->setRelation( 'items', $locked->items );

            return $result;
        } );
    }

    /**
     * Evaluates promotions against the cart's paid lines (free-item lines
     * are left out, so a promotion's own gifts never feed back into it).
     *
     * @since 1.0.0
     *
     * @param  Cart         $cart    Cart.
     * @param  string|null  $coupon  Coupon code.
     *
     * @return PromotionResult
     */
    protected function evaluatePromotions( Cart $cart, ?string $coupon ): PromotionResult
    {
        $items = $cart->relationLoaded( 'items' ) ? $cart->items : $cart->items()->get();

        $cart->setRelation( 'items', $items->reject( static fn ( CartItem $item ): bool => $item->isFreeItem() )->values() );

        try {
            return $this->promotions->evaluate( $cart, $coupon );
        } finally {
            $cart->setRelation( 'items', $items );
        }
    }

    /**
     * Adds, resizes, or removes the zero-priced lines promotions grant
     * (`meta.free_item`) to match `$result`. A free item that can't be sold
     * (unavailable product) is skipped, and one is capped at what is in
     * stock (left out when none is). Returns whether anything changed.
     *
     * @since 1.0.0
     *
     * @param  Cart             $cart    Locked cart.
     * @param  PromotionResult  $result  Promotion outcome.
     *
     * @return bool
     */
    protected function syncFreeItems( Cart $cart, PromotionResult $result ): bool
    {
        $currency = strtoupper( (string) $cart->currency );
        $existing = $cart->items->filter( static fn ( CartItem $item ): bool => $item->isFreeItem() )
            ->keyBy( static fn ( CartItem $item ): string => self::freeItemKey( $item->meta['promotion_id'] ?? null, (int) $item->product_id, $item->product_variant_id ) );
        $wanted   = [];
        $changed  = false;

        foreach ( $result->ledger->freeItems() as $free ) {
            $key = self::freeItemKey( $free['promotion_id'], (int) $free['product_id'], $free['variant_id'] );

            $wanted[ $key ] = ( $wanted[ $key ] ?? [ ...$free, 'quantity' => 0 ] );
            $wanted[ $key ]['quantity'] += (int) $free['quantity'];
        }

        foreach ( $wanted as $key => $free ) {
            $line    = $existing->get( $key );
            $product = Product::query()->storefrontVisible()->find( $free['product_id'] );
            $variant = null === $free['variant_id'] ? null : ProductVariant::query()->whereKey( $free['variant_id'] )->where( 'product_id', $free['product_id'] )->first();

            if ( null === $product || $product->typeIsMissing() || ( null !== $free['variant_id'] && null === $variant ) ) {
                continue;
            }

            // A gift is only given while it's in stock (the cart's own holds count as its own).
            $inStock  = $this->stock->sellable( $product, $variant, $cart );
            $quantity = min( self::MAX_LINE_QUANTITY, max( 1, (int) $free['quantity'] ), $inStock ?? PHP_INT_MAX );

            if ( $quantity < 1 ) {
                unset( $wanted[ $key ] );

                continue;
            }

            if ( null !== $line ) {
                if ( (int) $line->quantity !== $quantity ) {
                    $line->forceFill( [ 'quantity' => $quantity ] )->save();
                    $changed = true;
                }

                continue;
            }

            CartItem::query()->create( [
                'cart_id'                => $cart->id,
                'product_id'             => $product->id,
                'product_variant_id'     => $free['variant_id'],
                'quantity'               => $quantity,
                'unit_price_amount'      => 0,
                'unit_price_currency'    => $currency,
                'line_subtotal_amount'   => 0,
                'line_subtotal_currency' => $currency,
                'line_total_amount'      => 0,
                'line_total_currency'    => $currency,
                'options'                => [],
                'meta'                   => [ 'free_item' => true, 'promotion_id' => $free['promotion_id'] ],
                // Distinct from a paid line of the same product, so the two never merge.
                'options_hash'           => CartItem::hashOptions( [ '__free_item' => (int) ( $free['promotion_id'] ?? 0 ) ] ),
            ] );
            $changed = true;
        }

        foreach ( $existing as $key => $line ) {
            if ( ! isset( $wanted[ $key ] ) ) {
                $line->delete();
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Identity of a free-item line.
     *
     * @since 1.0.0
     *
     * @param  mixed     $promotionId  Granting promotion.
     * @param  int       $productId    Product.
     * @param  int|null  $variantId    Variant.
     *
     * @return string
     */
    protected static function freeItemKey( mixed $promotionId, int $productId, ?int $variantId ): string
    {
        return sprintf( '%s:%d:%s', null === $promotionId ? '-' : (string) $promotionId, $productId, null === $variantId ? '-' : (string) $variantId );
    }

    /**
     * Keeps the selected shipping rate honest after a change: when the
     * lines, discount, or destination differ from when it was quoted, the
     * cart is quoted again and the rate kept only if still offered at the
     * same amount. Otherwise it is dropped, the cart is flagged, and a
     * checkout past shipping selection steps back to it.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart  Locked cart (discount already set).
     * @param  array<string, mixed>  $meta  Cart meta.
     *
     * @return array<string, mixed>
     */
    protected function revalidateShipping( Cart $cart, array $meta ): array
    {
        $selected = $meta[ self::SHIPPING_RATE_META_KEY ] ?? null;

        if ( ! is_array( $selected ) ) {
            return $meta;
        }

        $destination = $this->shippingDestination( $cart, $selected );
        $fingerprint = $this->shippingFingerprint( $cart, $destination );

        if ( ( $selected['fingerprint'] ?? null ) === $fingerprint ) {
            return $meta;
        }

        $rate = null;

        if ( null !== $destination ) {
            try {
                $rate = $this->quoteShipping( $cart, $destination )->first( static fn ( ShippingRate $rate ): bool => $rate->id() === ( $selected['id'] ?? null ) );
            } catch ( Throwable $exception ) {
                Log::channel( 'ecommerce' )->warning( 'Could not re-quote the selected shipping rate.', [ 'cart_id' => $cart->id, 'error' => $exception->getMessage() ] );
            }
        }

        // Unchanged amount: the shopper still gets what they chose.
        if ( null !== $rate && (int) $rate->amount->getAmount() === (int) ( $selected['amount'] ?? -1 ) ) {
            $meta[ self::SHIPPING_RATE_META_KEY ]['fingerprint'] = $fingerprint;

            return $meta;
        }

        unset( $meta[ self::SHIPPING_RATE_META_KEY ] );
        $meta[ self::SHIPPING_INVALIDATED_META_KEY ] = true;
        $cart->shipping_amount                       = 0;

        if ( CheckoutState::isAfter( (string) $cart->checkout_state, CheckoutState::SHIPPING_SELECTION ) && CheckoutState::rank( (string) $cart->checkout_state ) < CheckoutState::rank( CheckoutState::COMPLETED ) ) {
            $cart->checkout_state = CheckoutState::SHIPPING_SELECTION;
        }

        return $meta;
    }

    /**
     * Where shipping is quoted to: the cart's shipping address, else the
     * destination stored with the selected rate.
     *
     * @since 1.0.0
     *
     * @param  Cart                       $cart      Cart.
     * @param  array<string, mixed>|null  $selected  Selected rate.
     *
     * @return Address|null
     */
    protected function shippingDestination( Cart $cart, ?array $selected ): ?Address
    {
        $address = $cart->shipping_address ?? $selected['destination'] ?? null;

        if ( ! is_array( $address ) || 2 !== strlen( (string) ( $address['country_code'] ?? '' ) ) ) {
            return null;
        }

        return Address::fromArray( $address );
    }

    /**
     * What a shipping quote depends on: the lines, the discount, the
     * currency, and the destination.
     *
     * @since 1.0.0
     *
     * @param  Cart          $cart         Cart.
     * @param  Address|null  $destination  Destination.
     *
     * @return string
     */
    protected function shippingFingerprint( Cart $cart, ?Address $destination ): string
    {
        $lines = $cart->items
            ->map( static fn ( CartItem $item ): string => sprintf( '%d:%s:%d', $item->product_id, (string) $item->product_variant_id, $item->quantity ) )
            ->sort()
            ->values()
            ->all();

        return hash( 'sha256', (string) json_encode( [
            $lines,
            (int) $cart->discount_amount,
            strtoupper( (string) $cart->currency ),
            null === $destination ? null : [ $destination->countryCode, $destination->regionCode, $destination->postalCode, $destination->city ],
        ] ) );
    }

    /**
     * Calculates tax into the cart when a destination is known; otherwise
     * zero, flagged as an estimate. A provider that fails leaves the cart
     * untaxed and flagged, rather than failing the cart change.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart           Locked cart.
     * @param  array<string, mixed>  $meta           Cart meta.
     * @param  array<int, int>       $lineDiscounts  Discount per line.
     *
     * @return array<string, mixed>
     */
    protected function applyTax( Cart $cart, array $meta, array $lineDiscounts ): array
    {
        $destination = $this->taxDestination( $cart, $meta );
        $inclusive   = (bool) config( 'artisanpack.ecommerce.tax.prices_include_tax', false );

        if ( null === $destination || $cart->items->isEmpty() ) {
            $this->zeroTax( $cart );
            $meta[ self::TAX_META_KEY ] = [ 'estimated' => true, 'prices_include_tax' => $inclusive, 'breakdown' => [] ];

            return $meta;
        }

        try {
            $result = $this->taxes->calculate( $cart, $destination, $lineDiscounts );
        } catch ( Throwable $exception ) {
            Log::channel( 'ecommerce' )->warning( 'Tax could not be calculated for a cart; showing it as an estimate.', [ 'cart_id' => $cart->id, 'error' => $exception->getMessage() ] );

            $this->zeroTax( $cart );
            $meta[ self::TAX_META_KEY ] = [ 'estimated' => true, 'prices_include_tax' => $inclusive, 'breakdown' => [], 'error' => true ];

            return $meta;
        }

        foreach ( $cart->items as $item ) {
            $tax = (int) ( isset( $result->perLine[ $item->id ] ) ? $result->perLine[ $item->id ]->getAmount() : 0 );

            if ( $tax !== (int) $item->tax_amount ) {
                $item->forceFill( [ 'tax_amount' => $tax ] )->save();
            }
        }

        $cart->tax_amount = (int) $result->total->getAmount();

        $summary                    = $result->toArray();
        $meta[ self::TAX_META_KEY ] = [
            'estimated'          => false,
            'prices_include_tax' => $result->pricesIncludeTax,
            'shipping'           => $summary['shipping'],
            'breakdown'          => $summary['breakdown'],
        ];

        return $meta;
    }

    /**
     * Clears the cart's and its lines' tax.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return void
     */
    protected function zeroTax( Cart $cart ): void
    {
        $cart->tax_amount = 0;

        foreach ( $cart->items as $item ) {
            if ( 0 !== (int) $item->tax_amount ) {
                $item->forceFill( [ 'tax_amount' => 0 ] )->save();
            }
        }
    }

    /**
     * The address tax is calculated for: shipping address, else billing
     * address, else the destination the shipping rate was quoted for.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart  Cart.
     * @param  array<string, mixed>  $meta  Cart meta.
     *
     * @return Address|null
     */
    protected function taxDestination( Cart $cart, array $meta ): ?Address
    {
        foreach ( [ $cart->shipping_address, $cart->billing_address, $meta[ self::SHIPPING_RATE_META_KEY ]['destination'] ?? null ] as $address ) {
            if ( is_array( $address ) && 2 === strlen( (string) ( $address['country_code'] ?? '' ) ) ) {
                return Address::fromArray( $address );
            }
        }

        return null;
    }

    /**
     * The storefront-visible product and (optional) variant, or a shopper-facing error.
     *
     * @since 1.0.0
     *
     * @param  int       $productId  Product id.
     * @param  int|null  $variantId  Variant id.
     *
     * @throws CartOperationException When either is unavailable.
     *
     * @return array{0: Product, 1: ProductVariant|null}
     */
    protected function sellable( int $productId, ?int $variantId ): array
    {
        $product = Product::query()->storefrontVisible()->find( $productId );

        if ( null === $product ) {
            throw new CartOperationException( 'product_id', 'product-unavailable', __( 'That product is not available.' ) );
        }

        // Its type's satellite is uninstalled: listed, but not sellable.
        if ( $product->typeIsMissing() ) {
            throw new CartOperationException( 'product_id', 'product-type-missing', __( 'That product is not available right now.' ) );
        }

        $variant = null;

        if ( null !== $variantId ) {
            $variant = ProductVariant::query()->whereKey( $variantId )->where( 'product_id', $product->id )->first();

            if ( null === $variant ) {
                throw new CartOperationException( 'product_variant_id', 'variant-unavailable', __( 'That variant is not available for this product.' ) );
            }
        }

        return [ $product, $variant ];
    }

    /**
     * The line options the product type accepts (variant aside, which the
     * line stores in its own column). Unknown options are dropped.
     *
     * @since 1.0.0
     *
     * @param  Product               $product  Product.
     * @param  ProductVariant|null   $variant  Variant.
     * @param  array<string, mixed>  $options  Submitted options.
     *
     * @throws CartOperationException When the type rejects them.
     *
     * @return array<string, mixed>
     */
    protected function validOptions( Product $product, ?ProductVariant $variant, array $options ): array
    {
        try {
            $validated = $product->productType()->validateCartOptions( $product, array_filter( [ 'variant_id' => $variant?->id ] ) + $options );
        } catch ( InvalidArgumentException ) {
            throw new CartOperationException( 'options', 'options-invalid', __( 'Choose the options this product needs.' ) );
        }

        unset( $validated['variant_id'] );

        return $validated;
    }

    /**
     * Refuses a quantity that exceeds what is in stock.
     *
     * @since 1.0.0
     *
     * @param  Cart                 $cart      Cart (its own reservations are added back).
     * @param  Product              $product   Product.
     * @param  ProductVariant|null  $variant   Variant.
     * @param  int                  $quantity  Total units the line would hold.
     *
     * @throws CartOperationException When there isn't enough.
     *
     * @return void
     */
    protected function assertInStock( Cart $cart, Product $product, ?ProductVariant $variant, int $quantity ): void
    {
        $available = $this->stock->sellable( $product, $variant, $cart );

        if ( null === $available || $quantity <= $available ) {
            return;
        }

        throw new CartOperationException( 'quantity', 'insufficient-stock', 0 === $available
            ? __( 'That item is out of stock.' )
            : trans_choice( 'Only :count is left in stock.|Only :count are left in stock.', $available, [ 'count' => $available ] ) );
    }

    /**
     * The current unit price of a line in the cart's currency, through its
     * product type.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart.
     * @param  Product               $product  Product.
     * @param  ProductVariant|null   $variant  Variant.
     * @param  array<string, mixed>  $options  Validated options.
     *
     * @throws CartOperationException When it has no price in that currency.
     *
     * @return Money
     */
    protected function priceFor( Cart $cart, Product $product, ?ProductVariant $variant, array $options = [] ): Money
    {
        try {
            return $product->productType()->priceLine( $product, array_filter( [ 'variant_id' => $variant?->id ] ) + $options, 1, (string) $cart->currency );
        } catch ( RuntimeException | InvalidArgumentException ) {
            throw new CartOperationException( 'product_id', 'price-unavailable', __( 'That product has no price in :currency.', [ 'currency' => $cart->currency ] ) );
        }
    }

    /**
     * Sets a line's unit price to its filtered current price (see
     * {@see self::linePrice()}) and recomputes its totals.
     *
     * @since 1.0.0
     *
     * @param  Cart      $cart   Cart the line belongs to.
     * @param  CartItem  $item   Line.
     * @param  Money     $price  Resolved unit price.
     *
     * @return CartItem
     */
    protected function reprice( Cart $cart, CartItem $item, Money $price ): CartItem
    {
        $item->unit_price_amount    = (int) $this->linePrice( $cart, $item, $price )->getAmount();
        $item->line_subtotal_amount = $item->unit_price_amount * (int) $item->quantity;
        $item->line_total_amount    = $item->line_subtotal_amount;
        $item->save();

        return $item;
    }

    /**
     * Runs a resolved unit price through `ap.ecommerce.pricing.itemPrice`
     * (filter). A return that isn't a non-negative {@see Money} in the
     * cart's currency is ignored and the resolved price is kept.
     *
     * @since 1.0.0
     *
     * @param  Cart      $cart   Cart.
     * @param  CartItem  $item   Line being priced.
     * @param  Money     $price  Unit price from the product type.
     *
     * @return Money
     */
    protected function linePrice( Cart $cart, CartItem $item, Money $price ): Money
    {
        return $this->validAmount( applyFilters( 'ap.ecommerce.pricing.itemPrice', $price, $item, $cart ), $price );
    }

    /**
     * `$candidate` when it is a non-negative {@see Money} in `$fallback`'s
     * currency, otherwise `$fallback` — the guard every pricing filter's
     * return value goes through.
     *
     * @since 1.0.0
     *
     * @param  mixed  $candidate  Filter return value.
     * @param  Money  $fallback   Value passed into the filter.
     *
     * @return Money
     */
    protected function validAmount( mixed $candidate, Money $fallback ): Money
    {
        if ( ! $candidate instanceof Money
            || ! $candidate->getCurrency()->equals( $fallback->getCurrency() )
            || $candidate->isNegative()
        ) {
            return $fallback;
        }

        return $candidate;
    }

    /**
     * Re-prices every paid line at its current price, so no line keeps a
     * price that has since changed (an ended sale, a new price row). Lines
     * whose product is no longer sellable keep their stored price; checkout
     * rejects them. Free-item lines stay at zero. A line priced in another
     * currency (carried over by a merge) is priced in the cart's currency,
     * or removed when it has no price there.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Locked cart.
     *
     * @return void
     */
    protected function repriceLines( Cart $cart ): void
    {
        $currency = strtoupper( (string) $cart->currency );

        foreach ( $cart->items()->with( [ 'product', 'variant' ] )->get() as $item ) {
            $foreign = strtoupper( (string) $item->unit_price_currency ) !== $currency;

            if ( $item->isFreeItem() || null === $item->product || $item->product->typeIsMissing() ) {
                if ( $foreign ) {
                    $item->delete();
                }

                continue;
            }

            try {
                $price = $item->product->productType()->priceLine( $item->product, array_filter( [ 'variant_id' => $item->product_variant_id ] ) + (array) ( $item->options ?? [] ), 1, $currency );
            } catch ( RuntimeException | InvalidArgumentException ) {
                if ( $foreign ) {
                    $item->delete();
                }

                continue;
            }

            $price = $this->linePrice( $cart, $item, $price );

            if ( $foreign || (int) $price->getAmount() !== (int) $item->unit_price_amount ) {
                $item->forceFill( [
                    'unit_price_amount'      => (int) $price->getAmount(),
                    'unit_price_currency'    => $currency,
                    'line_subtotal_amount'   => (int) $price->getAmount() * (int) $item->quantity,
                    'line_subtotal_currency' => $currency,
                    'line_total_amount'      => (int) $price->getAmount() * (int) $item->quantity,
                    'line_total_currency'    => $currency,
                ] )->save();
            }
        }

        $cart->unsetRelation( 'items' );
    }

    /**
     * Number of lines the shopper chose (free-item lines don't count).
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return int
     */
    protected function paidLineCount( Cart $cart ): int
    {
        return $cart->items()->get()->reject( static fn ( CartItem $item ): bool => $item->isFreeItem() )->count();
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart      $cart  Cart.
     * @param  CartItem  $item  Line.
     *
     * @throws CartOperationException When the line belongs to another cart.
     *
     * @return void
     */
    protected function assertOwnsItem( Cart $cart, CartItem $item ): void
    {
        if ( (int) $item->cart_id !== (int) $cart->id ) {
            throw new CartOperationException( 'item', 'item-not-found', __( 'That item is not in this cart.' ) );
        }
    }

    /**
     * Free-item lines follow their promotion, not the shopper.
     *
     * @since 1.0.0
     *
     * @param  CartItem  $item  Line.
     *
     * @throws CartOperationException When the line came with a promotion.
     *
     * @return void
     */
    protected function assertNotFreeItem( CartItem $item ): void
    {
        if ( $item->isFreeItem() ) {
            throw new CartOperationException( 'item', 'item-locked', __( 'That item comes with a promotion and can\'t be changed.' ) );
        }
    }

    /**
     * The coupon outcome a shopper sees. Unless
     * `promotions.verbose_coupon_errors` is on, an inactive or used-up
     * coupon reads like an unknown one, so error codes don't reveal which
     * codes exist (audit F14).
     *
     * @since 1.0.0
     *
     * @param  string  $status  `PromotionResult::COUPON_*` value.
     *
     * @return string
     */
    protected function publicCouponStatus( string $status ): string
    {
        if ( (bool) config( 'artisanpack.ecommerce.promotions.verbose_coupon_errors', false ) ) {
            return $status;
        }

        return in_array( $status, [ PromotionResult::COUPON_INACTIVE, PromotionResult::COUPON_EXHAUSTED ], true )
            ? PromotionResult::COUPON_INVALID
            : $status;
    }

    /**
     * Shopper-facing message for a coupon outcome.
     *
     * @since 1.0.0
     *
     * @param  string  $status  `PromotionResult::COUPON_*` value.
     *
     * @return string
     */
    protected function couponMessage( string $status ): string
    {
        return match ( $status ) {
            PromotionResult::COUPON_INACTIVE       => __( 'That coupon is not currently active.' ),
            PromotionResult::COUPON_EXHAUSTED      => __( 'That coupon has reached its usage limit.' ),
            PromotionResult::COUPON_NOT_ELIGIBLE   => __( 'Your cart does not qualify for that coupon.' ),
            PromotionResult::COUPON_NOT_COMBINABLE => __( 'That coupon cannot be combined with another promotion in your cart.' ),
            default                                => __( 'That coupon code is not valid.' ),
        };
    }
}
