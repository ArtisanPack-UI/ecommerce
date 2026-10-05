<?php

/**
 * LocalPickupMethod.
 *
 * `local-pickup` driver. Config:
 *
 * - `amount`       — optional handling charge (default free).
 * - `location`     — pickup location name / address shown to the customer.
 * - `instructions` — optional pickup instructions.
 *
 * Shipments created with this method get a QR handoff code — see
 * {@see \ArtisanPackUI\Ecommerce\Shipping\LocalPickupHandoff}.
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
class LocalPickupMethod extends AbstractShippingMethod
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'local-pickup';

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
        return __( 'Local pickup' );
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
            ConfigField::make( 'amount', 'money', __( 'Pickup fee' ) ),
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

        if ( ! isset( $config['amount'] ) ) {
            return $this->zero( $cart );
        }

        $amount = $this->configuredAmount( $config['amount'], $cart );

        return ( null === $amount || $amount->isNegative() ) ? null : $amount;
    }
}
