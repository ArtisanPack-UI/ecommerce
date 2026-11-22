<?php

/**
 * DescribesLineOptions.
 *
 * Optional on a {@see ProductType} (#222): turns a line's stored options
 * (`cart_items.options`, or the options in an order line's snapshot) into
 * human-readable rows ("Size: 16 × 20 in", "Paper: Lustre"), because only
 * the type knows what its option keys and values mean. Carts, orders,
 * emails, the REST and GraphQL APIs, and every storefront and admin family
 * show these rows through {@see \ArtisanPackUI\Ecommerce\Support\LineOptionDescriber}.
 *
 * Types that don't implement it get the default description: the variant
 * name, then each scalar option with its key headlined.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Product;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.4.0
 */
interface DescribesLineOptions
{
    /**
     * The rows describing a line of `$product` with `$options`, in display
     * order. Labels and values are already translated. A row may have an
     * empty `label` (e.g. a variant name shown on its own); rows with an
     * empty `value` are dropped.
     *
     * Called at placement time to copy the rows into the order line's
     * snapshot, so the wording a customer bought with never changes.
     *
     * @since 1.4.0
     *
     * @param  Product               $product  Product the line is for.
     * @param  array<string, mixed>  $options  Sanitised line options (as `validateCartOptions()` returned them, plus `variant_id` when the line has one).
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function describeLineOptions( Product $product, array $options ): array;
}
