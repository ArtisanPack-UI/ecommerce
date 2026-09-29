<?php

/**
 * FixedOffCartAction.
 *
 * `fixed-off-cart`: takes a fixed `amount` off the cart, spread across
 * lines and capped at the remaining subtotal. Config: `{ amount: int|map }`.
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
use ArtisanPackUI\Ecommerce\Support\DiscountLedger;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class FixedOffCartAction extends AbstractPromotionAction
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'fixed-off-cart';

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
        return __( 'Fixed amount off cart' );
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
        $amount = $this->amount( $config['amount'] ?? null, $ledger );

        if ( null !== $amount ) {
            $ledger->discountCart( $amount );
        }
    }
}
