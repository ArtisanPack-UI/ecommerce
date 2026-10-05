<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\CartStorage;
use ArtisanPackUI\Ecommerce\Services\DatabaseCartStorage;
use ArtisanPackUI\Ecommerce\Testing\Contracts\CartStorageContractTest;

/**
 * Verifies the reference {@see DatabaseCartStorage} satisfies the shared
 * {@see CartStorageContractTest} suite.
 *
 * @since 1.0.0
 */
final class DatabaseCartStorageContractTest extends CartStorageContractTest
{
    /**
     * @since 1.0.0
     *
     * @return CartStorage
     */
    protected function storage(): CartStorage
    {
        return new DatabaseCartStorage();
    }
}
