<?php

/**
 * ShippingMethodTypeRegistry.
 *
 * Runtime registry of {@see \ArtisanPackUI\Ecommerce\Contracts\ShippingMethodType}
 * implementations. Configurable shipping-method drivers. Core registers flat-rate, free-shipping, local-pickup, weight-based, price-based.
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

use ArtisanPackUI\Ecommerce\Contracts\ShippingMethodType;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @extends AbstractContractRegistry<ShippingMethodType>
 *
 * @method ShippingMethodType get( string $key )
 */
class ShippingMethodTypeRegistry extends AbstractContractRegistry
{
    /**
     * @since 1.0.0
     *
     * @return class-string<ShippingMethodType>
     */
    protected function contract(): string
    {
        return ShippingMethodType::class;
    }
}
