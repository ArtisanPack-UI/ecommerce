<?php

/**
 * CartMergeService.
 *
 * Merges a guest cart into a destination (authenticated-customer) cart per
 * engine spec §7.1:
 *
 * - lines with the same `(product_id, product_variant_id, options_hash)`
 *   sum their quantities;
 * - lines that differ in variant or options stay separate;
 * - on currency mismatch, the service throws
 *   {@see \ArtisanPackUI\Ecommerce\Exceptions\CartCurrencyMismatchException}
 *   unless the caller passes an explicit
 *   {@see \ArtisanPackUI\Ecommerce\ValueObjects\CartMergeResolution} — the
 *   engine never silently re-prices on FX drift.
 *
 * On success the destination cart's token is rotated (defence against
 * cross-session cart hijacking) and the `CartMerged` event is dispatched.
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
     * @param  CartService  $cartService  Used to rotate the destination cart's session token after a successful merge.
     */
    public function __construct(
        protected CartService $cartService,
    ) {
    }

    /**
     * Merges `$guestCart` into `$destinationCart`.
     *
     * When the two carts share a currency, the merge is unconditional: each
     * guest line is added to the destination, summing quantities on same
     * `(product, variant, options_hash)` and keeping separate lines
     * otherwise.
     *
     * When the currencies differ, `$resolution` must be provided:
     *
     * - {@see CartMergeResolution::KeepGuestCurrency}: destination cart's
     *   currency is rewritten to the guest cart's, its existing lines are
     *   discarded, and the guest lines are carried over verbatim.
     * - {@see CartMergeResolution::SwitchToAccountCurrency}: destination
     *   cart's currency is retained, its lines are left untouched, and the
     *   guest cart's lines are discarded.
     * - {@see CartMergeResolution::CancelMerge}: destination cart is
     *   returned unchanged and the guest cart is discarded.
     *
     * If `$resolution` is null and currencies differ, a
     * {@see CartCurrencyMismatchException} is raised for the caller to
     * present the choice to the shopper.
     *
     * Fires `ap.ecommerce.cart.merging` (filter — can substitute the result
     * cart) and `ap.ecommerce.cart.merged` (action) around the merge, and
     * dispatches the {@see CartMerged} event.
     *
     * @since 1.0.0
     *
     * @param  Cart                      $guestCart
     * @param  Cart                      $destinationCart
     * @param  CartMergeResolution|null  $resolution
     *
     * @throws CartCurrencyMismatchException When currencies differ and no resolution is provided.
     *
     * @return Cart The surviving cart after the merge (always a rotated-token version of the destination cart's row, except on cancel-merge).
     */
    public function merge( Cart $guestCart, Cart $destinationCart, ?CartMergeResolution $resolution = null ): Cart
    {
        if ( $guestCart->is( $destinationCart ) ) {
            return $destinationCart;
        }

        if ( $guestCart->currency !== $destinationCart->currency && null === $resolution ) {
            throw new CartCurrencyMismatchException( $guestCart, $destinationCart );
        }

        return DB::transaction( function () use ( $guestCart, $destinationCart, $resolution ): Cart {
            $carried = $this->carriedCountBeforeApply( $guestCart, $resolution );

            $result = $this->applyMerge( $guestCart, $destinationCart, $resolution );

            $filtered = applyFilters( 'ap.ecommerce.cart.merging', $result, $guestCart, $destinationCart );
            if ( $filtered instanceof Cart ) {
                $result = $filtered;
            }

            // Cancel-merge is not a merge — the guest cart is discarded and the
            // destination is returned unchanged — so listeners of the merged
            // action / event would be misled if we fired them here.
            if ( CartMergeResolution::CancelMerge !== $resolution ) {
                doAction( 'ap.ecommerce.cart.merged', $result, $carried );
                Event::dispatch( new CartMerged( $result, $carried ) );
            }

            return $result;
        } );
    }

    /**
     * Counts how many guest lines will reach the destination cart, computed
     * before {@see applyMerge()} runs (which deletes the guest cart).
     *
     * @since 1.0.0
     *
     * @param  Cart                      $guestCart
     * @param  CartMergeResolution|null  $resolution
     *
     * @return int
     */
    protected function carriedCountBeforeApply( Cart $guestCart, ?CartMergeResolution $resolution ): int
    {
        if ( CartMergeResolution::CancelMerge === $resolution || CartMergeResolution::SwitchToAccountCurrency === $resolution ) {
            return 0;
        }

        return (int) $guestCart->items()->count();
    }

    /**
     * Executes the actual line-level merge and returns the surviving cart.
     *
     * @since 1.0.0
     *
     * @param  Cart                      $guestCart
     * @param  Cart                      $destinationCart
     * @param  CartMergeResolution|null  $resolution
     *
     * @return Cart
     */
    protected function applyMerge( Cart $guestCart, Cart $destinationCart, ?CartMergeResolution $resolution ): Cart
    {
        $sameCurrency = $guestCart->currency === $destinationCart->currency;

        if ( CartMergeResolution::CancelMerge === $resolution ) {
            $guestCart->delete();

            return $destinationCart->fresh() ?? $destinationCart;
        }

        if ( ! $sameCurrency && CartMergeResolution::SwitchToAccountCurrency === $resolution ) {
            $guestCart->delete();

            return $this->cartService->rotateToken( $destinationCart->fresh() ?? $destinationCart );
        }

        if ( ! $sameCurrency && CartMergeResolution::KeepGuestCurrency === $resolution ) {
            $destinationCart->items()->delete();
            $this->rewriteDestinationCurrency( $destinationCart, $guestCart->currency );
            $this->transferItems( $guestCart, $destinationCart );
            $guestCart->delete();

            return $this->cartService->rotateToken( $destinationCart->fresh() ?? $destinationCart );
        }

        $this->transferItems( $guestCart, $destinationCart );
        $guestCart->delete();

        return $this->cartService->rotateToken( $destinationCart->fresh() ?? $destinationCart );
    }

    /**
     * Rewrites every paired `_currency` column on the destination cart in place.
     *
     * @since 1.0.0
     *
     * @param  Cart    $cart
     * @param  string  $currency
     *
     * @return void
     */
    protected function rewriteDestinationCurrency( Cart $cart, string $currency ): void
    {
        $cart->currency          = $currency;
        $cart->subtotal_currency = $currency;
        $cart->discount_currency = $currency;
        $cart->tax_currency      = $currency;
        $cart->shipping_currency = $currency;
        $cart->total_currency    = $currency;
        $cart->save();
    }

    /**
     * Moves guest lines onto the destination cart, summing quantities where
     * the destination already has a matching line.
     *
     * @since 1.0.0
     *
     * @param  Cart  $guestCart
     * @param  Cart  $destinationCart
     *
     * @return void
     */
    protected function transferItems( Cart $guestCart, Cart $destinationCart ): void
    {
        $existing = CartItem::query()
            ->where( 'cart_id', $destinationCart->id )
            ->get()
            ->keyBy( fn ( CartItem $item ) => $this->lineKey( $item ) );

        foreach ( $guestCart->items()->get() as $guestItem ) {
            $key = $this->lineKey( $guestItem );

            if ( isset( $existing[ $key ] ) ) {
                /** @var CartItem $match */
                $match                     = $existing[ $key ];
                $match->quantity += $guestItem->quantity;
                $match->line_subtotal_amount = $match->unit_price_amount * $match->quantity;
                $match->line_total_amount    = $match->line_subtotal_amount;
                $match->save();

                $guestItem->delete();

                continue;
            }

            $guestItem->cart_id = $destinationCart->id;
            $guestItem->save();
        }
    }

    /**
     * Composes a dedupe key matching the cart_items dedupe index.
     *
     * @since 1.0.0
     *
     * @param  CartItem  $item
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
