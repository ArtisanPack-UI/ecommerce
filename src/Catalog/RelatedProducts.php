<?php

/**
 * RelatedProducts.
 *
 * Upsells, cross-sells, and "you may also like" (#182). Hand-picked links
 * ({@see ProductRelation}) come first, in order; `related` lists are then
 * filled up from products sharing a category, then a tag. Only products
 * shoppers can see are returned, never the product itself.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Catalog;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductRelation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RelatedProducts
{
    /**
     * Up to `$limit` products of `$type` for `$product`, through
     * `ap.ecommerce.product.related`.
     *
     * @since 1.0.0
     *
     * @param  Product                    $product  Product.
     * @param  string                     $type     {@see ProductRelation::TYPES}.
     * @param  int                        $limit    Most to return.
     * @param  array<int|string, mixed>   $with     Relations to eager-load.
     *
     * @return Collection<int, Product>
     */
    public function for( Product $product, string $type = ProductRelation::RELATED, int $limit = 8, array $with = [] ): Collection
    {
        $limit  = max( 1, min( 50, $limit ) );
        $picked = $this->picked( [ (int) $product->id ], $type, $limit, [ (int) $product->id ], $with );

        if ( ProductRelation::RELATED === $type && $picked->count() < $limit ) {
            $exclude = [ (int) $product->id, ...$picked->modelKeys() ];
            $picked  = $picked->merge( $this->sharing( $product, 'categories', $limit - $picked->count(), $exclude, $with ) );
        }

        if ( ProductRelation::RELATED === $type && $picked->count() < $limit ) {
            $exclude = [ (int) $product->id, ...$picked->modelKeys() ];
            $picked  = $picked->merge( $this->sharing( $product, 'tags', $limit - $picked->count(), $exclude, $with ) );
        }

        $filtered = applyFilters( 'ap.ecommerce.product.related', $picked, $product, $type, $limit );

        return $filtered instanceof Collection ? $filtered : $picked;
    }

    /**
     * Cross-sells for what's in `$cart`: the cart products' hand-picked
     * cross-sells, first line first, leaving out anything already in the
     * cart; through `ap.ecommerce.cart.crossSells`.
     *
     * @since 1.0.0
     *
     * @param  Cart                      $cart   Cart.
     * @param  int                       $limit  Most to return.
     * @param  array<int|string, mixed>  $with   Relations to eager-load.
     *
     * @return Collection<int, Product>
     */
    public function crossSellsForCart( Cart $cart, int $limit = 8, array $with = [] ): Collection
    {
        $items    = $cart->relationLoaded( 'items' ) ? $cart->items : $cart->items()->get();
        $inCart   = $items->map( static fn ( CartItem $item ): int => (int) $item->product_id )->unique()->values()->all();
        $products = [] === $inCart ? new Collection() : $this->picked( $inCart, ProductRelation::CROSS_SELL, max( 1, min( 50, $limit ) ), $inCart, $with );

        $filtered = applyFilters( 'ap.ecommerce.cart.crossSells', $products, $cart, $limit );

        return $filtered instanceof Collection ? $filtered : $products;
    }

    /**
     * Visible hand-picked `$type` products of `$sources`, in source then
     * position order, without `$exclude`.
     *
     * @since 1.0.0
     *
     * @param  array<int, int>           $sources  Product ids whose links to read.
     * @param  string                    $type     Relation type.
     * @param  int                       $limit    Most to return.
     * @param  array<int, int>           $exclude  Product ids to leave out.
     * @param  array<int|string, mixed>  $with     Eager loads.
     *
     * @return Collection<int, Product>
     */
    protected function picked( array $sources, string $type, int $limit, array $exclude, array $with ): Collection
    {
        $order = [];

        ProductRelation::query()
            ->whereIn( 'product_id', $sources )
            ->where( 'type', $type )
            ->whereNotIn( 'related_product_id', $exclude )
            ->get( [ 'product_id', 'related_product_id', 'position' ] )
            ->sortBy( static fn ( ProductRelation $row ): array => [ array_search( (int) $row->product_id, $sources, true ), (int) $row->position ] )
            ->each( static function ( ProductRelation $row ) use ( &$order ): void {
                $order[ (int) $row->related_product_id ] ??= count( $order );
            } );

        if ( [] === $order ) {
            return new Collection();
        }

        return Product::query()
            ->storefrontVisible()
            ->whereKey( array_keys( $order ) )
            ->with( $with )
            ->get()
            ->sortBy( static fn ( Product $product ): int => $order[ (int) $product->id ] )
            ->take( $limit )
            ->values();
    }

    /**
     * Visible products sharing a category or tag (`$relation`) with
     * `$product`, most shared first.
     *
     * @since 1.0.0
     *
     * @param  Product                   $product   Product.
     * @param  string                    $relation  `categories` or `tags`.
     * @param  int                       $limit     Most to return.
     * @param  array<int, int>           $exclude   Product ids to leave out.
     * @param  array<int|string, mixed>  $with      Eager loads.
     *
     * @return Collection<int, Product>
     */
    protected function sharing( Product $product, string $relation, int $limit, array $exclude, array $with ): Collection
    {
        $pivot   = $product->{$relation}();
        $table   = $pivot->getTable();
        $own     = $pivot->getForeignPivotKeyName();
        $related = $pivot->getRelatedPivotKeyName();
        $ids     = DB::table( $table )->where( $own, $product->id )->pluck( $related )->all();

        if ( [] === $ids ) {
            return new Collection();
        }

        $ranked = DB::table( $table )
            ->whereIn( $related, $ids )
            ->whereNotIn( $own, $exclude )
            ->groupBy( $own )
            ->orderByRaw( 'COUNT(*) DESC' )
            ->orderBy( $own )
            ->limit( $limit * 4 )
            ->pluck( $own )
            ->map( static fn ( $id ): int => (int) $id )
            ->all();

        if ( [] === $ranked ) {
            return new Collection();
        }

        $rank = array_flip( $ranked );

        return Product::query()
            ->storefrontVisible()
            ->whereKey( $ranked )
            ->with( $with )
            ->get()
            ->sortBy( static fn ( Product $candidate ): int => $rank[ (int) $candidate->id ] )
            ->take( $limit )
            ->values();
    }
}
