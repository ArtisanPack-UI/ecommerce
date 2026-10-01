<?php

/**
 * Grouped product type.
 *
 * A storefront listing of related products ("prints in three sizes"). The
 * group itself is never sold: shoppers add its children to the cart one by
 * one. Children are stored in `product_children` (engine spec §3.10a).
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

use ArtisanPackUI\Ecommerce\Models\Product;
use InvalidArgumentException;

/**
 * `grouped` product type.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class GroupedProductType extends AbstractProductType
{
    /**
     * Registry key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'grouped';

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
        return __( 'Grouped product' );
    }

    /**
     * @since 1.0.0
     *
     * @return string|null
     */
    public function icon(): ?string
    {
        return 'hero-rectangle-group';
    }

    /**
     * The group ships nothing itself; its children do.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function requiresFulfillment(): bool
    {
        return false;
    }

    /**
     * The group has no stock of its own.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isInventoryTracked(): bool
    {
        return false;
    }

    /**
     * A grouped product cannot be added to a cart.
     *
     * @since 1.0.0
     *
     * @param  Product               $product  Product.
     * @param  array<string, mixed>  $options  Raw options.
     *
     * @throws InvalidArgumentException Always.
     *
     * @return array<string, mixed>
     */
    public function validateCartOptions( Product $product, array $options ): array
    {
        throw new InvalidArgumentException( 'A grouped product is not sold on its own; add its products individually.' );
    }
}
