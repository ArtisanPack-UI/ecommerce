<?php

/**
 * CartContainsProductTypeCondition.
 *
 * `cart-contains-product-type`: matches on the product types
 * (`simple`, `digital`, a satellite's `printful:printful-product`, …) of
 * the lines in a cart — or, for kanban board routing, a placed order.
 * Config:
 *
 * - `types` — product type registry keys to match.
 * - `match` — `any` (default: at least one line has a listed type),
 *             `all` (every listed type appears on some line), or
 *             `only` (every line has a listed type — e.g. "digital-only
 *             orders go to the Downloads board").
 *
 * A line whose product was deleted is judged by the type recorded in its
 * order snapshot.
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
use ArtisanPackUI\Ecommerce\Contracts\OrderAwarePromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use ArtisanPackUI\Ecommerce\Support\ConfigField;
use Closure;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CartContainsProductTypeCondition implements OrderAwarePromotionCondition, DescribesConfig
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'cart-contains-product-type';

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
        return __( 'Contains product type' );
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
        $registry = app( ProductTypeRegistry::class );
        $types    = [];

        foreach ( $registry->keys() as $key ) {
            $label         = $registry->meta( $key )['label'] ?? $key;
            $types[ $key ] = (string) ( $label instanceof Closure ? $label() : $label );
        }

        return [
            ConfigField::make( 'types', 'multiselect', __( 'Product types' ), [ 'required' => true, 'options' => ConfigField::options( $types ) ] ),
            ConfigField::make( 'match', 'select', __( 'Match' ), [
                'options' => ConfigField::options( [ 'any' => __( 'Any of them' ), 'all' => __( 'All of them' ), 'only' => __( 'Only these' ) ] ),
                'default' => 'any',
            ] ),
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
        $items = $cart->relationLoaded( 'items' ) ? $cart->items : $cart->items()->with( 'product' )->get();

        return $this->matches(
            $items->map( static fn ( CartItem $item ): ?string => $item->product?->type )->all(),
            $config,
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order                 $order   Placed order.
     * @param  array<string, mixed>  $config  See class docblock.
     *
     * @return bool
     */
    public function evaluateOrder( Order $order, array $config ): bool
    {
        $items = $order->relationLoaded( 'items' ) ? $order->items : $order->items()->with( 'product' )->get();

        return $this->matches(
            $items->map( static fn ( OrderItem $item ): ?string => $item->product?->type ?? ( $item->product_snapshot['type'] ?? null ) )->all(),
            $config,
        );
    }

    /**
     * Applies the `match` mode to the line types.
     *
     * @since 1.0.0
     *
     * @param  array<int, string|null>  $lineTypes  Product type per line.
     * @param  array<string, mixed>     $config     Condition config.
     *
     * @return bool
     */
    protected function matches( array $lineTypes, array $config ): bool
    {
        $wanted = array_values( array_unique( array_filter( (array) ( $config['types'] ?? [] ), 'is_string' ) ) );

        if ( [] === $wanted || [] === $lineTypes ) {
            return false;
        }

        $present = array_values( array_unique( array_filter( $lineTypes, 'is_string' ) ) );

        return match ( $config['match'] ?? 'any' ) {
            'any'   => [] !== array_intersect( $wanted, $present ),
            'all'   => [] === array_diff( $wanted, $present ),
            'only'  => [] === array_filter( $lineTypes, static fn ( ?string $type ): bool => ! in_array( $type, $wanted, true ) ),
            default => false,
        };
    }
}
