<?php

declare( strict_types=1 );

namespace Tests\Fixtures;

use ArtisanPackUI\Ecommerce\Contracts\ShippingLabelProvider;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingLabel;
use ArtisanPackUI\Ecommerce\ValueObjects\TrackingStatus;

/**
 * In-memory label provider for the `print-shipping-label` automation tests.
 */
class FakeShippingLabelProvider implements ShippingLabelProvider
{
    public const KEY = 'fake-labels';

    public function key(): string
    {
        return self::KEY;
    }

    public function buyLabel( Shipment $shipment ): ShippingLabel
    {
        return new ShippingLabel( 4242, self::KEY, 'TRACK-' . $shipment->id, 'https://track.example.test/' . $shipment->id, 'usps', 'priority' );
    }

    public function voidLabel( ShippingLabel $label ): void
    {
    }

    public function trackLabel( ShippingLabel $label ): TrackingStatus
    {
        return new TrackingStatus( 'in_transit' );
    }
}
