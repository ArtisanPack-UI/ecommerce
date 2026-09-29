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
use ArtisanPackUI\Ecommerce\Console\Commands\LintPciColumnsCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\PruneIdempotencyRecordsCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\ReleaseExpiredReservationsCommand;
use ArtisanPackUI\Ecommerce\Contracts\CartStorage;
use ArtisanPackUI\Ecommerce\Contracts\OrderNumberGenerator;
use ArtisanPackUI\Ecommerce\CurrencyRates\ConfigRateProvider;
use ArtisanPackUI\Ecommerce\CurrencyRates\FrankfurterRateProvider;
use ArtisanPackUI\Ecommerce\Ecommerce;
use ArtisanPackUI\Ecommerce\Fulfillment\ProportionalByLineTotalStrategy;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeGateway;
use ArtisanPackUI\Ecommerce\Http\Controllers\WebhookController;
use ArtisanPackUI\Ecommerce\Http\Middleware\EnsureEcommerceAbility;
use ArtisanPackUI\Ecommerce\Http\Middleware\ForceJsonResponse;
use ArtisanPackUI\Ecommerce\Http\Middleware\IdempotencyMiddleware;
use ArtisanPackUI\Ecommerce\Http\Middleware\RateLimitEcommerce;
use ArtisanPackUI\Ecommerce\Http\Middleware\RequestIdMiddleware;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Listeners\LinkCustomerOnUserVerified;
use ArtisanPackUI\Ecommerce\Logging\EcommerceLogFormatter;
use ArtisanPackUI\Ecommerce\ProductTypes\DigitalProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use ArtisanPackUI\Ecommerce\Promotions\Actions\AddFreeItemAction;
use ArtisanPackUI\Ecommerce\Promotions\Actions\BuyXGetYAction;
use ArtisanPackUI\Ecommerce\Promotions\Actions\FixedOffCartAction;
use ArtisanPackUI\Ecommerce\Promotions\Actions\FreeShippingAction;
use ArtisanPackUI\Ecommerce\Promotions\Actions\PercentOffCartAction;
use ArtisanPackUI\Ecommerce\Promotions\Actions\PercentOffProductAction;
use ArtisanPackUI\Ecommerce\Promotions\Actions\TieredDiscountAction;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\CartContainsProductCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\CustomerFirstOrderCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\CustomerInGroupCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\DayOfWeekCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\MinSubtotalCondition;
use ArtisanPackUI\Ecommerce\Registries\CurrencyRateProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\FraudProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\FulfillmentAllocationStrategyRegistry;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionSourceRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingLabelProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingMethodTypeRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingRateProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\TaxProviderRegistry;
use ArtisanPackUI\Ecommerce\Services\DatabaseCartStorage;
use ArtisanPackUI\Ecommerce\Services\Fraud\AlwaysApproveFraudProvider;
use ArtisanPackUI\Ecommerce\Services\Fraud\StripeRadarFraudProvider;
use ArtisanPackUI\Ecommerce\Services\RandomEightCharGenerator;
use ArtisanPackUI\Ecommerce\Shipping\Methods\FlatRateMethod;
use ArtisanPackUI\Ecommerce\Shipping\Methods\FreeShippingMethod;
use ArtisanPackUI\Ecommerce\Shipping\Methods\LocalPickupMethod;
use ArtisanPackUI\Ecommerce\Shipping\Methods\PriceBasedMethod;
use ArtisanPackUI\Ecommerce\Shipping\Methods\WeightBasedMethod;
use ArtisanPackUI\Ecommerce\Support\RateLimitPolicyRegistrar;
use ArtisanPackUI\Ecommerce\Tax\ManualTaxProvider;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Verified;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
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

        $this->registerLogChannel();

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

        $this->app->singleton( FraudProviderRegistry::class, function ( $app ): FraudProviderRegistry {
            return new FraudProviderRegistry( $app );
        } );

        $this->app->singleton( FulfillmentAllocationStrategyRegistry::class, function ( $app ): FulfillmentAllocationStrategyRegistry {
            return new FulfillmentAllocationStrategyRegistry( $app );
        } );

        foreach ( [
            TaxProviderRegistry::class,
            ShippingRateProviderRegistry::class,
            ShippingLabelProviderRegistry::class,
            ShippingMethodTypeRegistry::class,
            PromotionConditionRegistry::class,
            PromotionActionRegistry::class,
            PromotionSourceRegistry::class,
        ] as $registry ) {
            $this->app->singleton( $registry, static fn ( $app ) => new $registry( $app ) );
        }

        $this->app->singleton( CartStorage::class, DatabaseCartStorage::class );
        $this->app->singleton( OrderNumberGenerator::class, RandomEightCharGenerator::class );
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

        $this->registerRequestIdMiddleware();
        $this->registerIdempotencyMiddleware();
        $this->registerRateLimitMiddleware();
        $this->registerRateLimiters();
        $this->registerCoreProductTypes();
        $this->registerCoreCurrencyRateProviders();
        $this->registerCoreFulfillmentAllocationStrategies();
        $this->registerCorePaymentGateways();
        $this->registerCoreFraudProviders();
        $this->registerCoreTaxProviders();
        $this->registerCoreShippingMethodTypes();
        $this->registerCorePromotionRules();
        $this->registerWebhookRoute();
        $this->registerRestRoutes();
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
                LintPciColumnsCommand::class,
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
     * Merges the dedicated `ecommerce` log channel into the application's
     * `logging.channels` config so callers can immediately reach it with
     * `Log::channel('ecommerce')->info(...)`. The channel is a `single`
     * driver tapped by {@see EcommerceLogFormatter} to emit structured
     * JSON. Engine plan §16.3.
     *
     * Consumers who need to point the channel at a different path,
     * driver, or handler can override the entry by publishing their own
     * `config/logging.php` — the merge here only fills in the channel
     * when the host application has not already defined one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerLogChannel(): void
    {
        $channels = (array) $this->app['config']->get( 'logging.channels', [] );

        if ( array_key_exists( 'ecommerce', $channels ) ) {
            return;
        }

        $level = (string) $this->app['config']->get( 'artisanpack.ecommerce.log_level', 'debug' );

        $channels['ecommerce'] = [
            'driver' => 'single',
            'path'   => storage_path( 'logs/ecommerce.log' ),
            'level'  => $level,
            'tap'    => [ EcommerceLogFormatter::class ],
            'bubble' => true,
        ];

        $this->app['config']->set( 'logging.channels', $channels );
    }

    /**
     * Aliases {@see RequestIdMiddleware} so route classes can attach it
     * with `->middleware('ecommerce.request-id')`. The middleware
     * threads a correlation ID through log context + outbound webhook
     * headers + gateway idempotency keys. Engine plan §16.3.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerRequestIdMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make( Router::class );

        $router->aliasMiddleware( 'ecommerce.request-id', RequestIdMiddleware::class );
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
        $router->aliasMiddleware( 'ecommerce.can', EnsureEcommerceAbility::class );
        $router->aliasMiddleware( 'ecommerce.json', ForceJsonResponse::class );
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
     * Registers the built-in payment gateways the engine ships with.
     *
     * Currently: Stripe (parent plan §7.5 + §8.1 + §8.5). The gateway is
     * only registered when `artisanpack.ecommerce.gateways.stripe.enabled`
     * is true, so a mis-configured environment can't accidentally route
     * traffic through a provider whose secret key is unset.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCorePaymentGateways(): void
    {
        if ( ! (bool) $this->app[ 'config' ]->get( 'artisanpack.ecommerce.gateways.stripe.enabled', false ) ) {
            return;
        }

        /** @var PaymentGatewayRegistry $registry */
        $registry = $this->app->make( PaymentGatewayRegistry::class );

        $registry->register(
            StripeGateway::KEY,
            StripeGateway::class,
            [
                'label'                    => __( 'Stripe' ),
                'supports_saved_cards'     => true,
                'supports_partial_refunds' => true,
            ],
        );
    }

    /**
     * Registers the built-in fraud providers the engine ships with.
     *
     * `always-approve` is always registered — stores that don't want
     * fraud gating still route through the assess step for uniform
     * timeline entries and hook signals. `stripe-radar` is only
     * registered when the Stripe gateway itself is enabled, since it
     * depends on the same client factory. Satellites (Signifyd, Kount)
     * register additional providers from their own service-provider
     * `boot()` and can be chained via a comma-separated
     * `artisanpack.ecommerce.fraud.provider`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCoreFraudProviders(): void
    {
        /** @var FraudProviderRegistry $registry */
        $registry = $this->app->make( FraudProviderRegistry::class );

        $registry->register(
            AlwaysApproveFraudProvider::KEY,
            AlwaysApproveFraudProvider::class,
            [ 'label' => __( 'Always approve (fraud gating disabled)' ) ],
        );

        if ( (bool) $this->app[ 'config' ]->get( 'artisanpack.ecommerce.gateways.stripe.enabled', false ) ) {
            $this->app->bind( StripeRadarFraudProvider::class, function ( $app ): StripeRadarFraudProvider {
                return new StripeRadarFraudProvider(
                    static fn () => $app->make( \ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeClientFactory::class )->make(),
                );
            } );

            $registry->register(
                StripeRadarFraudProvider::KEY,
                StripeRadarFraudProvider::class,
                [ 'label' => __( 'Stripe Radar' ) ],
            );
        }
    }

    /**
     * Registers the built-in tax provider (`manual`, reads `tax_rates`).
     * Satellites (Stripe Tax, TaxJar, Avalara) register theirs from their
     * own service-provider `boot()`; `artisanpack.ecommerce.tax.provider`
     * picks the single active one. Engine spec §5 row 5.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCoreTaxProviders(): void
    {
        $this->app->make( TaxProviderRegistry::class )->register(
            ManualTaxProvider::KEY,
            ManualTaxProvider::class,
            [ 'label' => __( 'Manual tax rates' ) ],
        );
    }

    /**
     * Registers the built-in shipping-method drivers (parent plan §5.10).
     * Real-time carrier providers are satellites and register against
     * {@see ShippingRateProviderRegistry} instead.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCoreShippingMethodTypes(): void
    {
        /** @var ShippingMethodTypeRegistry $registry */
        $registry = $this->app->make( ShippingMethodTypeRegistry::class );

        $registry->register( FlatRateMethod::KEY, FlatRateMethod::class, [ 'label' => __( 'Flat rate' ) ] );
        $registry->register( FreeShippingMethod::KEY, FreeShippingMethod::class, [ 'label' => __( 'Free shipping' ) ] );
        $registry->register( LocalPickupMethod::KEY, LocalPickupMethod::class, [ 'label' => __( 'Local pickup' ) ] );
        $registry->register( WeightBasedMethod::KEY, WeightBasedMethod::class, [ 'label' => __( 'Weight-based' ) ] );
        $registry->register( PriceBasedMethod::KEY, PriceBasedMethod::class, [ 'label' => __( 'Price-based' ) ] );
    }

    /**
     * Registers the built-in promotion sources, conditions, and actions
     * (parent plan §5.9, engine spec §5 rows 7–9). Satellites add novel
     * conditions / actions / sources from their own `boot()`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCorePromotionRules(): void
    {
        /** @var PromotionSourceRegistry $sources */
        $sources = $this->app->make( PromotionSourceRegistry::class );
        $sources->register( 'automatic', [ 'label' => __( 'Automatic' ) ] );
        $sources->register( 'coupon', [ 'label' => __( 'Coupon code' ) ] );

        /** @var PromotionConditionRegistry $conditions */
        $conditions = $this->app->make( PromotionConditionRegistry::class );

        foreach ( [
            MinSubtotalCondition::class,
            CartContainsProductCondition::class,
            CustomerInGroupCondition::class,
            DayOfWeekCondition::class,
            CustomerFirstOrderCondition::class,
        ] as $condition ) {
            $conditions->register( $condition::KEY, $condition );
        }

        /** @var PromotionActionRegistry $actions */
        $actions = $this->app->make( PromotionActionRegistry::class );

        foreach ( [
            PercentOffCartAction::class,
            FixedOffCartAction::class,
            PercentOffProductAction::class,
            FreeShippingAction::class,
            BuyXGetYAction::class,
            AddFreeItemAction::class,
            TieredDiscountAction::class,
        ] as $action ) {
            $actions->register( $action::KEY, $action );
        }
    }

    /**
     * Registers the generic inbound-webhook route.
     *
     * `POST /ecommerce/webhooks/{provider}` dispatches to whatever gateway
     * is registered under `{provider}` in {@see PaymentGatewayRegistry}.
     * The route is rate-limited by the shared `ap.ecommerce.webhook.inbound`
     * policy and excluded from CSRF because a provider cannot mint a token.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerWebhookRoute(): void
    {
        /** @var Router $router */
        $router = $this->app->make( Router::class );

        $router->post( 'ecommerce/webhooks/{provider}', [ WebhookController::class, 'handle' ] )
            ->where( 'provider', '[A-Za-z0-9_.-]+' )
            ->middleware( [ 'api', 'ecommerce.request-id', 'ecommerce.rate-limit:ecommerce.webhook.inbound' ] )
            ->name( 'ecommerce.webhooks' );
    }

    /**
     * Registers the `/api/ecommerce/v1` REST routes (parent plan §12) when
     * `artisanpack.ecommerce.features.rest` is enabled. Route names are
     * prefixed `ecommerce.api.` so idempotency endpoint keys are stable.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerRestRoutes(): void
    {
        $config = $this->app['config'];

        if ( ! (bool) $config->get( 'artisanpack.ecommerce.features.rest', true ) ) {
            return;
        }

        /** @var Router $router */
        $router = $this->app->make( Router::class );

        // Ability checks run right after authentication and before route-model
        // binding, so a caller without the ability gets 403 for every id —
        // never a 404 that reveals which orders / customers exist.
        $kernel = $this->app->make( HttpKernel::class );

        if ( method_exists( $kernel, 'addToMiddlewarePriorityAfter' ) ) {
            $kernel->addToMiddlewarePriorityAfter( AuthenticatesRequests::class, EnsureEcommerceAbility::class );
        }

        // 401s on the API render as problem+json (engine spec §11.5).
        $handler = $this->app->make( ExceptionHandler::class );

        if ( method_exists( $handler, 'renderable' ) ) {
            $handler->renderable( static function ( AuthenticationException $e, $request ) {
                return $request->routeIs( 'ecommerce.api.*' )
                    ? Problem::make( 401, 'unauthenticated', __( 'Unauthenticated' ), __( 'Authentication is required.' ), $request )
                    : null;
            } );
        }

        $router->prefix( trim( (string) $config->get( 'artisanpack.ecommerce.api_prefix', 'api/ecommerce' ), '/' ) . '/' . $config->get( 'artisanpack.ecommerce.api.version', 'v1' ) )
            ->middleware( array_merge(
                [ 'ecommerce.json' ],
                (array) $config->get( 'artisanpack.ecommerce.api.middleware', [ 'api', 'ecommerce.request-id' ] ),
            ) )
            ->name( 'ecommerce.api.' )
            ->group( __DIR__ . '/../../routes/api.php' );
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
