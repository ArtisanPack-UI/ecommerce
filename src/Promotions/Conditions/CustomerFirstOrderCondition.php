<?php

/**
 * CustomerFirstOrderCondition.
 *
 * `customer-first-order`: passes when the shopper has no prior order
 * (failed orders don't count). The shopper is identified by the cart's
 * customer, falling back to the cart email. A cart with neither passes —
 * the identity isn't known yet — so checkout MUST re-evaluate promotions
 * once the email is captured, before the order is placed.
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

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerFirstOrderCondition implements PromotionCondition
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'customer-first-order';

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
        return __( "Customer's first order" );
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart.
     * @param  array<string, mixed>  $config  Unused.
     *
     * @return bool
     */
    public function evaluate( Cart $cart, array $config ): bool
    {
        $email = null === $cart->email ? '' : strtolower( trim( (string) $cart->email ) );

        if ( null === $cart->customer_id && '' === $email ) {
            return true;
        }

        return ! Order::query()
            ->where( 'system_status', '!=', 'failed' )
            ->where( function ( $query ) use ( $cart, $email ): void {
                if ( null !== $cart->customer_id ) {
                    $query->orWhere( 'customer_id', $cart->customer_id );
                }

                if ( '' !== $email ) {
                    $query->orWhereRaw( 'LOWER(email) = ?', [ $email ] );
                }
            } )
            ->exists();
    }
}
