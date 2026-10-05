<?php

/**
 * Variable product type.
 *
 * A product sold through its variants (size × colour, …). Each variant has
 * its own SKU, prices, and stock, so a cart line must name the variant.
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
 * `variable` product type.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class VariableProductType extends AbstractProductType
{
    /**
     * Registry key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'variable';

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
        return __( 'Variable product' );
    }

    /**
     * @since 1.0.0
     *
     * @return string|null
     */
    public function icon(): ?string
    {
        return 'hero-squares-2x2';
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
     * A variable product is only sold as one of its variants.
     *
     * @since 1.0.0
     *
     * @param  Product               $product  Product.
     * @param  array<string, mixed>  $options  Raw options.
     *
     * @throws InvalidArgumentException When no variant is chosen.
     *
     * @return array<string, mixed>
     */
    public function validateCartOptions( Product $product, array $options ): array
    {
        $out = parent::validateCartOptions( $product, $options );

        if ( ! isset( $out['variant_id'] ) ) {
            throw new InvalidArgumentException( 'A variable product needs a variant_id.' );
        }

        return $out;
    }
}
