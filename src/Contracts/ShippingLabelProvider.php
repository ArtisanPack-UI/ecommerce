<?php

/**
 * ShippingLabelProvider contract.
 *
 * Purchases, voids, and tracks carrier labels for a shipment. Implemented by
 * the `shipping-labels` peer package and its carrier satellites; this
 * interface duplicates the surface the engine calls so a satellite can
 * implement it without a hard dependency on the labels package.
 *
 * Engine spec §4.4, parent plan §5.10.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingLabel;
use ArtisanPackUI\Ecommerce\ValueObjects\TrackingStatus;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface ShippingLabelProvider
{
    /**
     * Machine-readable provider key; matches the registry key.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string;

    /**
     * Purchases a label for `$shipment`.
     *
     * @since 1.0.0
     *
     * @param  Shipment  $shipment  Shipment to label.
     *
     * @return ShippingLabel
     */
    public function buyLabel( Shipment $shipment ): ShippingLabel;

    /**
     * Voids a previously-purchased label.
     *
     * @since 1.0.0
     *
     * @param  ShippingLabel  $label  Label to void.
     *
     * @return void
     */
    public function voidLabel( ShippingLabel $label ): void;

    /**
     * Returns the carrier's current tracking status for `$label`.
     *
     * @since 1.0.0
     *
     * @param  ShippingLabel  $label  Label to track.
     *
     * @return TrackingStatus
     */
    public function trackLabel( ShippingLabel $label ): TrackingStatus;
}
