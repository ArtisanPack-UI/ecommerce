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

use ArtisanPackUI\Ecommerce\Console\Commands\AuditOrderStatusCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\PruneIdempotencyRecordsCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\ReleaseExpiredReservationsCommand;
use ArtisanPackUI\Ecommerce\CurrencyRates\ConfigRateProvider;
use ArtisanPackUI\Ecommerce\CurrencyRates\FrankfurterRateProvider;
use ArtisanPackUI\Ecommerce\Ecommerce;
use ArtisanPackUI\Ecommerce\Fulfillment\ProportionalByLineTotalStrategy;
use ArtisanPackUI\Ecommerce\Http\Middleware\IdempotencyMiddleware;
use ArtisanPackUI\Ecommerce\Http\Middleware\RateLimitEcommerce;
use ArtisanPackUI\Ecommerce\Listeners\LinkCustomerOnUserVerified;
use ArtisanPackUI\Ecommerce\ProductTypes\DigitalProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use ArtisanPackUI\Ecommerce\Registries\CurrencyRateProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\FulfillmentAllocationStrategyRegistry;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use ArtisanPackUI\Ecommerce\Support\RateLimitPolicyRegistrar;
use Illuminate\Auth\Events\Verified;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Router;
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

        $this->app->singleton( CurrencyRateProviderRegistry::class, function ( $app ): CurrencyRateProviderRegistry {
            return new CurrencyRateProviderRegistry( $app );
        } );

        $this->app->singleton( PaymentGatewayRegistry::class, function ( $app ): PaymentGatewayRegistry {
            return new PaymentGatewayRegistry( $app );
        } );

        $this->app->singleton( FulfillmentAllocationStrategyRegistry::class, function ( $app ): FulfillmentAllocationStrategyRegistry {
            return new FulfillmentAllocationStrategyRegistry( $app );
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

        $this->registerIdempotencyMiddleware();
        $this->registerRateLimitMiddleware();
        $this->registerRateLimiters();
        $this->registerCoreProductTypes();
        $this->registerCoreCurrencyRateProviders();
        $this->registerCoreFulfillmentAllocationStrategies();
        $this->registerCustomerListeners();

        if ( $this->app->runningInConsole() ) {
            $this->publishes( [
                __DIR__ . '/../../config/artisanpack/ecommerce.php' => config_path( 'artisanpack/ecommerce.php' ),
            ], 'ecommerce-config' );

            $this->publishes( [
                __DIR__ . '/../../database/migrations' => database_path( 'migrations' ),
            ], 'ecommerce-migrations' );

            $this->commands( [
                AuditOrderStatusCommand::class,
                PruneIdempotencyRecordsCommand::class,
                ReleaseExpiredReservationsCommand::class,
            ] );

            $this->app->booted( function (): void {
                /** @var Schedule $schedule */
                $schedule = $this->app->make( Schedule::class );
                $schedule->command( 'ecommerce:release-expired-reservations' )
                    ->everyMinute()
                    ->withoutOverlapping()
                    ->runInBackground();
                $schedule->command( 'ecommerce:audit-order-status' )
                    ->dailyAt( '02:15' )
                    ->withoutOverlapping()
                    ->runInBackground();
                $schedule->command( 'ecommerce:prune-idempotency-records' )
                    ->hourly()
                    ->withoutOverlapping()
                    ->runInBackground();
            } );
        }
    }

    /**
     * Aliases {@see IdempotencyMiddleware} so route classes can attach it
     * with `->middleware('ecommerce.idempotency')`. Engine spec §11.2.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerIdempotencyMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make( Router::class );

        $router->aliasMiddleware( 'ecommerce.idempotency', IdempotencyMiddleware::class );
    }

    /**
     * Aliases {@see RateLimitEcommerce} so routes can attach a named policy
     * with `->middleware('ecommerce.rate-limit:ecommerce.catalog.read')`.
     * Engine spec §11.3.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerRateLimitMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make( Router::class );

        $router->aliasMiddleware( 'ecommerce.rate-limit', RateLimitEcommerce::class );
    }

    /**
     * Registers every named rate-limit policy from engine spec §11.3
     * (parent plan §16.1) with Laravel's `RateLimiter` facade.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerRateLimiters(): void
    {
        RateLimitPolicyRegistrar::register();
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

    /**
     * Registers the built-in FX-rate providers the engine ships with.
     *
     * Satellites register additional providers (e.g. Wise, OpenExchange)
     * from their own service-provider `boot()`. Registration happens here
     * so satellite providers loaded before this one can still see the
     * built-ins when they boot.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCoreCurrencyRateProviders(): void
    {
        /** @var CurrencyRateProviderRegistry $registry */
        $registry = $this->app->make( CurrencyRateProviderRegistry::class );

        $registry->register(
            ConfigRateProvider::KEY,
            ConfigRateProvider::class,
            [ 'label' => __( 'Configured rates' ) ],
        );

        $registry->register(
            FrankfurterRateProvider::KEY,
            FrankfurterRateProvider::class,
            [ 'label' => __( 'Frankfurter (ECB reference rates)' ) ],
        );
    }

    /**
     * Registers the built-in fulfillment allocation strategies the engine
     * ships with. Satellites (subscriptions, marketplaces, split-shipment
     * carriers, …) register additional strategies from their own
     * service-provider `boot()`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCoreFulfillmentAllocationStrategies(): void
    {
        /** @var FulfillmentAllocationStrategyRegistry $registry */
        $registry = $this->app->make( FulfillmentAllocationStrategyRegistry::class );

        $registry->register(
            ProportionalByLineTotalStrategy::KEY,
            ProportionalByLineTotalStrategy::class,
            [ 'label' => __( 'Proportional by line total' ) ],
        );
    }

    /**
     * Wires the customer-lifecycle listeners: on verified-email registration,
     * back-fill `customers.user_id` for the shopper (engine spec §5.8 / §3.22).
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCustomerListeners(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make( Dispatcher::class );

        $events->listen( Verified::class, LinkCustomerOnUserVerified::class );
    }
}
