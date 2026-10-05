<?php

/**
 * StorefrontCartService.
 *
 * The shopper-facing cart operations behind the REST cart endpoints
 * (engine spec §9.2) and the matching GraphQL mutations (§10.3:
 * `createCart`, `addToCart`, `updateCartItem`, `removeCartItem`,
 * `applyCoupon`, `removeCoupon`). Both surfaces call this class, so they
 * share one set of rules:
 *
 * - only storefront-visible products can be added, and a variant must
 *   belong to its product;
 * - the unit price is always resolved server-side through
 *   {@see ProductPriceResolver} in the cart's currency — never taken from
 *   the client;
 * - every line is re-priced on every change, so no line can keep an
 *   expired sale price, and one line holds at most
 *   {@see self::MAX_LINE_QUANTITY} units;
 * - every change runs under a row lock on the cart, together with the
 *   recomputation of its subtotal, discount (promotions plus the applied
 *   coupon), and total;
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

use ArtisanPackUI\Ecommerce\Contracts\ShippingRateProvider;
use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Shipping\ZoneShippingRateProvider;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\PromotionResult;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingRate;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Money\Currency;
use Money\Money;

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
     * Most units one cart line can hold.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_LINE_QUANTITY = 10_000;

    /**
     * @since 1.0.0
     *
     * @param  CartService           $carts       Cart persistence + hooks.
     * @param  ProductPriceResolver  $prices      Server-side price lookup.
     * @param  PromotionEngine       $promotions  Discount evaluation.
     */
    public function __construct(
        protected CartService $carts,
        protected ProductPriceResolver $prices,
        protected PromotionEngine $promotions,
    ) {
    }

    /**
     * Creates a cart.
     *
     * @since 1.0.0
     *
     * @param  string|null  $currency  ISO 4217 code (defaults to the base currency).
     * @param  string|null  $email     Shopper email.
     *
     * @return Cart
     */
    public function create( ?string $currency = null, ?string $email = null ): Cart
    {
        $attributes = array_filter( [
            'currency' => null === $currency ? null : strtoupper( $currency ),
            'email'    => $email,
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
     * @throws CartOperationException When the product/variant is unavailable or unpriced.
     *
     * @return CartItem
     */
    public function addItem( Cart $cart, int $productId, ?int $variantId, int $quantity, array $options = [] ): CartItem
    {
        [ $product, $variant ] = $this->sellable( $productId, $variantId );

        return $this->mutate( $cart, function ( Cart $locked ) use ( $product, $variant, $quantity, $options ): CartItem {
            $price    = $this->priceFor( $locked, $variant ?? $product );
            $existing = (int) $locked->items()
                ->where( 'product_id', $product->id )
                ->where( 'product_variant_id', $variant?->id )
                ->where( 'options_hash', CartItem::hashOptions( $options ) )
                ->value( 'quantity' );

            if ( $existing + $quantity > self::MAX_LINE_QUANTITY ) {
                throw new CartOperationException( 'quantity', 'quantity-limit', __( 'A cart line can hold at most :max units.', [ 'max' => self::MAX_LINE_QUANTITY ] ) );
            }

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
     * @throws CartOperationException When the line is not in the cart.
     *
     * @return CartItem|null The updated line, or null when it was removed.
     */
    public function updateItem( Cart $cart, CartItem $item, int $quantity ): ?CartItem
    {
        $this->assertOwnsItem( $cart, $item );

        if ( $quantity > self::MAX_LINE_QUANTITY ) {
            throw new CartOperationException( 'quantity', 'quantity-limit', __( 'A cart line can hold at most :max units.', [ 'max' => self::MAX_LINE_QUANTITY ] ) );
        }

        return $this->mutate( $cart, function ( Cart $locked ) use ( $item, $quantity ): ?CartItem {
            if ( $quantity <= 0 ) {
                $this->carts->removeItem( $locked, $item );

                return null;
            }

            [ $product, $variant ] = $this->sellable( (int) $item->product_id, null === $item->product_variant_id ? null : (int) $item->product_variant_id );

            $price   = $this->priceFor( $locked, $variant ?? $product );
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
     * @throws CartOperationException When the line is not in the cart.
     *
     * @return void
     */
    public function removeItem( Cart $cart, CartItem $item ): void
    {
        $this->assertOwnsItem( $cart, $item );

        $this->mutate( $cart, fn ( Cart $locked ) => $this->carts->removeItem( $locked, $item ) );
    }

    /**
     * Applies a coupon code, replacing any previously applied code.
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
            $result = $this->promotions->evaluate( $locked->load( 'items' ), $code );

            if ( ! $result->couponApplied() ) {
                $status = $result->couponStatus ?? PromotionResult::COUPON_INVALID;

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

            $locked->setRawAttributes( $cleared->getAttributes(), true );

            $meta = (array) ( $locked->meta ?? [] );
            unset( $meta[ self::SHIPPING_RATE_META_KEY ] );
            $locked->meta = $meta;

            return $locked;
        } );
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
     * The selection is not re-quoted when lines change afterwards; checkout
     * should quote again before charging, as it does for tax, and check the
     * shipping address matches the stored `destination`.
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
        $provider ??= app( ZoneShippingRateProvider::class );

        $rate = $this->mutate( $cart, function ( Cart $locked ) use ( $destination, $rateId, $provider ): ShippingRate {
            $rate = $provider->getRatesForCart( $locked->load( 'items' ), $destination )
                ->first( static fn ( ShippingRate $rate ): bool => $rate->id() === $rateId
                    && $rate->amount->getCurrency()->getCode() === strtoupper( (string) $locked->currency )
                    && ! $rate->amount->isNegative() );

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
            $locked->meta                         = $meta;
            $locked->shipping_amount              = (int) $rate->amount->getAmount();

            return $rate;
        } );

        $method = null === $rate->shippingMethodId ? null : ShippingMethod::query()->find( $rate->shippingMethodId );

        doAction( 'ap.ecommerce.shipping.methodSelected', $rate, $cart, $method );

        return $cart;
    }

    /**
     * Recomputes subtotal, discount, and total from the cart's lines, the
     * automatic promotions, and the applied coupon. A coupon that stopped
     * applying (expired, usage exhausted, cart no longer eligible) is
     * dropped from the cart.
     *
     * The subtotal runs through `ap.ecommerce.pricing.subtotal` and the
     * total through `ap.ecommerce.pricing.total` (filters). A return that
     * isn't a non-negative {@see Money} in the cart's currency is ignored.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return Cart
     */
    public function refreshTotals( Cart $cart ): Cart
    {
        $cart->load( 'items.product' );

        $currency              = new Currency( strtoupper( (string) $cart->currency ) );
        $subtotal              = new Money( (int) $cart->items->sum( 'line_subtotal_amount' ), $currency );
        $cart->subtotal_amount = (int) $this->validAmount( applyFilters( 'ap.ecommerce.pricing.subtotal', $subtotal, $cart ), $subtotal )->getAmount();

        $meta       = (array) ( $cart->meta ?? [] );
        $unsellable = $this->unsellableItems( $cart )->modelKeys();

        if ( [] === $unsellable ) {
            unset( $meta[ self::UNSELLABLE_META_KEY ] );
        } else {
            $meta[ self::UNSELLABLE_META_KEY ] = $unsellable;
        }

        $cart->meta = $meta;

        $coupon = $meta[ self::COUPON_META_KEY ] ?? null;
        $result = $this->promotions->evaluate( $cart, is_string( $coupon ) ? $coupon : null );

        if ( is_string( $coupon ) && ! $result->couponApplied() ) {
            unset( $meta[ self::COUPON_META_KEY ] );
            $cart->meta = $meta;
        }

        $cart->discount_amount = (int) $result->discountTotal()->getAmount();

        $total     = new Money( max(
            0,
            $cart->subtotal_amount - $cart->discount_amount + (int) $cart->tax_amount + (int) $cart->shipping_amount,
        ), $currency );
        $breakdown = [
            'subtotal' => new Money( (int) $cart->subtotal_amount, $currency ),
            'discount' => new Money( (int) $cart->discount_amount, $currency ),
            'tax'      => new Money( (int) $cart->tax_amount, $currency ),
            'shipping' => new Money( (int) $cart->shipping_amount, $currency ),
        ];

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
     * Runs a cart change under a row lock on the cart, then recomputes its
     * totals in the same transaction — so concurrent changes to one cart
     * serialize and the stored totals always match the stored lines. The
     * caller's `$cart` instance is synced with the result.
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

            $result = $change( $locked );

            $this->repriceLines( $locked );
            $this->refreshTotals( $locked );
            $cart->setRawAttributes( $locked->getAttributes(), true );
            $cart->setRelation( 'items', $locked->items );

            return $result;
        } );
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
     * The current price of a product / variant in the cart's currency.
     *
     * @since 1.0.0
     *
     * @param  Cart                    $cart       Cart.
     * @param  Product|ProductVariant  $priceable  Priceable.
     *
     * @throws CartOperationException When it has no price in that currency.
     *
     * @return Money
     */
    protected function priceFor( Cart $cart, Product|ProductVariant $priceable ): Money
    {
        return $this->prices->resolve( $priceable, (string) $cart->currency )
            ?? throw new CartOperationException( 'product_id', 'price-unavailable', __( 'That product has no price in :currency.', [ 'currency' => $cart->currency ] ) );
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
     * @param  Money     $price  Unit price from {@see ProductPriceResolver}.
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
     * Re-prices every line at its current price, so no line keeps a price
     * that has since changed (an ended sale, a new price row). Lines whose
     * product is no longer sellable keep their stored price; checkout
     * rejects them.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Locked cart.
     *
     * @return void
     */
    protected function repriceLines( Cart $cart ): void
    {
        foreach ( $cart->items()->with( [ 'product', 'variant' ] )->get() as $item ) {
            $priceable = $item->variant ?? $item->product;
            $price     = null === $priceable ? null : $this->prices->resolve( $priceable, (string) $cart->currency );

            if ( null === $price ) {
                continue;
            }

            $price = $this->linePrice( $cart, $item, $price );

            if ( (int) $price->getAmount() !== (int) $item->unit_price_amount ) {
                $item->unit_price_amount    = (int) $price->getAmount();
                $item->line_subtotal_amount = $item->unit_price_amount * (int) $item->quantity;
                $item->line_total_amount    = $item->line_subtotal_amount;
                $item->save();
            }
        }
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
    protected function assertOpen( Cart $cart ): void
    {
        if ( null !== $cart->completed_order_id || ( null !== $cart->expires_at && $cart->expires_at->isPast() ) ) {
            throw new CartOperationException( 'cart', 'cart-closed', __( 'This cart can no longer be changed.' ) );
        }
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
