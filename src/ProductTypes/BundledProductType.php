<?php

/**
 * Bundled product type.
 *
 * Several products sold together as one line at the bundle's own price
 * ("starter kit"). Members are stored in `product_children` (engine spec
 * §3.10a) and copied into the order snapshot so the packing list survives
 * later catalog edits. Stock is tracked on the bundle's own inventory row.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\ProductTypes;

use ArtisanPackUI\Ecommerce\Contracts\ExpandsInventory;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductChild;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;

/**
 * `bundled` product type.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class BundledProductType extends AbstractProductType implements ExpandsInventory
{
    /**
     * Registry key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'bundled';

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return __( 'Bundled product' );
    }

    /**
     * @since 1.0.0
     *
     * @return string|null
     */
    public function icon(): ?string
    {
        return 'hero-gift';
    }

    /**
     * @since 1.0.0
     *
     * @return bool
     */
    public function requiresFulfillment(): bool
    {
        return true;
    }

    /**
     * Adds the bundle's members to the line snapshot.
     *
     * @since 1.0.0
     *
     * @param  CartItem  $item  Cart line.
     *
     * @return array<string, mixed>
     */
    public function buildOrderSnapshot( CartItem $item ): array
    {
        $snapshot = parent::buildOrderSnapshot( $item );

        $snapshot['bundle'] = ProductChild::query()
            ->where( 'parent_product_id', $item->product_id )
            ->with( [ 'product:id,name,sku', 'variant:id,name,sku' ] )
            ->orderBy( 'position' )
            ->orderBy( 'id' )
            ->get()
            ->map( static fn ( ProductChild $child ): array => [
                'product_id' => $child->child_product_id,
                'variant_id' => $child->child_variant_id,
                'name'       => $child->variant?->name ?? $child->product?->name,
                'sku'        => $child->variant?->sku ?? $child->product?->sku,
                'quantity'   => $child->quantity,
            ] )
            ->all();

        return $snapshot;
    }

    /**
     * A bundle's stock is its members': each sold bundle consumes every
     * member's quantity.
     *
     * @since 1.0.0
     *
     * @param  Product              $product   Bundle.
     * @param  ProductVariant|null  $variant   Unused (bundles have no variants).
     * @param  int                  $quantity  Bundles sold.
     *
     * @return array<int, array{stockable: Product|ProductVariant, quantity: int}>
     */
    public function inventoryComponents( Product $product, ?ProductVariant $variant, int $quantity ): array
    {
        $components = [];

        foreach ( ProductChild::query()->where( 'parent_product_id', $product->getKey() )->with( [ 'product', 'variant' ] )->orderBy( 'position' )->orderBy( 'id' )->get() as $child ) {
            $member = $child->variant ?? $child->product;

            if ( null === $member || ( null !== $child->product && ! $child->product->typeIsMissing() && ! $child->product->productType()->isInventoryTracked() ) ) {
                continue;
            }

            $components[] = [ 'stockable' => $member, 'quantity' => max( 1, (int) $child->quantity ) * $quantity ];
        }

        return $components;
    }
}
