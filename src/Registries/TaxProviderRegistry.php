<?php

/**
 * TaxProviderRegistry.
 *
 * Runtime registry of {@see \ArtisanPackUI\Ecommerce\Contracts\TaxProvider}
 * implementations. Core registers `manual`; only the provider named by
 * `artisanpack.ecommerce.tax.provider` is used at checkout.
 *
 * Engine spec §5 (row 5).
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

use ArtisanPackUI\Ecommerce\Contracts\TaxProvider;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @extends AbstractContractRegistry<TaxProvider>
 *
 * @method TaxProvider get( string $key )
 */
class TaxProviderRegistry extends AbstractContractRegistry
{
    /**
     * @since 1.0.0
     *
     * @return class-string<TaxProvider>
     */
    protected function contract(): string
    {
        return TaxProvider::class;
    }
}
