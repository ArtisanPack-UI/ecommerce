<?php

/**
 * ReadsLineProducts.
 *
 * The paid lines of a cart or placed order as `{ product_id, variant_id,
 * quantity }` — lines a promotion added for free are left out — and the
 * category and tag ids of their products, for the conditions that look at
 * what is being bought.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Promotions\Conditions\Concerns;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
trait ReadsLineProducts
{
    /**
     * The cart's paid lines.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return array<int, array{product_id: int|null, variant_id: int|null, quantity: int}>
     */
    protected function cartLines( Cart $cart ): array
    {
        $items = $cart->relationLoaded( 'items' ) ? $cart->items : $cart->items()->get();

        return $items
            ->reject( static fn ( CartItem $item ): bool => $item->isFreeItem() )
            ->map( static fn ( CartItem $item ): array => [
                'product_id' => null === $item->product_id ? null : (int) $item->product_id,
                'variant_id' => null === $item->product_variant_id ? null : (int) $item->product_variant_id,
                'quantity'   => (int) $item->quantity,
            ] )
            ->values()
            ->all();
    }

    /**
     * The order's paid lines.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return array<int, array{product_id: int|null, variant_id: int|null, quantity: int}>
     */
    protected function orderLines( Order $order ): array
    {
        $items = $order->relationLoaded( 'items' ) ? $order->items : $order->items()->get();

        return $items
            ->reject( static fn ( OrderItem $item ): bool => true === ( $item->product_snapshot['free_item'] ?? false ) )
            ->map( static fn ( OrderItem $item ): array => [
                'product_id' => null === $item->product_id ? null : (int) $item->product_id,
                'variant_id' => null === $item->product_variant_id ? null : (int) $item->product_variant_id,
                'quantity'   => (int) $item->quantity,
            ] )
            ->values()
            ->all();
    }

    /**
     * The ids related to `$productIds` through `$relation` (`categories`
     * or `tags`), read from the pivot table so the lines' loaded relations
     * stay untouched.
     *
     * @since 1.0.0
     *
     * @param  array<int, int|null>  $productIds  Product ids.
     * @param  string                $relation    Product relation.
     *
     * @return array<int, int>
     */
    protected function relatedIds( array $productIds, string $relation ): array
    {
        $productIds = array_values( array_unique( array_filter( $productIds, 'is_int' ) ) );

        if ( [] === $productIds ) {
            return [];
        }

        /** @var BelongsToMany $pivot */
        $pivot = ( new Product() )->{$relation}();

        return DB::table( $pivot->getTable() )
            ->whereIn( $pivot->getForeignPivotKeyName(), $productIds )
            ->distinct()
            ->pluck( $pivot->getRelatedPivotKeyName() )
            ->map( static fn ( $id ): int => (int) $id )
            ->all();
    }

    /**
     * Positive integer ids from a config list.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Configured list.
     *
     * @return array<int, int>
     */
    protected static function ids( mixed $value ): array
    {
        return array_values( array_unique( array_filter( array_map( 'intval', array_filter( (array) $value, 'is_numeric' ) ), static fn ( int $id ): bool => $id > 0 ) ) );
    }
}
