<?php

/**
 * ShippingRateProviderRegistry.
 *
 * Runtime registry of {@see \ArtisanPackUI\Ecommerce\Contracts\ShippingRateProvider}
 * implementations. Real-time carrier rate providers (satellites). Empty by default; engine spec §5 row 3.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Registries;

use ArtisanPackUI\Ecommerce\Contracts\ShippingRateProvider;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @extends AbstractContractRegistry<ShippingRateProvider>
 *
 * @method ShippingRateProvider get( string $key )
 */
class ShippingRateProviderRegistry extends AbstractContractRegistry
{
    /**
     * @since 1.0.0
     *
     * @return class-string<ShippingRateProvider>
     */
    protected function contract(): string
    {
        return ShippingRateProvider::class;
    }
}
