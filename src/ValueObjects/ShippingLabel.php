<?php

/**
 * ShippingLabel value object.
 *
 * A purchased carrier label, as returned by
 * {@see \ArtisanPackUI\Ecommerce\Contracts\ShippingLabelProvider::buyLabel()}.
 * Engine spec §4.4.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\ValueObjects;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ShippingLabel
{
    /**
     * @since 1.0.0
     *
     * @param  int                   $id              Label id in the labels package (stored on `shipments.label_id`).
     * @param  string                $providerKey     Provider that issued the label.
     * @param  string|null           $trackingNumber  Carrier tracking number.
     * @param  string|null           $trackingUrl     Public tracking URL.
     * @param  string|null           $carrier         Carrier slug.
     * @param  string|null           $service         Carrier service slug.
     * @param  string|null           $labelUrl        URL of the printable label.
     * @param  array<string, mixed>  $meta            Provider-specific extras.
     */
    public function __construct(
        public readonly int $id,
        public readonly string $providerKey,
        public readonly ?string $trackingNumber = null,
        public readonly ?string $trackingUrl = null,
        public readonly ?string $carrier = null,
        public readonly ?string $service = null,
        public readonly ?string $labelUrl = null,
        public readonly array $meta = [],
    ) {
    }
}
