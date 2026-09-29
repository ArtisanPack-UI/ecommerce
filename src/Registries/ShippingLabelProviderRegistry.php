<?php

/**
 * ShippingLabelProviderRegistry.
 *
 * Runtime registry of {@see \ArtisanPackUI\Ecommerce\Contracts\ShippingLabelProvider}
 * implementations. Label providers. Empty by default; populated by shipping-labels satellites. Engine spec §5 row 4.
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

use ArtisanPackUI\Ecommerce\Contracts\ShippingLabelProvider;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @extends AbstractContractRegistry<ShippingLabelProvider>
 *
 * @method ShippingLabelProvider get( string $key )
 */
class ShippingLabelProviderRegistry extends AbstractContractRegistry
{
    /**
     * @since 1.0.0
     *
     * @return class-string<ShippingLabelProvider>
     */
    protected function contract(): string
    {
        return ShippingLabelProvider::class;
    }
}
