<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\ShippingRateProvider;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\Shipping\ZoneShippingRateProvider;
use ArtisanPackUI\Ecommerce\Testing\Contracts\ShippingRateProviderContractTest;

/**
 * Verifies the reference {@see ZoneShippingRateProvider} satisfies the shared
 * {@see ShippingRateProviderContractTest} suite.
 *
 * @since 1.0.0
 */
final class ZoneShippingRateProviderContractTest extends ShippingRateProviderContractTest
{
    protected function provider(): ShippingRateProvider
    {
        return $this->app->make( ZoneShippingRateProvider::class );
    }

    protected function seedServiceableDestination(): void
    {
        $zone = ShippingZone::factory()->create( [ 'country_codes' => [ 'US' ] ] );

        ShippingMethod::factory()->create( [ 'zone_id' => $zone->id, 'key' => 'flat-rate', 'label' => 'Standard', 'config' => [ 'amount' => [ 'USD' => 500, 'EUR' => 450 ] ] ] );
        ShippingMethod::factory()->create( [ 'zone_id' => $zone->id, 'key' => 'local-pickup', 'label' => 'Pickup', 'config' => [] ] );
        ShippingMethod::factory()->create( [ 'zone_id' => $zone->id, 'key' => 'weight-based', 'label' => 'By weight', 'config' => [ 'unit' => 'kg', 'tiers' => [ [ 'max_weight' => null, 'amount' => [ 'USD' => 900, 'EUR' => 800 ] ] ] ] ] );
    }
}
