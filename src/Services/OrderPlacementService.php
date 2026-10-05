<?php

/**
 * OrderPlacementService.
 *
 * Turns a cart into an order (engine spec §6.1; audit D1). Every step runs
 * in one transaction under the cart's row lock, so a failure anywhere
 * leaves nothing behind and two placements of the same cart produce one
 * order:
 *
 *  1. the cart must be open (not converted, not expired);
 *  2. its lines are re-priced, promotions re-evaluated, the shipping rate
 *     re-quoted, and tax recalculated ({@see StorefrontCartService::recalculate()});
 *     a coupon that stopped applying refuses the order;
 *  3. it must be complete — lines that can all be sold (variable products
 *     with a variant), an email, a billing address (or a shipping address to
 *     use as one), and for orders that ship a shipping address and a
 *     current shipping rate; with `expected_total` in the context the total
 *     must still match what the shopper is paying;
 *  4. its stock is held ({@see CheckoutReservations}); a shortfall refuses
 *     the order;
 *  5. the currency, base currency and FX rate are snapshotted;
 *  6. the order attributes run through `ap.ecommerce.order.placing`, the
 *     number comes from the {@see OrderNumberGenerator} through
 *     `ap.ecommerce.order.number`, and each line is snapshotted through its
 *     product type's `buildOrderSnapshot()`, with its own discount and tax;
 *  7. shipping, and tax not tied to a line (tax on shipping), are split
 *     across the lines by the configured {@see FulfillmentAllocationStrategy};
 *  8. the cart's reservations become the order's (they no longer expire);
 *  9. promotion usage is recorded (limits are enforced under lock);
 * 10. the cart is cleared (`converted`) and pointed at the order;
 * 11. each line's product type runs `onOrderPlaced()`.
 *
 * Once the transaction commits, `ap.ecommerce.order.placed` fires and
 * {@see OrderPlaced}, {@see CartCompleted}, {@see PromotionApplied} (per
 * promotion) and {@see CouponRedeemed} are dispatched.
 *
 * The order is `pending` / payment `pending`: taking the payment is the
 * caller's next step ({@see CheckoutService::finalize()}).
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

use ArtisanPackUI\Ecommerce\Checkout\CheckoutReservations;
use ArtisanPackUI\Ecommerce\Contracts\OrderNumberGenerator;
use ArtisanPackUI\Ecommerce\Events\CartCompleted;
use ArtisanPackUI\Ecommerce\Events\CouponRedeemed;
use ArtisanPackUI\Ecommerce\Events\OrderPlaced;
use ArtisanPackUI\Ecommerce\Events\PromotionApplied;
use ArtisanPackUI\Ecommerce\Exceptions\OrderPlacementException;
use ArtisanPackUI\Ecommerce\Exceptions\PromotionUsageLimitReachedException;
use ArtisanPackUI\Ecommerce\Fulfillment\LineAllocator;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Registries\CurrencyRateProviderRegistry;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;
use ArtisanPackUI\Ecommerce\ValueObjects\PromotionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Money\Currency;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderPlacementService
{
    /**
     * Placement context key: the total (minor units) the shopper is paying;
     * placement is refused when the recalculated total differs.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const EXPECTED_TOTAL = 'expected_total';

    /**
     * @since 1.0.0
     *
     * @param  StorefrontCartService                  $carts         Recalculation and cart rules.
     * @param  CartService                            $cartService   Clears the converted cart.
     * @param  CheckoutReservations                   $reservations  Stock holds.
     * @param  InventoryService                       $inventory     Moves the holds to the order.
     * @param  PromotionEngine                        $promotions    Usage recording.
     * @param  OrderPlacementHooks                    $hooks         `order.placing` / `order.placed`.
     * @param  OrderNumberGenerator                   $numbers       Order numbers.
     * @param  LineAllocator                          $lines         Per-line split of shipping and tax.
     * @param  CurrencyRateProviderRegistry           $rates         FX snapshot.
     * @param  StoreCurrencies                        $currencies    Base currency.
     */
    public function __construct(
        protected StorefrontCartService $carts,
        protected CartService $cartService,
        protected CheckoutReservations $reservations,
        protected InventoryService $inventory,
        protected PromotionEngine $promotions,
        protected OrderPlacementHooks $hooks,
        protected OrderNumberGenerator $numbers,
        protected LineAllocator $lines,
        protected CurrencyRateProviderRegistry $rates,
        protected StoreCurrencies $currencies,
    ) {
    }

    /**
     * Places an order from `$cart`.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart.
     * @param  array<string, mixed>  $context  `expected_total` (int), `ip_address`, `user_agent`, `customer_note`.
     *
     * @throws OrderPlacementException When the cart can't become an order.
     *
     * @return Order The placed order, with its items.
     */
    public function place( Cart $cart, array $context = [] ): Order
    {
        [ $order, $converted, $result ] = DB::transaction( function () use ( $cart, $context ): array {
            $locked = Cart::query()->lockForUpdate()->find( $cart->getKey() );

            if ( null === $locked || null !== $locked->completed_order_id || ( null !== $locked->expires_at && $locked->expires_at->isPast() ) ) {
                throw OrderPlacementException::cartClosed();
            }

            $coupon = ( (array) ( $locked->meta ?? [] ) )[ StorefrontCartService::COUPON_META_KEY ] ?? null;

            $this->carts->recalculate( $locked );
            $locked->refresh()->load( [ 'items.product', 'items.variant' ] );

            // A coupon the shopper applied stopped applying (used up, expired,
            // or the cart no longer qualifies): don't charge them more than
            // they agreed to without saying so.
            if ( null !== $coupon && null === ( ( (array) ( $locked->meta ?? [] ) )[ StorefrontCartService::COUPON_META_KEY ] ?? null ) ) {
                throw OrderPlacementException::couponNoLongerValid();
            }

            $this->assertPlaceable( $locked, $context );

            $this->reservations->hold( $locked, false );

            $result = $this->carts->promotionResult( $locked );
            $order  = $this->createOrder( $locked, $context );

            $this->createItems( $order, $locked );
            $this->allocate( $order );

            $this->inventory->transferReservations( $locked, $order );

            try {
                $this->promotions->recordUsage( $order, $result );
            } catch ( PromotionUsageLimitReachedException $exception ) {
                throw new OrderPlacementException( 'coupon', 'promotion-unavailable', $exception->getMessage(), 409 );
            }

            // A snapshot of the cart as it was converted, for the events.
            $converted         = $locked->replicate()->setRelation( 'items', $locked->items );
            $converted->id     = $locked->id;
            $converted->exists = true;

            $cleared = $this->cartService->clear( $locked, 'converted' );
            $cleared->forceFill( [ 'completed_order_id' => $order->id ] )->save();

            $order->load( 'items' );

            foreach ( $order->items as $item ) {
                $product = $item->product;

                if ( null !== $product && ! $product->typeIsMissing() ) {
                    $product->productType()->onOrderPlaced( $order, $item );
                }
            }

            return [ $order, $converted, $result ];
        } );

        // After the outermost commit: a caller may place inside its own transaction.
        AfterCommit::run( 'ap.ecommerce.order.placed', fn () => $this->announce( $order, $converted, $result ) );

        $cart->refresh();

        return $order;
    }

    /**
     * Refuses a cart that isn't ready to become an order.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Locked, recalculated cart.
     * @param  array<string, mixed>  $context  Placement context.
     *
     * @throws OrderPlacementException When it isn't.
     *
     * @return void
     */
    protected function assertPlaceable( Cart $cart, array $context ): void
    {
        $paid = $cart->items->reject( static fn ( CartItem $item ): bool => $item->isFreeItem() );

        if ( $paid->isEmpty() ) {
            throw OrderPlacementException::emptyCart();
        }

        $unsellable = $this->carts->unsellableItems( $cart );

        if ( $unsellable->isNotEmpty() ) {
            throw OrderPlacementException::unsellableItems( $unsellable->modelKeys() );
        }

        foreach ( $paid as $item ) {
            try {
                $item->product->productType()->validateCartOptions( $item->product, array_filter( [ 'variant_id' => $item->product_variant_id ] ) + (array) ( $item->options ?? [] ) );
            } catch ( InvalidArgumentException ) {
                throw OrderPlacementException::variantRequired();
            }
        }

        if ( null === $cart->email || '' === trim( (string) $cart->email ) ) {
            throw OrderPlacementException::emailRequired();
        }

        $meta = (array) ( $cart->meta ?? [] );

        if ( $this->carts->requiresShipping( $cart ) ) {
            if ( null === $cart->shipping_address ) {
                throw OrderPlacementException::shippingAddressRequired();
            }

            if ( ! is_array( $meta[ StorefrontCartService::SHIPPING_RATE_META_KEY ] ?? null ) ) {
                throw OrderPlacementException::shippingRateStale();
            }
        }

        if ( null === $cart->billing_address && null === $cart->shipping_address ) {
            throw OrderPlacementException::billingAddressRequired();
        }

        if ( isset( $context[ self::EXPECTED_TOTAL ] ) && (int) $context[ self::EXPECTED_TOTAL ] !== (int) $cart->total_amount ) {
            throw OrderPlacementException::totalsChanged();
        }
    }

    /**
     * Creates the order row from the cart.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Locked cart.
     * @param  array<string, mixed>  $context  Placement context.
     *
     * @return Order
     */
    protected function createOrder( Cart $cart, array $context ): Order
    {
        $currency = strtoupper( (string) $cart->currency );
        $base     = $this->currencies->base();
        $meta     = (array) ( $cart->meta ?? [] );
        $rate     = $meta[ StorefrontCartService::SHIPPING_RATE_META_KEY ] ?? null;
        $tax      = (array) ( $meta[ StorefrontCartService::TAX_META_KEY ] ?? [] );
        $taxAt    = (array) ( $cart->shipping_address ?? $cart->billing_address ?? [] );

        $attributes = [
            'customer_id'             => $cart->customer_id,
            'email'                   => $cart->email,
            'phone'                   => $cart->shipping_address['phone'] ?? $cart->billing_address['phone'] ?? null,
            'system_status'           => 'pending',
            'payment_status'          => 'pending',
            'fulfillment_status'      => 'unfulfilled',
            'currency'                => $currency,
            'base_currency'           => $base,
            'fx_rate_to_base_e8'      => $this->exchangeRate( $currency, $base ),
            'subtotal_amount'         => (int) $cart->subtotal_amount,
            'subtotal_currency'       => $currency,
            'discount_amount'         => (int) $cart->discount_amount,
            'discount_currency'       => $currency,
            'tax_amount'              => (int) $cart->tax_amount,
            'tax_currency'            => $currency,
            'shipping_amount'         => (int) $cart->shipping_amount,
            'shipping_currency'       => $currency,
            'total_amount'            => (int) $cart->total_amount,
            'total_currency'          => $currency,
            'total_refunded_currency' => $currency,
            'shipping_address'        => $cart->shipping_address,
            'billing_address'         => $cart->billing_address ?? $cart->shipping_address,
            'shipping_method_key'     => is_array( $rate ) ? ( $rate['method_key'] ?? null ) : null,
            'payment_gateway_key'     => $cart->payment_gateway_key,
            'payment_reference'       => $cart->payment_reference,
            'ip_address'              => isset( $context['ip_address'] ) ? mb_substr( (string) $context['ip_address'], 0, 45 ) : null,
            'user_agent'              => isset( $context['user_agent'] ) ? mb_substr( (string) $context['user_agent'], 0, 255 ) : null,
            'customer_note'           => isset( $context['customer_note'] ) ? mb_substr( trim( (string) $context['customer_note'] ), 0, 2_000 ) : null,
            'is_claimed'              => null !== $cart->customer_id,
            'placed_at'               => Carbon::now(),
            'locale'                  => $cart->locale,
            'meta'                    => array_filter( [
                'coupon_code'        => $meta[ StorefrontCartService::COUPON_META_KEY ] ?? null,
                'shipping_rate'      => is_array( $rate ) ? array_diff_key( $rate, [ 'fingerprint' => true ] ) : null,
                'prices_include_tax' => (bool) ( $tax['prices_include_tax'] ?? false ),
                'tax_breakdown'      => array_values( array_map( static fn ( array $row ): array => $row + [
                    'country_code' => isset( $taxAt['country_code'] ) ? strtoupper( (string) $taxAt['country_code'] ) : null,
                    'region_code'  => $taxAt['region_code'] ?? null,
                ], (array) ( $tax['breakdown'] ?? [] ) ) ),
            ], static fn ( mixed $value ): bool => null !== $value && [] !== $value ),
        ];

        $order = new Order( $this->hooks->placing( $attributes, $cart ) );

        $number              = $this->numbers->generate( $order );
        $filtered            = applyFilters( 'ap.ecommerce.order.number', $number, $order );
        $order->order_number = is_string( $filtered ) && '' !== trim( $filtered ) ? mb_substr( trim( $filtered ), 0, 50 ) : $number;
        $order->save();

        // A customer without a language preference takes the one they
        // shopped in, for later notifications that aren't about an order.
        if ( null !== $cart->customer_id && '' !== (string) $cart->locale ) {
            Customer::query()->whereKey( $cart->customer_id )->whereNull( 'locale' )->update( [ 'locale' => $cart->locale ] );
        }

        return $order;
    }

    /**
     * Copies the cart lines onto the order, each with its snapshot, its own
     * discount, and its own tax.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     * @param  Cart   $cart   Locked cart.
     *
     * @return void
     */
    protected function createItems( Order $order, Cart $cart ): void
    {
        foreach ( $cart->items as $item ) {
            $currency = strtoupper( (string) $cart->currency );
            $product  = $item->product;
            $variant  = $item->variant;

            OrderItem::query()->create( [
                'order_id'           => $order->id,
                'product_id'         => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_snapshot'   => array_merge( array_filter( [
                    'name'         => $product?->name,
                    'sku'          => $variant?->sku ?? $product?->sku,
                    'variant_name' => $variant?->name,
                    'free_item'    => $item->isFreeItem() ? true : null,
                    'promotion_id' => $item->isFreeItem() ? ( $item->meta['promotion_id'] ?? null ) : null,
                ], static fn ( mixed $value ): bool => null !== $value ), $product->productType()->buildOrderSnapshot( $item ) ),
                'quantity'            => (int) $item->quantity,
                'unit_price_amount'   => (int) $item->unit_price_amount,
                'unit_price_currency' => $currency,
                'discount_amount'     => (int) $item->discount_amount,
                'discount_currency'   => $currency,
                'tax_amount'          => (int) $item->tax_amount,
                'tax_currency'        => $currency,
                'shipping_amount'     => 0,
                'shipping_currency'   => $currency,
                'total_amount'        => 0,
                'total_currency'      => $currency,
                'fulfillment_status'  => 'unfulfilled',
                'meta'                => (array) ( $item->meta ?? [] ),
            ] );
        }
    }

    /**
     * Splits shipping, and the tax not already on a line (tax on shipping),
     * across the order's lines ({@see LineAllocator}).
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order with its items.
     *
     * @return void
     */
    protected function allocate( Order $order ): void
    {
        $items = $order->items()->get();

        $this->lines->allocate(
            $order,
            $items,
            $items->mapWithKeys( static fn ( OrderItem $item ): array => [ (int) $item->id => (int) $item->tax_amount ] )->all(),
            (bool) ( $order->meta['prices_include_tax'] ?? false ),
        );
    }

    /**
     * The `$currency` → `$base` rate (×1e8) to snapshot on the order.
     *
     * @since 1.0.0
     *
     * @param  string  $currency  Order currency.
     * @param  string  $base      Base currency.
     *
     * @throws OrderPlacementException When no rate is available.
     *
     * @return int
     */
    protected function exchangeRate( string $currency, string $base ): int
    {
        if ( $currency === $base ) {
            return 100_000_000;
        }

        try {
            $rate = $this->rates->active()->getRateE8( new Currency( $currency ), new Currency( $base ) );
        } catch ( Throwable $exception ) {
            Log::channel( 'ecommerce' )->error( 'No exchange rate for an order currency; the order was not placed.', [ 'currency' => $currency, 'base' => $base, 'error' => $exception->getMessage() ] );

            throw OrderPlacementException::exchangeRateUnavailable();
        }

        if ( $rate < 1 ) {
            throw OrderPlacementException::exchangeRateUnavailable();
        }

        return $rate;
    }

    /**
     * Fires the placement hook and events (run once the transaction commits).
     *
     * @since 1.0.0
     *
     * @param  Order            $order      Placed order.
     * @param  Cart             $converted  The cart as it was converted.
     * @param  PromotionResult  $result     Promotions the order was priced with.
     *
     * @return void
     */
    protected function announce( Order $order, Cart $converted, PromotionResult $result ): void
    {
        $this->hooks->placed( $order );

        Event::dispatch( new OrderPlaced( $order ) );
        Event::dispatch( new CartCompleted( $converted, $order ) );

        foreach ( $result->applied as $promotion ) {
            Event::dispatch( new PromotionApplied( $promotion, $converted, $result->ledger->amountForPromotion( (int) $promotion->id ) ) );
        }

        if ( $result->couponApplied() && null !== $result->couponCode ) {
            $coupon = Coupon::findByCode( $result->couponCode );

            if ( null !== $coupon ) {
                Event::dispatch( new CouponRedeemed( $coupon, $order, $result->ledger->amountForPromotion( (int) $coupon->promotion_id ) ) );
            }
        }
    }
}
