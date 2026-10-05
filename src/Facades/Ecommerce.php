<?php

/**
 * Ecommerce Facade.
 *
 * Provides static access to the Ecommerce class.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Ecommerce Facade.
 *
 * @method static \ArtisanPackUI\Ecommerce\Services\StorefrontCartService cart()
 * @method static \ArtisanPackUI\Ecommerce\Services\CheckoutService checkout()
 * @method static \ArtisanPackUI\Ecommerce\Orders\OrderServices orders()
 * @method static \ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator payments()
 * @method static \ArtisanPackUI\Ecommerce\Catalog\CatalogQuery catalog()
 * @method static \ArtisanPackUI\Ecommerce\Services\CustomerService customers()
 * @method static \ArtisanPackUI\Ecommerce\Services\InventoryService inventory()
 * @method static string version()
 *
 * @see \ArtisanPackUI\Ecommerce\Ecommerce
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class Ecommerce extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'ecommerce';
    }
}
