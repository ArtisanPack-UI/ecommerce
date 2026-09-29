<?php

/**
 * AddFreeItemAction.
 *
 * `add-free-item`: gives `quantity` units of a product away. Units
 * already in the cart are discounted to zero first; any shortfall is
 * recorded on {@see DiscountLedger::freeItems()} for checkout to add.
 * Config: `{ product_id: int, variant_id?: int, quantity?: int }`.
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
class AddFreeItemAction extends AbstractPromotionAction
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'add-free-item';

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
        return __( 'Add free item' );
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
        if ( ! is_numeric( $config['product_id'] ?? null ) ) {
            return;
        }

        $productId = (int) $config['product_id'];
        $variantId = is_numeric( $config['variant_id'] ?? null ) ? (int) $config['variant_id'] : null;
        $quantity  = max( 1, (int) ( $config['quantity'] ?? 1 ) );

        foreach ( $ledger->lines() as $line ) {
            if ( 0 === $quantity ) {
                break;
            }

            if ( $line['product_id'] !== $productId || ( null !== $variantId && $line['variant_id'] !== $variantId ) ) {
                continue;
            }

            $units     = min( $quantity, $line['quantity'] );
            $quantity -= $units;

            $ledger->discountLine( $line['id'], $line['unit_price']->multiply( $units ) );
        }

        $ledger->addFreeItem( $productId, $variantId, $quantity );
    }
}
