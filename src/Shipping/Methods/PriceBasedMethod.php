<?php

/**
 * PriceBasedMethod.
 *
 * `price-based` driver. Config:
 *
 * - `tiers` — list of `{ min_subtotal: int|map, amount: int|map }`. The
 *             tier with the highest `min_subtotal` the cart subtotal meets
 *             wins. Below every tier → method unavailable.
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
class PriceBasedMethod extends AbstractShippingMethod
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'price-based';

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
        return __( 'Price-based' );
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
            ConfigField::make( 'tiers', 'repeater', __( 'Tiers' ), [
                'required' => true,
                'help'     => __( 'The highest tier the subtotal reaches applies.' ),
                'fields'   => [
                    ConfigField::make( 'min_subtotal', 'money', __( 'Minimum subtotal' ), [ 'default' => 0 ] ),
                    ConfigField::make( 'amount', 'money', __( 'Rate' ), [ 'required' => true ] ),
                ],
            ] ),
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

        $subtotal  = $this->subtotal( $cart );
        $best      = null;
        $bestFloor = null;

        foreach ( (array) ( $config['tiers'] ?? [] ) as $tier ) {
            if ( ! is_array( $tier ) || ! array_key_exists( 'amount', $tier ) ) {
                continue;
            }

            $floor = $this->configuredAmount( $tier['min_subtotal'] ?? 0, $cart );

            if ( null === $floor || $subtotal->lessThan( $floor ) ) {
                continue;
            }

            if ( null === $bestFloor || $floor->greaterThan( $bestFloor ) ) {
                $best      = $tier;
                $bestFloor = $floor;
            }
        }

        if ( null === $best ) {
            return null;
        }

        $amount = $this->configuredAmount( $best['amount'], $cart );

        return ( null === $amount || $amount->isNegative() ) ? null : $amount;
    }
}
