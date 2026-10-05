<?php

/**
 * ProvidesStorefrontOptions.
 *
 * Optional on a {@see ProductType} (#179): describes the inputs a
 * storefront renders on the add-to-cart form, framework-agnostically, so
 * configurable, subscription, grouped, and bundled products work in every
 * storefront family.
 *
 * Each field: `type` (`text`, `textarea`, `number`, `quantity`, `select`,
 * `radio`, `checkbox`, `info`), `name` (the cart option key, dot or bracket
 * notation), `label`, and optionally `options` (`[ { value, label } ]`),
 * `rules` (Laravel validation rules the engine enforces), `required`,
 * `default`, `help`, and `meta` (type-specific extras, such as a child's
 * `product_id`).
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
interface ProvidesStorefrontOptions
{
    /**
     * The add-to-cart fields for `$product`.
     *
     * @since 1.0.0
     *
     * @param  Product  $product  Product.
     *
     * @return array<int, array<string, mixed>>
     */
    public function storefrontOptions( Product $product ): array;
}
