<?php

/**
 * FlatRateMethod.
 *
 * `flat-rate` driver. Config:
 *
 * - `amount`          — base charge (base-currency int or currency map).
 * - `per_item_amount` — optional extra charge per unit in the cart.
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
use ArtisanPackUI\Ecommerce\Support\ConfigField;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class FlatRateMethod extends AbstractShippingMethod
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'flat-rate';

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
        return __( 'Flat rate' );
    }

    /**
     * Fields this shipping method type's `config` takes (engine issue #149).
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [
            ConfigField::make( 'amount', 'money', __( 'Rate' ), [ 'default' => 0 ] ),
            ConfigField::make( 'per_item_amount', 'money', __( 'Extra per item' ) ),
        ];
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

        $amount = $this->configuredAmount( $config['amount'] ?? 0, $cart );

        if ( null === $amount ) {
            return null;
        }

        if ( isset( $config['per_item_amount'] ) ) {
            $perItem = $this->configuredAmount( $config['per_item_amount'], $cart );

            if ( null !== $perItem ) {
                $amount = $amount->add( $perItem->multiply( (int) $this->items( $cart )->sum( 'quantity' ) ) );
            }
        }

        return $amount->isNegative() ? null : $amount;
    }
}
