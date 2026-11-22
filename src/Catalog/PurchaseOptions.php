<?php

/**
 * PurchaseOptions.
 *
 * What a product page needs to sell a product (#172, #223), shared by
 * `GET products/{product}/purchase-options` and the GraphQL
 * `productPurchaseOptions` query: the display price, stock status, the
 * variant matrix, and the product type's add-to-cart fields, normalized
 * through {@see StorefrontOptionSchema} so a malformed field is dropped
 * (with a logged warning) instead of breaking the page.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Catalog;

use ArtisanPackUI\Ecommerce\Contracts\ProvidesStorefrontOptions;
use ArtisanPackUI\Ecommerce\Inventory\StockStatus;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Pricing\PriceDisplayResolver;
use ArtisanPackUI\Ecommerce\Support\StorefrontOptionSchema;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.4.0
 */
class PurchaseOptions
{
    /**
     * @since 1.4.0
     *
     * @param  StorefrontOptionSchema  $schema  Field validator.
     */
    public function __construct( protected StorefrontOptionSchema $schema )
    {
    }

    /**
     * The purchase options of `$product` in `$currency`.
     *
     * @since 1.4.0
     *
     * @param  Product       $product      Product (already known to be storefront-visible).
     * @param  string        $currency     ISO 4217 code.
     * @param  Address|null  $destination  Where tax is estimated for (the store's country when null).
     *
     * @return array{product_id: int, currency: string, price: array<string, mixed>|null, stock: array<string, mixed>, variants: array<int, array<string, mixed>>, options: array<int, array<string, mixed>>}
     */
    public function for( Product $product, string $currency, ?Address $destination = null ): array
    {
        $currency = strtoupper( $currency );

        return [
            'product_id' => (int) $product->id,
            'currency'   => $currency,
            'price'      => app( PriceDisplayResolver::class )->for( $product, $currency, $destination )?->toArray(),
            'stock'      => StockStatus::for( $product )->toArray(),
            'variants'   => app( VariantResolver::class )->matrix( $product, $currency ),
            'options'    => $this->fields( $product ),
        ];
    }

    /**
     * The product type's add-to-cart fields, normalized.
     *
     * @since 1.4.0
     *
     * @param  Product  $product  Product.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fields( Product $product ): array
    {
        if ( $product->typeIsMissing() ) {
            return [];
        }

        $type = $product->productType();

        return $type instanceof ProvidesStorefrontOptions ? $this->schema->normalize( $type->storefrontOptions( $product ) ) : [];
    }
}
