<?php

/**
 * CartContainsProductCondition.
 *
 * `cart-contains-product`: passes when the cart holds at least
 * `min_quantity` (default 1) units of the listed products / variants.
 * Config:
 *
 * - `product_ids`  — product ids to match.
 * - `variant_ids`  — optional variant ids to match.
 * - `min_quantity` — minimum matching units (default 1).
 * - `match`        — `any` (default: total matching units across the list)
 *                    or `all` (every listed product present at `min_quantity`).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Promotions\Conditions;

use ArtisanPackUI\Ecommerce\Contracts\DescribesConfig;
use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Support\ConfigField;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CartContainsProductCondition implements PromotionCondition, DescribesConfig
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'cart-contains-product';

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
        return __( 'Cart contains product' );
    }

    /**
     * Fields this condition's `config` takes (engine issue #149).
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [
            ConfigField::make( 'product_ids', 'product', __( 'Products' ), [ 'multiple' => true ] ),
            ConfigField::make( 'variant_ids', 'variant', __( 'Variants' ), [ 'multiple' => true ] ),
            ConfigField::make( 'match', 'select', __( 'Match' ), [ 'options' => ConfigField::options( [ 'any' => __( 'Any of them' ), 'all' => __( 'All of them' ) ] ), 'default' => 'any' ] ),
            ConfigField::make( 'min_quantity', 'number', __( 'Minimum quantity' ), [ 'rules' => [ 'integer', 'min:1' ], 'default' => 1 ] ),
        ];
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart.
     * @param  array<string, mixed>  $config  See class docblock.
     *
     * @return bool
     */
    public function evaluate( Cart $cart, array $config ): bool
    {
        $productIds  = array_map( 'intval', array_filter( (array) ( $config['product_ids'] ?? [] ), 'is_numeric' ) );
        $variantIds  = array_map( 'intval', array_filter( (array) ( $config['variant_ids'] ?? [] ), 'is_numeric' ) );
        $minQuantity = max( 1, (int) ( $config['min_quantity'] ?? 1 ) );

        if ( [] === $productIds && [] === $variantIds ) {
            return false;
        }

        $items = $cart->relationLoaded( 'items' ) ? $cart->items : $cart->items()->get();

        if ( 'all' === ( $config['match'] ?? 'any' ) ) {
            foreach ( $productIds as $productId ) {
                if ( $items->where( 'product_id', $productId )->sum( 'quantity' ) < $minQuantity ) {
                    return false;
                }
            }

            foreach ( $variantIds as $variantId ) {
                if ( $items->where( 'product_variant_id', $variantId )->sum( 'quantity' ) < $minQuantity ) {
                    return false;
                }
            }

            return true;
        }

        $matching = $items
            ->filter( static fn ( CartItem $item ): bool => in_array( (int) $item->product_id, $productIds, true )
                || ( null !== $item->product_variant_id && in_array( (int) $item->product_variant_id, $variantIds, true ) ) )
            ->sum( 'quantity' );

        return $matching >= $minQuantity;
    }
}
