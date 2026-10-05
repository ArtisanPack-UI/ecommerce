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

use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use InvalidArgumentException;

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

        if ( array_key_exists( 'license_type', $options ) && null !== $options['license_type'] && '' !== $options['license_type'] ) {
            // Only license types the product offers (`meta.licensing.types`).
            $allowed = array_map( 'strval', (array) ( $product->meta['licensing']['types'] ?? [] ) );

            if ( ! is_string( $options['license_type'] ) || ! in_array( $options['license_type'], $allowed, true ) ) {
                throw new InvalidArgumentException( 'license_type must be one of the license types this product offers.' );
            }

            $out['license_type'] = $options['license_type'];
        }

        return $out;
    }

    /**
     * The line snapshot plus a `digital_delivery` marker recording that a
     * delivery is expected. The `ecommerce-digital-delivery` satellite
     * listens for `ap.ecommerce.order.placed` to mint the download tokens;
     * the engine only marks intent. The marker is part of the snapshot
     * written at placement — the snapshot is immutable afterwards.
     *
     * @since 1.0.0
     *
     * @param  CartItem  $item  Cart line being converted.
     *
     * @return array<string, mixed>
     */
    public function buildOrderSnapshot( CartItem $item ): array
    {
        return parent::buildOrderSnapshot( $item ) + [
            'digital_delivery' => [
                'expected'   => true,
                'issued_at'  => null,
                'expires_at' => null,
            ],
        ];
    }
}
