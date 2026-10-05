<?php

/**
 * FixedOffProductAction.
 *
 * `fixed-off-product` (audit I3): takes a fixed amount off each matching
 * line — per unit (default) or once per line — never more than the line
 * has left. Config `{ amount: int|map, product_ids?: int[],
 * variant_ids?: int[], per?: unit|line }`; the amount is in the base
 * currency (converted) or a per-currency map.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Promotions\Actions;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Support\ConfigField;
use ArtisanPackUI\Ecommerce\Support\DiscountLedger;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class FixedOffProductAction extends AbstractPromotionAction
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'fixed-off-product';

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
        return __( 'Fixed amount off products' );
    }

    /**
     * Fields this action's `config` takes.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [
            ConfigField::make( 'amount', 'money', __( 'Amount off' ), [ 'required' => true ] ),
            ConfigField::make( 'product_ids', 'product', __( 'Products' ), [ 'multiple' => true ] ),
            ConfigField::make( 'variant_ids', 'variant', __( 'Variants' ), [ 'multiple' => true ] ),
            ConfigField::make( 'per', 'select', __( 'Applies' ), [
                'options' => ConfigField::options( [ 'unit' => __( 'To each unit' ), 'line' => __( 'Once per line' ) ] ),
                'default' => 'unit',
            ] ),
        ];
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart.
     * @param  DiscountLedger        $ledger  Running ledger.
     * @param  array<string, mixed>  $config  See the class docblock.
     *
     * @return void
     */
    public function apply( Cart $cart, DiscountLedger $ledger, array $config ): void
    {
        $amount = $this->amount( $config['amount'] ?? null, $ledger );
        $per    = $config['per'] ?? 'unit';

        if ( null === $amount || ! in_array( $per, [ 'unit', 'line' ], true ) ) {
            return;
        }

        foreach ( $this->matchingLines( $ledger, $config ) as $line ) {
            $ledger->discountLine( $line['id'], 'unit' === $per ? $amount->multiply( (string) max( 0, $line['quantity'] ) ) : $amount );
        }
    }
}
