<?php

/**
 * TieredDiscountAction.
 *
 * `tiered-discount`: picks the highest tier whose `min_subtotal` the
 * remaining subtotal meets and applies its `percent` or fixed `amount`
 * off the cart. Config:
 * `{ tiers: [{ min_subtotal: int|map, percent?: number, amount?: int|map }] }`.
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
class TieredDiscountAction extends AbstractPromotionAction
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'tiered-discount';

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
        return __( 'Tiered discount' );
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
        $subtotal = $ledger->remainingSubtotal();
        $best     = null;
        $floor    = null;

        foreach ( (array) ( $config['tiers'] ?? [] ) as $tier ) {
            if ( ! is_array( $tier ) ) {
                continue;
            }

            $min = $this->converter->fromConfigured( $tier['min_subtotal'] ?? 0, $ledger->currency()->getCode() );

            if ( null === $min || $subtotal->lessThan( $min ) ) {
                continue;
            }

            if ( null === $floor || $min->greaterThan( $floor ) ) {
                $best  = $tier;
                $floor = $min;
            }
        }

        if ( null === $best ) {
            return;
        }

        $fraction = $this->fraction( $best['percent'] ?? null );

        if ( null !== $fraction ) {
            $ledger->discountCart( $subtotal->multiply( $fraction ) );

            return;
        }

        $amount = $this->amount( $best['amount'] ?? null, $ledger );

        if ( null !== $amount ) {
            $ledger->discountCart( $amount );
        }
    }
}
