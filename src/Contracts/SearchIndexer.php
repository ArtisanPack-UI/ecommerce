<?php

/**
 * SearchIndexer.
 *
 * Engine spec §4.15: a custom search transport kept in step with the
 * catalog alongside Laravel Scout (#176). Registered indexers receive every
 * storefront product change.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Product;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface SearchIndexer
{
    /**
     * Registry key.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string;

    /**
     * Adds or updates `$products` in the index.
     *
     * @since 1.0.0
     *
     * @param  iterable<Product>  $products  Products.
     *
     * @return void
     */
    public function indexMany( iterable $products ): void;

    /**
     * Removes `$product` from the index.
     *
     * @since 1.0.0
     *
     * @param  Product  $product  Product.
     *
     * @return void
     */
    public function delete( Product $product ): void;

    /**
     * Empties the index.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function flush(): void;
}
