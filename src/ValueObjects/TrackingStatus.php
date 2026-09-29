<?php

/**
 * TrackingStatus value object.
 *
 * Normalized carrier tracking state. `status` uses the shipment status
 * vocabulary (`pending`, `in_transit`, `delivered`, `exception`).
 * Engine spec §4.4 / §6.6.
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

use DateTimeInterface;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class TrackingStatus
{
    /**
     * @since 1.0.0
     *
     * @param  string                  $status          Shipment status.
     * @param  string|null             $description     Carrier-supplied description.
     * @param  DateTimeInterface|null  $occurredAt      When the carrier recorded the event.
     * @param  string|null             $trackingNumber  Tracking number, when the carrier assigned one.
     * @param  string|null             $trackingUrl     Public tracking URL.
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $description = null,
        public readonly ?DateTimeInterface $occurredAt = null,
        public readonly ?string $trackingNumber = null,
        public readonly ?string $trackingUrl = null,
    ) {
    }
}
