<?php

/**
 * Ecommerce service provider.
 *
 * Bootstraps the Ecommerce package by registering services and bindings.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Providers;

use ArtisanPackUI\Ecommerce\Ecommerce;
use ArtisanPackUI\Ecommerce\ProductTypes\DigitalProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Ecommerce package.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class EcommerceServiceProvider extends ServiceProvider
{
    /**
     * Registers any application services.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/artisanpack/ecommerce.php',
            'artisanpack.ecommerce',
        );

        $this->app->singleton( 'ecommerce', function ( $app ) {
            return new Ecommerce();
        } );

        $this->app->singleton( ProductTypeRegistry::class, function ( $app ): ProductTypeRegistry {
            return new ProductTypeRegistry( $app );
        } );
    }

    /**
     * Bootstraps any application services.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom( __DIR__ . '/../../database/migrations' );

        $this->registerCoreProductTypes();

        if ( $this->app->runningInConsole() ) {
            $this->publishes( [
                __DIR__ . '/../../config/artisanpack/ecommerce.php' => config_path( 'artisanpack/ecommerce.php' ),
            ], 'ecommerce-config' );

            $this->publishes( [
                __DIR__ . '/../../database/migrations' => database_path( 'migrations' ),
            ], 'ecommerce-migrations' );
        }
    }

    /**
     * Registers the built-in product types the engine ships with.
     *
     * Satellites (subscriptions, memberships, licenses, gift cards, …) add
     * their own by calling `$registry->register()` from their own
     * service-provider `boot()`. Registration happens in `boot()` — not
     * `register()` — so satellite providers loaded before this one can
     * still see the built-ins when they boot.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCoreProductTypes(): void
    {
        /** @var ProductTypeRegistry $registry */
        $registry = $this->app->make( ProductTypeRegistry::class );

        $registry->register(
            SimpleProductType::KEY,
            SimpleProductType::class,
            [ 'label' => __( 'Simple product' ), 'icon' => 'hero-cube' ],
        );

        $registry->register(
            DigitalProductType::KEY,
            DigitalProductType::class,
            [ 'label' => __( 'Digital product' ), 'icon' => 'hero-arrow-down-tray' ],
        );
    }
}
