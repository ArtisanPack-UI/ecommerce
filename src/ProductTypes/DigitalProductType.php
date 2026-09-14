<?php

/**
 * DigitalProductType.
 *
 * The reference implementation of a downloadable product: no shipment, no
 * inventory tracking, post-placement side-effects mint download tokens.
 * Engine spec §4.1, §5 registry entry `digital`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\ProductTypes;

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;

/**
 * The `digital` product type.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DigitalProductType extends AbstractProductType
{
    /**
     * Registry key.
     *
     * @since 1.0.0
     */
    public const KEY = 'digital';

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
        return __( 'Digital product' );
    }

    /**
     * @since 1.0.0
     *
     * @return string|null
     */
    public function icon(): ?string
    {
        return 'hero-arrow-down-tray';
    }

    /**
     * Digital goods never enter the fulfillment pipeline.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function requiresFulfillment(): bool
    {
        return false;
    }

    /**
     * Digital goods have unbounded supply — inventory tracking is off.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isInventoryTracked(): bool
    {
        return false;
    }

    /**
     * Extends the default cart-option payload with the download-license
     * type when the caller supplied one. Anything else is ignored so
     * request bodies cannot smuggle arbitrary payload into
     * `cart_items.options`.
     *
     * @since 1.0.0
     *
     * @param  Product              $product  Product being added.
     * @param  array<string, mixed> $options  Raw options.
     *
     * @return array<string, mixed>
     */
    public function validateCartOptions( Product $product, array $options ): array
    {
        $out = parent::validateCartOptions( $product, $options );

        if ( ! empty( $options[ 'license_type' ] ) && is_string( $options[ 'license_type' ] ) ) {
            $out[ 'license_type' ] = $options[ 'license_type' ];
        }

        return $out;
    }

    /**
     * Records that a digital delivery is expected. The
     * `ecommerce-digital-delivery` satellite listens for `ap.ecommerce.order.placed`
     * to actually mint the download tokens; the engine only marks intent.
     *
     * @since 1.0.0
     *
     * @param  Order      $order      Placed order.
     * @param  OrderItem  $orderItem  Line to run side-effects for.
     *
     * @return void
     */
    public function onOrderPlaced( Order $order, OrderItem $orderItem ): void
    {
        $snapshot                       = (array) ( $orderItem->product_snapshot ?? [] );
        $snapshot[ 'digital_delivery' ] = [
            'expected'   => true,
            'issued_at'  => null,
            'expires_at' => null,
        ];

        $orderItem->forceFill( [ 'product_snapshot' => $snapshot ] )->save();
    }
}
