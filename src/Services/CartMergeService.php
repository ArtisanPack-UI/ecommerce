<?php

/**
 * CartMergeService.
 *
 * Merges a guest cart into a destination (signed-in customer's) cart, per
 * parent plan §7.1:
 *
 * - lines with the same `(product_id, product_variant_id, options_hash)`
 *   sum their quantities, capped at {@see StorefrontCartService::MAX_LINE_QUANTITY}
 *   and at what is in stock (a destination line never shrinks);
 * - lines that differ in variant or options stay separate; guest lines past
 *   `cart.max_lines` are left out;
 * - promotion-granted free lines aren't carried — the merged cart's own
 *   promotions decide them again;
 * - the guest cart's stock reservations move to the destination;
 * - every line is re-priced and the totals recomputed.
 *
 * When the currencies differ the service throws
 * {@see CartCurrencyMismatchException} unless the caller passes a
 * {@see CartMergeResolution}, so the shopper chooses which currency to keep.
 * Lines are always re-priced from the catalog in the kept currency (never
 * converted from the other cart's prices); a line with no price there is
 * dropped.
 *
 * Both carts are locked (lower id first) for the whole merge, and a cart
 * that became an order or expired can't take part. On success the
 * destination cart's token is rotated (defence against cross-session cart
 * hijacking) and the `CartMerged` event is dispatched.
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

use ArtisanPackUI\Ecommerce\Events\CartMerged;
use ArtisanPackUI\Ecommerce\Exceptions\CartCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Inventory\StockLevels;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\ValueObjects\CartMergeResolution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CartMergeService
{
    /**
     * @since 1.0.0
     *
     * @param  CartService            $cartService  Rotates the destination cart's token after a merge.
     * @param  StorefrontCartService  $storefront   Re-prices and re-totals the merged cart.
     * @param  StockLevels            $stock        Caps merged quantities at what is in stock.
     * @param  InventoryService       $inventory    Moves the guest cart's reservations.
     */
    public function __construct(
        protected CartService $cartService,
        protected StorefrontCartService $storefront,
        protected StockLevels $stock,
        protected InventoryService $inventory,
    ) {
    }

    /**
     * Merges `$guestCart` into `$destinationCart`.
     *
     * When the two carts share a currency, the merge is unconditional.
     * When they differ, `$resolution` must be provided:
     *
     * - {@see CartMergeResolution::KeepGuestCurrency}: the destination cart
     *   switches to the guest cart's currency; both carts' lines are kept,
     *   priced in that currency.
     * - {@see CartMergeResolution::SwitchToAccountCurrency}: the destination
     *   cart keeps its currency; the guest lines are carried over, priced in
     *   that currency.
     * - {@see CartMergeResolution::CancelMerge}: the destination cart is
     *   returned unchanged and the guest cart is discarded.
     *
     * If `$resolution` is null and the currencies differ, a
     * {@see CartCurrencyMismatchException} is raised for the caller to
     * present the choice to the shopper.
     *
     * Fires `ap.ecommerce.cart.merging` (filter — can substitute the result
     * cart) and `ap.ecommerce.cart.merged` (action) around the merge, and
     * dispatches the {@see CartMerged} event.
     *
     * @since 1.0.0
     *
     * @param  Cart                      $guestCart        Guest cart (deleted by the merge).
     * @param  Cart                      $destinationCart  Cart that survives.
     * @param  CartMergeResolution|null  $resolution       Choice for a currency mismatch.
     *
     * @throws CartCurrencyMismatchException When currencies differ and no resolution is provided.
     * @throws CartOperationException        When either cart became an order or expired.
     *
     * @return Cart The surviving cart (the destination cart's row with a rotated token, except on cancel-merge).
     */
    public function merge( Cart $guestCart, Cart $destinationCart, ?CartMergeResolution $resolution = null ): Cart
    {
        if ( $guestCart->is( $destinationCart ) ) {
            return $destinationCart;
        }

        return DB::transaction( function () use ( $guestCart, $destinationCart, $resolution ): Cart {
            [ $guest, $destination ] = $this->lockPair( $guestCart, $destinationCart );

            $this->storefront->assertOpen( $guest );
            $this->storefront->assertOpen( $destination );

            $sameCurrency = strtoupper( (string) $guest->currency ) === strtoupper( (string) $destination->currency );

            if ( ! $sameCurrency && null === $resolution ) {
                throw new CartCurrencyMismatchException( $guest, $destination );
            }

            if ( CartMergeResolution::CancelMerge === $resolution ) {
                $this->discard( $guest );

                return $destination;
            }

            if ( ! $sameCurrency && CartMergeResolution::KeepGuestCurrency === $resolution ) {
                $this->rewriteDestinationCurrency( $destination, (string) $guest->currency );
            }

            $this->inventory->transferReservations( $guest, $destination, true );

            $carriedIds = $this->transferItems( $guest, $destination );
            $this->discard( $guest );

            $this->storefront->recalculate( $destination );

            // Re-pricing drops carried lines with no price in the kept currency.
            $carried = [] === $carriedIds ? 0 : CartItem::query()->whereKey( $carriedIds )->count();

            $result = $this->cartService->rotateToken( $destination );

            $filtered = applyFilters( 'ap.ecommerce.cart.merging', $result, $guestCart, $destinationCart );

            if ( $filtered instanceof Cart ) {
                $result = $filtered;
            }

            doAction( 'ap.ecommerce.cart.merged', $result, $carried );
            Event::dispatch( new CartMerged( $result, $carried ) );

            return $result;
        } );
    }

    /**
     * Locks both carts, lower id first so two merges of the same pair can't
     * deadlock, and returns them as `[ guest, destination ]`.
     *
     * @since 1.0.0
     *
     * @param  Cart  $guest        Guest cart.
     * @param  Cart  $destination  Destination cart.
     *
     * @return array{0: Cart, 1: Cart}
     */
    protected function lockPair( Cart $guest, Cart $destination ): array
    {
        $locked = Cart::query()
            ->whereKey( [ $guest->getKey(), $destination->getKey() ] )
            ->orderBy( 'id' )
            ->lockForUpdate()
            ->get()
            ->keyBy( 'id' );

        return [
            $locked->get( $guest->getKey() ) ?? throw new CartOperationException( 'cart', 'cart-not-found', __( 'That cart could not be found.' ) ),
            $locked->get( $destination->getKey() ) ?? throw new CartOperationException( 'cart', 'cart-not-found', __( 'That cart could not be found.' ) ),
        ];
    }

    /**
     * Deletes a cart and its lines.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return void
     */
    protected function discard( Cart $cart ): void
    {
        $cart->items()->delete();
        $cart->delete();
    }

    /**
     * Rewrites every paired `_currency` column on the destination cart in place.
     *
     * @since 1.0.0
     *
     * @param  Cart    $cart      Cart.
     * @param  string  $currency  ISO 4217 code.
     *
     * @return void
     */
    protected function rewriteDestinationCurrency( Cart $cart, string $currency ): void
    {
        $currency = strtoupper( $currency );

        $cart->forceFill( [
            'currency'          => $currency,
            'subtotal_currency' => $currency,
            'discount_currency' => $currency,
            'tax_currency'      => $currency,
            'shipping_currency' => $currency,
            'total_currency'    => $currency,
        ] )->save();
    }

    /**
     * Moves the guest lines onto the destination cart and returns the ids
     * of the destination lines they went into. A matching destination line takes the summed quantity,
     * capped at the line limit and at what is in stock (never less than it
     * already had); a new line is carried only while the cart is under
     * `cart.max_lines` and while any of it is in stock.
     *
     * @since 1.0.0
     *
     * @param  Cart  $guest        Locked guest cart.
     * @param  Cart  $destination  Locked destination cart.
     *
     * @return array<int, int>
     */
    protected function transferItems( Cart $guest, Cart $destination ): array
    {
        $existing = CartItem::query()
            ->where( 'cart_id', $destination->id )
            ->get()
            ->reject( static fn ( CartItem $item ): bool => $item->isFreeItem() )
            ->keyBy( fn ( CartItem $item ): string => $this->lineKey( $item ) );

        $lines   = $existing->count();
        $carried = [];

        foreach ( $guest->items()->with( [ 'product', 'variant' ] )->get() as $guestItem ) {
            if ( $guestItem->isFreeItem() || null === $guestItem->product ) {
                continue;
            }

            $key   = $this->lineKey( $guestItem );
            $match = $existing->get( $key );
            $limit = $this->unitLimit( $destination, $guestItem );

            if ( null !== $match ) {
                $quantity = max( (int) $match->quantity, min( (int) $match->quantity + (int) $guestItem->quantity, $limit ) );

                if ( $quantity !== (int) $match->quantity ) {
                    $match->forceFill( [
                        'quantity'             => $quantity,
                        'line_subtotal_amount' => (int) $match->unit_price_amount * $quantity,
                        'line_total_amount'    => (int) $match->unit_price_amount * $quantity,
                    ] )->save();
                }

                $carried[] = (int) $match->id;

                continue;
            }

            $quantity = min( (int) $guestItem->quantity, $limit );

            if ( $quantity < 1 || $lines >= $this->storefront->maxLines() ) {
                continue;
            }

            $guestItem->forceFill( [
                'cart_id'              => $destination->id,
                'quantity'             => $quantity,
                'line_subtotal_amount' => (int) $guestItem->unit_price_amount * $quantity,
                'line_total_amount'    => (int) $guestItem->unit_price_amount * $quantity,
            ] )->save();

            $existing->put( $key, $guestItem );
            ++$lines;
            $carried[] = (int) $guestItem->id;
        }

        return array_values( array_unique( $carried ) );
    }

    /**
     * Most units of `$item`'s product the destination line may hold.
     *
     * @since 1.0.0
     *
     * @param  Cart      $destination  Destination cart (its reservations count as its own).
     * @param  CartItem  $item         Guest line.
     *
     * @return int
     */
    protected function unitLimit( Cart $destination, CartItem $item ): int
    {
        $limit = StorefrontCartService::MAX_LINE_QUANTITY;

        if ( null !== $item->product && ! $item->product->typeIsMissing() ) {
            $available = $this->stock->sellable( $item->product, $item->variant, $destination );

            if ( null !== $available ) {
                $limit = min( $limit, $available );
            }
        }

        return $limit;
    }

    /**
     * Composes a dedupe key matching the cart_items dedupe index.
     *
     * @since 1.0.0
     *
     * @param  CartItem  $item  Line.
     *
     * @return string
     */
    protected function lineKey( CartItem $item ): string
    {
        return sprintf(
            '%d:%s:%s',
            $item->product_id,
            null === $item->product_variant_id ? '-' : (string) $item->product_variant_id,
            $item->options_hash,
        );
    }
}
