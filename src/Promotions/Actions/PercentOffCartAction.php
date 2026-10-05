<?php

/**
 * PercentOffCartAction.
 *
 * `percent-off-cart`: takes `percent` (0–100) off whatever subtotal
 * earlier promotions left. Config: `{ percent: number }`.
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
class PercentOffCartAction extends AbstractPromotionAction
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'percent-off-cart';

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
        return __( 'Percent off cart' );
    }

    /**
     * Fields this action's `config` takes (engine issue #149).
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [
            ConfigField::make( 'percent', 'percent', __( 'Percent off' ), [ 'required' => true ] ),
        ];
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart.
     * @param  DiscountLedger        $ledger  Running ledger.
     * @param  array<string, mixed>  $config  See class docblock.
     *
     * @return void
     */
    public function apply( Cart $cart, DiscountLedger $ledger, array $config ): void
    {
        $fraction = $this->fraction( $config['percent'] ?? null );

        if ( null === $fraction ) {
            return;
        }

        $ledger->discountCart( $ledger->remainingSubtotal()->multiply( $fraction ) );
    }
}
