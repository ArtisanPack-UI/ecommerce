<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\TaxProvider;
use ArtisanPackUI\Ecommerce\Models\TaxRate;
use ArtisanPackUI\Ecommerce\Tax\ManualTaxProvider;
use ArtisanPackUI\Ecommerce\Testing\Contracts\TaxProviderContractTest;

/**
 * Verifies the reference {@see ManualTaxProvider} satisfies the shared
 * {@see TaxProviderContractTest} suite.
 *
 * @since 1.0.0
 */
final class ManualTaxProviderContractTest extends TaxProviderContractTest
{
    protected function provider(): TaxProvider
    {
        return $this->app->make( ManualTaxProvider::class );
    }

    protected function seedTaxableJurisdiction(): void
    {
        TaxRate::factory()->create( [ 'region_code' => 'IL', 'rate_ubps' => 62_500_000, 'priority' => 1, 'is_shipping_taxable' => true, 'label' => 'Illinois' ] );
        TaxRate::factory()->create( [ 'region_code' => 'IL', 'postal_pattern' => '606*', 'rate_ubps' => 21_250_000, 'priority' => 2, 'label' => 'Chicago' ] );
    }
}
