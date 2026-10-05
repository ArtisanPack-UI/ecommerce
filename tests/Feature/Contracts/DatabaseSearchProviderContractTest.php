<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\SearchProvider;
use ArtisanPackUI\Ecommerce\Registries\SearchProviderRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\SearchProviderContractTest;

/**
 * Verifies the core search provider satisfies the shared
 * {@see SearchProviderContractTest} suite.
 *
 * @since 1.0.0
 */
final class DatabaseSearchProviderContractTest extends SearchProviderContractTest
{
    protected function provider(): SearchProvider
    {
        return $this->app->make( SearchProviderRegistry::class )->get( 'default' );
    }
}
