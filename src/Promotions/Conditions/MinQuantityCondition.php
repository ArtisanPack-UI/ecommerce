<?php

/**
 * MinQuantityCondition.
 *
 * `min-quantity` (audit I3): the cart holds at least `quantity` units —
 * of the listed products or variants, or of anything when none are listed.
 * Free items a promotion added don't count.
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
class MinQuantityCondition implements OrderAwarePromotionCondition, DescribesConfig
{
    use ReadsLineProducts;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'min-quantity';

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
        return __( 'Minimum quantity' );
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
            ConfigField::make( 'quantity', 'number', __( 'Minimum quantity' ), [ 'required' => true, 'rules' => [ 'integer', 'min:1' ] ] ),
            ConfigField::make( 'product_ids', 'product', __( 'Products' ), [ 'multiple' => true, 'help' => __( 'Count only these products. Leave empty to count everything.' ) ] ),
            ConfigField::make( 'variant_ids', 'variant', __( 'Variants' ), [ 'multiple' => true ] ),
        ];
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart.
     * @param  array<string, mixed>  $config  `{ quantity: int, product_ids?: int[], variant_ids?: int[] }`.
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
     * @param  array<string, mixed>  $config  See {@see self::evaluate()}.
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
        $minimum = $config['quantity'] ?? null;

        if ( ! is_numeric( $minimum ) || (int) $minimum < 1 ) {
            return false;
        }

        $products = self::ids( $config['product_ids'] ?? [] );
        $variants = self::ids( $config['variant_ids'] ?? [] );
        $counted  = 0;

        foreach ( $lines as $line ) {
            $listed = ( [] === $products && [] === $variants )
                || in_array( $line['product_id'], $products, true )
                || ( null !== $line['variant_id'] && in_array( $line['variant_id'], $variants, true ) );

            if ( $listed ) {
                $counted += $line['quantity'];
            }
        }

        return $counted >= (int) $minimum;
    }
}
