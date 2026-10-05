<?php

/**
 * VariantResolver.
 *
 * Maps a shopper's attribute choices to a variant, and describes every
 * combination a variation picker offers (#172): its attribute values,
 * variant, availability, price, and image.
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

use ArtisanPackUI\Ecommerce\Inventory\StockStatus;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\ProductVariantOptionValue;
use ArtisanPackUI\Ecommerce\Pricing\PriceDisplayResolver;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class VariantResolver
{
    /**
     * @since 1.0.0
     *
     * @param  PriceDisplayResolver  $prices  Display prices.
     */
    public function __construct( private readonly PriceDisplayResolver $prices )
    {
    }

    /**
     * The variant of `$product` whose option values are exactly
     * `$attributeValueIds` (in any order), or null.
     *
     * @since 1.0.0
     *
     * @param  Product          $product            Product.
     * @param  array<int, int>  $attributeValueIds  One chosen value per attribute.
     *
     * @return ProductVariant|null
     */
    public function match( Product $product, array $attributeValueIds ): ?ProductVariant
    {
        $wanted = array_values( array_unique( array_map( 'intval', $attributeValueIds ) ) );
        sort( $wanted );

        if ( [] === $wanted ) {
            return null;
        }

        foreach ( $this->combinations( $product ) as $variantId => $values ) {
            if ( $values === $wanted ) {
                return $product->variants()->whereKey( $variantId )->first();
            }
        }

        return null;
    }

    /**
     * Every variant of `$product` as a picker row.
     *
     * @since 1.0.0
     *
     * @param  Product  $product   Product.
     * @param  string   $currency  Currency for prices.
     *
     * @return array<int, array{variant_id: int, sku: string|null, attribute_value_ids: array<int, int>, available: bool, stock: array<string, mixed>, price: array<string, mixed>|null, image_media_id: int|null}>
     */
    public function matrix( Product $product, string $currency ): array
    {
        $combinations = $this->combinations( $product );
        $rows         = [];

        foreach ( $product->variants()->orderBy( 'position' )->orderBy( 'id' )->get() as $variant ) {
            $stock = StockStatus::for( $variant->setRelation( 'product', $product ) );
            $price = $this->prices->for( $variant, $currency );

            $rows[] = [
                'variant_id'          => (int) $variant->id,
                'sku'                 => $variant->sku,
                'attribute_value_ids' => $combinations[ (int) $variant->id ] ?? [],
                'available'           => $stock->purchasable() && null !== $price,
                'stock'               => $stock->toArray(),
                'price'               => $price?->toArray(),
                'image_media_id'      => null === $variant->image_media_id ? null : (int) $variant->image_media_id,
            ];
        }

        return $rows;
    }

    /**
     * Each variant's sorted attribute value ids.
     *
     * @since 1.0.0
     *
     * @param  Product  $product  Product.
     *
     * @return array<int, array<int, int>>
     */
    protected function combinations( Product $product ): array
    {
        $combinations = [];

        ProductVariantOptionValue::query()
            ->whereIn( 'product_variant_id', $product->variants()->select( 'id' ) )
            ->get( [ 'product_variant_id', 'product_attribute_value_id' ] )
            ->each( function ( ProductVariantOptionValue $row ) use ( &$combinations ): void {
                $combinations[ (int) $row->product_variant_id ][] = (int) $row->product_attribute_value_id;
            } );

        return array_map( static function ( array $values ): array {
            sort( $values );

            return $values;
        }, $combinations );
    }
}
