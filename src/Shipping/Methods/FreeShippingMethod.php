<?php

/**
 * FreeShippingMethod.
 *
 * `free-shipping` driver. Config:
 *
 * - `min_subtotal` — optional threshold (base-currency int or currency
 *                    map); the method is unavailable below it.
 *
 * Promotion-driven free shipping (the `free-shipping` promotion action)
 * is separate — it zeroes the chosen rate via the discount ledger.
 *
 * Parent plan §5.10.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Shipping\Methods;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class FreeShippingMethod extends AbstractShippingMethod
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'free-shipping';

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
        return __( 'Free shipping' );
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart         Cart.
     * @param  Address               $destination  Destination.
     * @param  array<string, mixed>  $config       Method config.
     *
     * @return Money|null
     */
    public function calculate( Cart $cart, Address $destination, array $config ): ?Money
    {
        if ( $this->items( $cart )->isEmpty() ) {
            return null;
        }

        if ( isset( $config['min_subtotal'] ) ) {
            $threshold = $this->configuredAmount( $config['min_subtotal'], $cart );

            if ( null === $threshold || $this->subtotal( $cart )->lessThan( $threshold ) ) {
                return null;
            }
        }

        return $this->zero( $cart );
    }
}
