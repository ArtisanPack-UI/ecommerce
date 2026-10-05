<?php

/**
 * ProductViews.
 *
 * Fires `ap.ecommerce.product.viewed` `(Product $product, ?Customer
 * $customer)` (#179). Storefronts call {@see self::record()} when a product
 * page is shown (REST storefronts post to `products/{product}/views`) so
 * satellites such as recently-viewed hear about views from every
 * storefront. The engine stores nothing itself.
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

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Product;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ProductViews
{
    /**
     * @since 1.0.0
     *
     * @param  Product        $product   Viewed product.
     * @param  Customer|null  $customer  Viewer, when signed in.
     *
     * @return void
     */
    public static function record( Product $product, ?Customer $customer = null ): void
    {
        doAction( 'ap.ecommerce.product.viewed', $product, $customer );
    }
}
