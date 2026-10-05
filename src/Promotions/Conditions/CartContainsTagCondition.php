<?php

/**
 * CartContainsTagCondition.
 *
 * `cart-contains-tag` (audit I3): the cart holds a product with any (or
 * all) of the listed tags. Config `{ tag_ids: int[], match?: any|all }`.
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
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\Concerns\ReadsLineProducts;
use ArtisanPackUI\Ecommerce\Support\ConfigField;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CartContainsTagCondition implements OrderAwarePromotionCondition, DescribesConfig
{
    use ReadsLineProducts;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'cart-contains-tag';

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
        return __( 'Contains tag' );
    }

    /**
     * Fields this condition's `config` takes.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [
            ConfigField::make( 'tag_ids', 'tag', __( 'Tags' ), [ 'required' => true, 'multiple' => true ] ),
            ConfigField::make( 'match', 'select', __( 'Match' ), [
                'options' => ConfigField::options( [ 'any' => __( 'Any of them' ), 'all' => __( 'All of them' ) ] ),
                'default' => 'any',
            ] ),
        ];
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart.
     * @param  array<string, mixed>  $config  See the class docblock.
     *
     * @return bool
     */
    public function evaluate( Cart $cart, array $config ): bool
    {
        return $this->matches( $this->cartLines( $cart ), $config );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order                 $order   Placed order.
     * @param  array<string, mixed>  $config  See the class docblock.
     *
     * @return bool
     */
    public function evaluateOrder( Order $order, array $config ): bool
    {
        return $this->matches( $this->orderLines( $order ), $config );
    }

    /**
     * @since 1.0.0
     *
     * @param  array<int, array{product_id: int|null, variant_id: int|null, quantity: int}>  $lines   Paid lines.
     * @param  array<string, mixed>                                                          $config  Config.
     *
     * @return bool
     */
    protected function matches( array $lines, array $config ): bool
    {
        $wanted = self::ids( $config['tag_ids'] ?? [] );

        if ( [] === $wanted || [] === $lines ) {
            return false;
        }

        $present = $this->relatedIds( array_column( $lines, 'product_id' ), 'tags' );

        $groups = array_map( static fn ( int $id ): array => [ $id ], $wanted );
        $hits   = array_filter( $groups, static fn ( array $group ): bool => [] !== array_intersect( $group, $present ) );

        return match ( $config['match'] ?? 'any' ) {
            'any'   => [] !== $hits,
            'all'   => count( $hits ) === count( $groups ),
            default => false,
        };
    }
}
