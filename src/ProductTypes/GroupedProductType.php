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

use ArtisanPackUI\Ecommerce\Contracts\ProvidesStorefrontOptions;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductChild;
use InvalidArgumentException;

/**
 * `grouped` product type.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class GroupedProductType extends AbstractProductType implements ProvidesStorefrontOptions
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

    /**
     * One quantity field per child: the storefront adds each chosen child
     * to the cart as its own line (#179).
     *
     * @since 1.0.0
     *
     * @param  Product  $product  Grouped product.
     *
     * @return array<int, array<string, mixed>>
     */
    public function storefrontOptions( Product $product ): array
    {
        return $product->children()->with( [ 'product', 'variant' ] )->get()
            ->filter( static fn ( ProductChild $child ): bool => null !== $child->product && 'active' === $child->product->status )
            ->map( static fn ( ProductChild $child ): array => [
                'type'    => 'quantity',
                'name'    => 'items.' . $child->id,
                'label'   => (string) ( $child->variant?->name ? $child->product->name . ' — ' . $child->variant->name : $child->product->name ),
                'default' => 0,
                'rules'   => [ 'integer', 'min:0' ],
                'meta'    => [ 'product_id' => (int) $child->child_product_id, 'variant_id' => null === $child->child_variant_id ? null : (int) $child->child_variant_id, 'add_separately' => true ],
            ] )
            ->values()
            ->all();
    }
}
