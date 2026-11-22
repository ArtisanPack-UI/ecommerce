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
 * `radio`, `checkbox`, `info`, `swatch`), `name` (the cart option key, dot
 * or bracket notation), `label`, and optionally `options`, `rules`
 * (Laravel validation rules the engine enforces), `required`, `default`,
 * `help`, `visible_when`, and `meta` (type-specific extras, such as a
 * child's `product_id`).
 *
 * Since 1.4.0 (#223):
 *
 * - each entry in `options` is `{ value, label }` plus, optionally,
 *   `price_delta` (`{ amount, currency }`, minor units), `image`
 *   (`{ url, srcset?, alt }`), `swatch` (`{ hex?, image_url?, alt }`),
 *   `hint`, and `disabled` (bool);
 * - `swatch` is a single choice shown as swatches (storefronts that don't
 *   support it render a `radio`);
 * - `visible_when` (`[ { field, in: [values] } ]`, all must match) shows a
 *   field only for certain values of others. Hidden fields are not
 *   validated and their values are dropped: call
 *   {@see \ArtisanPackUI\Ecommerce\Support\StorefrontOptionSchema::validate()}
 *   (or `stripHidden()` and `rules()`) from `validateCartOptions()`;
 * - `meta.disables` (`{ value: { field: [values] } }`) lets storefronts
 *   disable conflicting choices client-side; server validation stays
 *   authoritative.
 *
 * The engine passes the fields through `StorefrontOptionSchema::normalize()`
 * before serving them, which drops malformed fields and options with a
 * logged warning.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 * @since      1.4.0 Option metadata, the `swatch` type, `visible_when`, and `meta.disables`.
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
