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

use ArtisanPackUI\Ecommerce\Auth\CmsFrameworkPermissions;
use ArtisanPackUI\Ecommerce\Auth\EcommerceAuthorizer;
use ArtisanPackUI\Ecommerce\Console\Commands\AuditOrderStatusCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\FlagAbandonedCartsCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\GenerateOpenApiCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\LintPciColumnsCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\LintTranslationsCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\PruneCartsCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\PruneIdempotencyRecordsCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\PruneLedgersCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\ReconcilePaymentsCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\RefreshFxRatesCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\ReleaseExpiredReservationsCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\RetryWebhookDeliveriesCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\SatelliteAuditCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\SatelliteReinstallCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\SatelliteUninstallCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\SeedDemoCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\SyncPermissionsCommand;
use ArtisanPackUI\Ecommerce\Console\Commands\VerifySatelliteCommand;
use ArtisanPackUI\Ecommerce\Contracts\CartStorage;
use ArtisanPackUI\Ecommerce\Contracts\CurrencyResolver;
use ArtisanPackUI\Ecommerce\Contracts\OrderNumberGenerator;
use ArtisanPackUI\Ecommerce\Contracts\ReviewModerator;
use ArtisanPackUI\Ecommerce\CurrencyRates\ConfigRateProvider;
use ArtisanPackUI\Ecommerce\CurrencyRates\FrankfurterRateProvider;
use ArtisanPackUI\Ecommerce\Ecommerce;
use ArtisanPackUI\Ecommerce\Events\KanbanCardMoved;
use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Exceptions\CustomerWriteException;
use ArtisanPackUI\Ecommerce\Exceptions\OrderSubstatusWriteException;
use ArtisanPackUI\Ecommerce\Exceptions\ProductWriteException;
use ArtisanPackUI\Ecommerce\Exceptions\SettingsWriteException;
use ArtisanPackUI\Ecommerce\Fulfillment\ProportionalByLineTotalStrategy;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeGateway;
use ArtisanPackUI\Ecommerce\GraphQL\EcommerceSchema;
use ArtisanPackUI\Ecommerce\GraphQL\Execution\GuardOperations;
use ArtisanPackUI\Ecommerce\GraphQL\Fields\Subscriptions;
use ArtisanPackUI\Ecommerce\Http\Controllers\NotificationUnsubscribeController;
use ArtisanPackUI\Ecommerce\Http\Controllers\WebhookController;
use ArtisanPackUI\Ecommerce\Http\Middleware\AuthenticateOptionally;
use ArtisanPackUI\Ecommerce\Http\Middleware\CacheHeaders;
use ArtisanPackUI\Ecommerce\Http\Middleware\EnsureEcommerceAbility;
use ArtisanPackUI\Ecommerce\Http\Middleware\ForceJsonResponse;
use ArtisanPackUI\Ecommerce\Http\Middleware\IdempotencyMiddleware;
use ArtisanPackUI\Ecommerce\Http\Middleware\LimitGraphQLBatch;
use ArtisanPackUI\Ecommerce\Http\Middleware\NegotiateLocale;
use ArtisanPackUI\Ecommerce\Http\Middleware\RateLimitEcommerce;
use ArtisanPackUI\Ecommerce\Http\Middleware\RequestIdMiddleware;
use ArtisanPackUI\Ecommerce\Http\Middleware\ServiceSignatureMiddleware;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Kanban\Triggers\CreateShipmentTrigger;
use ArtisanPackUI\Ecommerce\Kanban\Triggers\DispatchJobTrigger;
use ArtisanPackUI\Ecommerce\Kanban\Triggers\PrintShippingLabelTrigger;
use ArtisanPackUI\Ecommerce\Kanban\Triggers\SendEmailTrigger;
use ArtisanPackUI\Ecommerce\Kanban\Triggers\UpdateOrderFieldTrigger;
use ArtisanPackUI\Ecommerce\Kanban\Triggers\WebhookTrigger;
use ArtisanPackUI\Ecommerce\Kanban\Widgets\CustomerWidget;
use ArtisanPackUI\Ecommerce\Kanban\Widgets\DaysInColumnWidget;
use ArtisanPackUI\Ecommerce\Kanban\Widgets\FulfillmentStatusWidget;
use ArtisanPackUI\Ecommerce\Kanban\Widgets\ItemCountWidget;
use ArtisanPackUI\Ecommerce\Kanban\Widgets\PaymentStatusWidget;
use ArtisanPackUI\Ecommerce\Kanban\Widgets\ShippingMethodWidget;
use ArtisanPackUI\Ecommerce\Kanban\Widgets\TagsWidget;
use ArtisanPackUI\Ecommerce\Kanban\Widgets\TotalWidget;
use ArtisanPackUI\Ecommerce\Listeners\BroadcastGraphQLSubscriptions;
use ArtisanPackUI\Ecommerce\Listeners\BroadcastKanbanCardMoved;
use ArtisanPackUI\Ecommerce\Listeners\DispatchWebhooksForEvent;
use ArtisanPackUI\Ecommerce\Listeners\IssueDigitalDeliverables;
use ArtisanPackUI\Ecommerce\Listeners\LinkCustomerOnUserVerified;
use ArtisanPackUI\Ecommerce\Listeners\MergeGuestCartOnLogin;
use ArtisanPackUI\Ecommerce\Listeners\RecordModelActivity;
use ArtisanPackUI\Ecommerce\Listeners\RevokeDigitalDeliverables;
use ArtisanPackUI\Ecommerce\Listeners\SendCatalogNotifications;
use ArtisanPackUI\Ecommerce\Listeners\SyncSearchIndexers;
use ArtisanPackUI\Ecommerce\Listeners\TrackCustomerMilestones;
use ArtisanPackUI\Ecommerce\Listeners\UpdateCustomerStats;
use ArtisanPackUI\Ecommerce\Logging\EcommerceLogFormatter;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerAddress;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\EcommerceSetting;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use ArtisanPackUI\Ecommerce\Models\NotificationTemplate;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\Models\TaxClass;
use ArtisanPackUI\Ecommerce\Models\TaxRate;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Policies\CouponPolicy;
use ArtisanPackUI\Ecommerce\Policies\CustomerAddressPolicy;
use ArtisanPackUI\Ecommerce\Policies\CustomerPolicy;
use ArtisanPackUI\Ecommerce\Policies\DigitalFilePolicy;
use ArtisanPackUI\Ecommerce\Policies\InventoryPolicy;
use ArtisanPackUI\Ecommerce\Policies\KanbanBoardPolicy;
use ArtisanPackUI\Ecommerce\Policies\KanbanCardPolicy;
use ArtisanPackUI\Ecommerce\Policies\LicenseKeyPolicy;
use ArtisanPackUI\Ecommerce\Policies\NotificationTemplatePolicy;
use ArtisanPackUI\Ecommerce\Policies\OrderPolicy;
use ArtisanPackUI\Ecommerce\Policies\OrderSubstatusPolicy;
use ArtisanPackUI\Ecommerce\Policies\ProductPolicy;
use ArtisanPackUI\Ecommerce\Policies\PromotionPolicy;
use ArtisanPackUI\Ecommerce\Policies\RefundPolicy;
use ArtisanPackUI\Ecommerce\Policies\ReportPolicy;
use ArtisanPackUI\Ecommerce\Policies\ReviewPolicy;
use ArtisanPackUI\Ecommerce\Policies\SettingsPolicy;
use ArtisanPackUI\Ecommerce\Policies\ShippingZonePolicy;
use ArtisanPackUI\Ecommerce\Policies\TaxRatePolicy;
use ArtisanPackUI\Ecommerce\Policies\WebhookSubscriptionPolicy;
use ArtisanPackUI\Ecommerce\ProductTypes\BundledProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\DigitalProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\GroupedProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\VariableProductType;
use ArtisanPackUI\Ecommerce\Promotions\Actions\AddFreeItemAction;
use ArtisanPackUI\Ecommerce\Promotions\Actions\BuyXGetYAction;
use ArtisanPackUI\Ecommerce\Promotions\Actions\FixedOffCartAction;
use ArtisanPackUI\Ecommerce\Promotions\Actions\FixedOffProductAction;
use ArtisanPackUI\Ecommerce\Promotions\Actions\FreeShippingAction;
use ArtisanPackUI\Ecommerce\Promotions\Actions\PercentOffCartAction;
use ArtisanPackUI\Ecommerce\Promotions\Actions\PercentOffProductAction;
use ArtisanPackUI\Ecommerce\Promotions\Actions\TieredDiscountAction;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\CartContainsCategoryCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\CartContainsProductCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\CartContainsProductTypeCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\CartContainsTagCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\CurrencyIsCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\CustomerFirstOrderCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\CustomerInGroupCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\CustomerLifetimeValueOverCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\DateRangeCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\DayOfWeekCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\MinQuantityCondition;
use ArtisanPackUI\Ecommerce\Promotions\Conditions\MinSubtotalCondition;
use ArtisanPackUI\Ecommerce\Registries\AccountMenuRegistry;
use ArtisanPackUI\Ecommerce\Registries\AdminMenuRegistry;
use ArtisanPackUI\Ecommerce\Registries\CurrencyRateProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\FraudProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\FulfillmentAllocationStrategyRegistry;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
use ArtisanPackUI\Ecommerce\Registries\NotificationChannelRegistry;
use ArtisanPackUI\Ecommerce\Registries\NotificationTemplateRegistry;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionActionRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use ArtisanPackUI\Ecommerce\Registries\PromotionSourceRegistry;
use ArtisanPackUI\Ecommerce\Registries\ReportRegistry;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\Ecommerce\Registries\SearchIndexerRegistry;
use ArtisanPackUI\Ecommerce\Registries\SearchProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\SettingsRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingLabelProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingMethodTypeRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingRateProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\SubStatusRegistry;
use ArtisanPackUI\Ecommerce\Registries\TaxProviderRegistry;
use ArtisanPackUI\Ecommerce\Reports\CategoryRevenueReport;
use ArtisanPackUI\Ecommerce\Reports\InventoryLevelsReport;
use ArtisanPackUI\Ecommerce\Reports\LowStockReport;
use ArtisanPackUI\Ecommerce\Reports\Report;
use ArtisanPackUI\Ecommerce\Reports\SalesReport;
use ArtisanPackUI\Ecommerce\Reports\SummaryReport;
use ArtisanPackUI\Ecommerce\Reports\TaxCollectedReport;
use ArtisanPackUI\Ecommerce\Reports\TopProductsReport;
use ArtisanPackUI\Ecommerce\Reviews\NoopReviewModerator;
use ArtisanPackUI\Ecommerce\Reviews\ProductRatingAggregator;
use ArtisanPackUI\Ecommerce\Search\DatabaseSearchProvider;
use ArtisanPackUI\Ecommerce\Services\ActivityLogService;
use ArtisanPackUI\Ecommerce\Services\DatabaseCartStorage;
use ArtisanPackUI\Ecommerce\Services\DigitalDownloadService;
use ArtisanPackUI\Ecommerce\Services\Fraud\AlwaysApproveFraudProvider;
use ArtisanPackUI\Ecommerce\Services\Fraud\StripeRadarFraudProvider;
use ArtisanPackUI\Ecommerce\Services\KanbanAutomationRunner;
use ArtisanPackUI\Ecommerce\Services\KanbanRoutingService;
use ArtisanPackUI\Ecommerce\Services\LicenseService;
use ArtisanPackUI\Ecommerce\Services\RandomEightCharGenerator;
use ArtisanPackUI\Ecommerce\Services\SessionCurrencyResolver;
use ArtisanPackUI\Ecommerce\Settings\CoreSettings;
use ArtisanPackUI\Ecommerce\Settings\SettingsRepository;
use ArtisanPackUI\Ecommerce\Shipping\Methods\FlatRateMethod;
use ArtisanPackUI\Ecommerce\Shipping\Methods\FreeShippingMethod;
use ArtisanPackUI\Ecommerce\Shipping\Methods\LocalPickupMethod;
use ArtisanPackUI\Ecommerce\Shipping\Methods\PriceBasedMethod;
use ArtisanPackUI\Ecommerce\Shipping\Methods\WeightBasedMethod;
use ArtisanPackUI\Ecommerce\Support\EngineSchedule;
use ArtisanPackUI\Ecommerce\Support\MorphType;
use ArtisanPackUI\Ecommerce\Support\RateLimitPolicyRegistrar;
use ArtisanPackUI\Ecommerce\Support\RegionalJsonFallbackLoader;
use ArtisanPackUI\Ecommerce\Support\RegistryHookRegistrar;
use ArtisanPackUI\Ecommerce\Support\RequestContext;
use ArtisanPackUI\Ecommerce\Tax\ManualTaxProvider;
use ArtisanPackUI\Ecommerce\Tax\TaxRateCandidates;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Verified;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Rebing\GraphQL\GraphQL as RebingGraphQL;
use Rebing\GraphQL\Support\ExecutionMiddleware\AddAuthUserContextValueMiddleware;
use Rebing\GraphQL\Support\ExecutionMiddleware\AutomaticPersistedQueriesMiddleware;
use Rebing\GraphQL\Support\ExecutionMiddleware\ValidateOperationParamsMiddleware;
use Stripe\StripeClient;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

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
     * Whether the "Stripe enabled but not installed" error was logged.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected bool $stripeMissingLogged = false;

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
        $this->registerSentryIntegration();
        $this->registerRegionalLocaleFallback();

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
            KanbanCardWidgetRegistry::class,
            KanbanAutomationRegistry::class,
            NotificationTemplateRegistry::class,
            SearchProviderRegistry::class,
            SearchIndexerRegistry::class,
        ] as $registry ) {
            $this->app->singleton( $registry, static fn ( $app ) => new $registry( $app ) );
        }

        $this->app->singleton( SatelliteRegistry::class, static fn ( $app ): SatelliteRegistry => new SatelliteRegistry( $app ) );
        $this->app->singleton( SettingsRegistry::class, static fn ( $app ): SettingsRegistry => new SettingsRegistry( $app ) );
        $this->app->singleton( SettingsRepository::class );
        $this->app->singleton( ReportRegistry::class, static fn ( $app ): ReportRegistry => new ReportRegistry( $app ) );
        $this->app->singleton( AdminMenuRegistry::class, static fn ( $app ): AdminMenuRegistry => new AdminMenuRegistry( $app ) );
        $this->app->singleton( AccountMenuRegistry::class, static fn ( $app ): AccountMenuRegistry => new AccountMenuRegistry( $app ) );
        $this->app->singleton( NotificationChannelRegistry::class, static fn ( $app ): NotificationChannelRegistry => new NotificationChannelRegistry( $app ) );
        // Scoped so queue workers and Octane re-read it per job / request.
        $this->app->scoped( SubStatusRegistry::class );
        $this->app->scoped( TaxRateCandidates::class );

        $this->app->singleton( CartStorage::class, DatabaseCartStorage::class );
        $this->app->singleton( CurrencyResolver::class, SessionCurrencyResolver::class );
        $this->app->singleton( OrderNumberGenerator::class, RandomEightCharGenerator::class );
        $this->app->singleton( ReviewModerator::class, NoopReviewModerator::class );

        // One shared instance, so ActivityLogService::withoutRecording()
        // pauses the model observers too (customer delete-and-anonymize).
        $this->app->singleton( ActivityLogService::class );

        // After every provider has registered, before any boots: rebing
        // registers its schema routes while booting, so the `ecommerce`
        // schema must be in its config by then.
        $this->app->booting( function (): void {
            $this->registerGraphQLSchema();
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
        MorphType::register();
        $this->registerMigrations();
        $this->registerTranslations();
        $this->registerCoreSettings();
        $this->registerRequestIdMiddleware();
        $this->registerMiddlewarePriority();
        $this->registerIdempotencyMiddleware();
        $this->registerRateLimitMiddleware();
        $this->registerRateLimiters();
        $this->registerCoreProductTypes();
        $this->registerCoreCurrencyRateProviders();
        $this->registerCoreFulfillmentAllocationStrategies();
        $this->registerCorePaymentGateways();
        $this->registerCoreFraudProviders();
        $this->registerCoreSearchProviders();
        $this->registerCoreAccountMenu();
        $this->registerCoreTaxProviders();
        $this->registerCoreShippingMethodTypes();
        $this->registerCorePromotionRules();
        $this->registerCoreKanban();
        $this->registerCoreNotificationTemplates();
        $this->registerCoreNotificationChannels();
        $this->registerCoreReports();
        $this->registerRegistryHooks();
        $this->registerWebhookRoute();
        $this->registerPolicies();
        $this->registerCmsFrameworkPermissions();
        $this->registerRestRoutes();
        $this->registerCustomerListeners();
        $this->registerWebhookListeners();
        $this->registerGraphQLSubscriptions();
        $this->registerKanbanListeners();
        $this->registerReviewListeners();
        $this->registerDigitalDeliveryListeners();
        $this->registerNotificationListeners();
        $this->registerActivityLogObservers();

        if ( $this->app->runningInConsole() ) {
            $this->publishes( [
                __DIR__ . '/../../config/artisanpack/ecommerce.php' => config_path( 'artisanpack/ecommerce.php' ),
            ], 'ecommerce-config' );

            $this->publishes( [
                __DIR__ . '/../../database/migrations' => database_path( 'migrations' ),
            ], 'ecommerce-migrations' );

            $this->publishes( [
                __DIR__ . '/../../lang' => $this->app->langPath( 'vendor/ecommerce' ),
            ], 'ecommerce-lang' );

            $this->commands( [
                AuditOrderStatusCommand::class,
                GenerateOpenApiCommand::class,
                LintPciColumnsCommand::class,
                LintTranslationsCommand::class,
                FlagAbandonedCartsCommand::class,
                PruneCartsCommand::class,
                PruneIdempotencyRecordsCommand::class,
                PruneLedgersCommand::class,
                ReconcilePaymentsCommand::class,
                RefreshFxRatesCommand::class,
                ReleaseExpiredReservationsCommand::class,
                RetryWebhookDeliveriesCommand::class,
                SatelliteAuditCommand::class,
                SatelliteReinstallCommand::class,
                SatelliteUninstallCommand::class,
                SeedDemoCommand::class,
                SyncPermissionsCommand::class,
                VerifySatelliteCommand::class,
            ] );

            $this->app->booted( function (): void {
                EngineSchedule::register( $this->app->make( Schedule::class ) );
            } );
        }
    }

    /**
     * Defines the ability Gates and registers the post-migrate permission
     * sync when cms-framework is available.
     *
     * @since 1.0.0
     *
     * @param  CmsFrameworkPermissions  $permissions  cms-framework bridge.
     *
     * @return void
     */
    public function bootCmsFrameworkPermissions( CmsFrameworkPermissions $permissions ): void
    {
        if ( ! $permissions->isAvailable() ) {
            return;
        }

        $permissions->defineGates();

        $this->app->make( Dispatcher::class )->listen( MigrationsEnded::class, static function ( MigrationsEnded $event ) use ( $permissions ): void {
            // Only after real forward migrations: `--pretend` promises no writes.
            if ( 'up' !== $event->method || ! empty( $event->options['pretend'] ) ) {
                return;
            }

            try {
                $permissions->sync();
            } catch ( Throwable $exception ) {
                // A partial install (cms tables not migrated yet) must not
                // fail the migration; `ecommerce:sync-permissions` retries.
                Log::channel( 'ecommerce' )->warning( 'Could not sync ecommerce permissions into cms-framework.', [ 'error' => $exception->getMessage() ] );
            }
        } );
    }

    /**
     * Loads the engine's migrations unless the host called
     * {@see Ecommerce::ignoreMigrations()} to run published copies instead.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerMigrations(): void
    {
        if ( Ecommerce::shouldRunMigrations() ) {
            $this->loadMigrationsFrom( __DIR__ . '/../../database/migrations' );
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
     * Registers {@see EcommerceSentryIntegration} when `sentry/sentry-laravel`
     * is installed and `artisanpack.ecommerce.sentry.enabled` is on (parent
     * plan §16.3). Detection is at runtime, so Sentry stays an optional
     * dependency: without it the integration provider is never loaded.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerSentryIntegration(): void
    {
        if ( ! EcommerceSentryIntegration::sentryInstalled() ) {
            return;
        }

        if ( ! (bool) $this->app['config']->get( 'artisanpack.ecommerce.sentry.enabled', true ) ) {
            return;
        }

        $this->app->register( EcommerceSentryIntegration::class );
    }

    /**
     * Loads the engine's JSON translation catalogues (parent plan §16.5).
     *
     * Every user-facing string runs through `__()` with its English source
     * as the key, so the engine reads correctly in English with no catalogue
     * at all. `lang/{en,es,fr,de}.json` ship the shipped locales. JSON paths
     * merge in registration order and the app's own `lang/{locale}.json` is
     * read last, so precedence is: shipped catalogue < published copy in
     * `lang/vendor/ecommerce/` (the `ecommerce-lang` tag) < `lang/{locale}.json`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerTranslations(): void
    {
        $this->loadJsonTranslationsFrom( __DIR__ . '/../../lang' );
        $this->loadJsonTranslationsFrom( $this->app->langPath( 'vendor/ecommerce' ) );
    }

    /**
     * Wraps the translation loader so regional locales (`de_DE`) fall back
     * to their base language's JSON catalogue (`de`). Laravel applies locale
     * fallback to PHP group files only, so without this an app running in
     * `de_DE` would get English engine strings next to German-formatted
     * money and dates. Opt out with
     * `artisanpack.ecommerce.localization.regional_fallback`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerRegionalLocaleFallback(): void
    {
        if ( ! (bool) $this->app['config']->get( 'artisanpack.ecommerce.localization.regional_fallback', true ) ) {
            return;
        }

        $this->app->extend( 'translation.loader', static function ( Loader $loader ): Loader {
            return $loader instanceof RegionalJsonFallbackLoader ? $loader : new RegionalJsonFallbackLoader( $loader );
        } );
    }

    /**
     * Aliases {@see RequestIdMiddleware} so route classes can attach it
     * with `->middleware('ecommerce.request-id')`. The middleware
     * threads a correlation ID through log context + outbound webhook
     * headers + gateway idempotency keys. Engine plan §16.3.
     *
     * Also clears {@see RequestContext} around every queued job and at the
     * start of every Octane request (audit G4): the id is static, so in a
     * long-lived worker one job's id would otherwise be reused by every
     * job after it.
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
        $router->aliasMiddleware( 'ecommerce.locale', NegotiateLocale::class );

        $reset  = static fn (): null => RequestContext::reset();
        $events = $this->app->make( Dispatcher::class );

        $events->listen( JobProcessing::class, $reset );
        $events->listen( JobProcessed::class, $reset );
        $events->listen( JobFailed::class, $reset );
        $events->listen( 'Laravel\\Octane\\Events\\RequestReceived', $reset );
    }

    /**
     * Orders the engine's auth middleware relative to Laravel's, whatever
     * order a route lists them in:
     *
     * - {@see ServiceSignatureMiddleware} runs before `auth:*`, so a signed
     *   service request is authenticated before Sanctum looks for a user;
     * - {@see EnsureEcommerceAbility} runs right after authentication and
     *   before route-model binding, so a caller without the ability gets
     *   403 for every id — never a 404 that reveals which orders exist.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerMiddlewarePriority(): void
    {
        $kernel = $this->app->make( HttpKernel::class );

        if ( method_exists( $kernel, 'addToMiddlewarePriorityAfter' ) ) {
            $kernel->addToMiddlewarePriorityAfter( AuthenticatesRequests::class, EnsureEcommerceAbility::class );

            // Idempotency runs after auth and the ability check but before
            // route-model binding, so a retried DELETE whose model is now
            // gone replays the stored success instead of a 404.
            $kernel->addToMiddlewarePriorityAfter( EnsureEcommerceAbility::class, IdempotencyMiddleware::class );
        }

        if ( method_exists( $kernel, 'addToMiddlewarePriorityBefore' ) ) {
            $kernel->addToMiddlewarePriorityBefore( AuthenticatesRequests::class, ServiceSignatureMiddleware::class );
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
        $router->aliasMiddleware( 'ecommerce.cache', CacheHeaders::class );
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
        $router->aliasMiddleware( 'ecommerce.service-signature', ServiceSignatureMiddleware::class );
        $router->aliasMiddleware( 'ecommerce.optional-auth', AuthenticateOptionally::class );
        $router->aliasMiddleware( 'ecommerce.graphql-batch', LimitGraphQLBatch::class );
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

        $registry->register(
            VariableProductType::KEY,
            VariableProductType::class,
            [ 'label' => __( 'Variable product' ), 'icon' => 'hero-squares-2x2' ],
        );

        $registry->register(
            GroupedProductType::KEY,
            GroupedProductType::class,
            [ 'label' => __( 'Grouped product' ), 'icon' => 'hero-rectangle-group' ],
        );

        $registry->register(
            BundledProductType::KEY,
            BundledProductType::class,
            [ 'label' => __( 'Bundled product' ), 'icon' => 'hero-gift' ],
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
     * traffic through a provider whose secret key is unset, and only when
     * the optional `stripe/stripe-php` package is installed.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCorePaymentGateways(): void
    {
        if ( ! $this->stripeAvailable() ) {
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
     * The core account menu entries (#179): storefronts define the
     * `ecommerce.account.*` routes. Downloads and license keys only show
     * to customers who have some.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCoreAccountMenu(): void
    {
        $menu = $this->app->make( AccountMenuRegistry::class );

        $menu->register( 'profile', [ 'label' => static fn (): string => __( 'Account details' ), 'route' => 'ecommerce.account.profile', 'icon' => 'user', 'position' => 10 ] );
        $menu->register( 'orders', [ 'label' => static fn (): string => __( 'Orders' ), 'route' => 'ecommerce.account.orders', 'icon' => 'receipt', 'position' => 20 ] );
        $menu->register( 'addresses', [ 'label' => static fn (): string => __( 'Addresses' ), 'route' => 'ecommerce.account.addresses', 'icon' => 'map-pin', 'position' => 30 ] );
        $menu->register( 'downloads', [
            'label'    => static fn (): string => __( 'Downloads' ),
            'route'    => 'ecommerce.account.downloads',
            'icon'     => 'download',
            'position' => 40,
            'visible'  => static fn ( ?Customer $customer ): bool => null !== $customer && app( DigitalDownloadService::class )->forCustomer( $customer )->exists(),
        ] );
        $menu->register( 'license-keys', [
            'label'    => static fn (): string => __( 'License keys' ),
            'route'    => 'ecommerce.account.license-keys',
            'icon'     => 'key',
            'position' => 50,
            'visible'  => static fn ( ?Customer $customer ): bool => null !== $customer && app( LicenseService::class )->forCustomer( $customer )->exists(),
        ] );
        $menu->register( 'notifications', [ 'label' => static fn (): string => __( 'Email preferences' ), 'route' => 'ecommerce.account.notifications', 'icon' => 'bell', 'position' => 60 ] );
    }

    /**
     * Registers the core search provider and keeps registered search
     * indexers in step with the catalog (#176). The active provider is
     * `search.provider`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCoreSearchProviders(): void
    {
        $this->app->make( SearchProviderRegistry::class )->register(
            DatabaseSearchProvider::KEY,
            DatabaseSearchProvider::class,
            [ 'label' => static fn (): string => __( 'Store search' ) ],
        );

        SyncSearchIndexers::register();
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

        if ( $this->stripeAvailable() ) {
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
     * Whether the built-in Stripe gateway and Radar provider can be
     * registered: the gateway is enabled and `stripe/stripe-php` (an
     * optional dependency) is installed. An enabled gateway without the SDK
     * logs an error once per boot instead of failing every request.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    protected function stripeAvailable(): bool
    {
        if ( ! (bool) $this->app['config']->get( 'artisanpack.ecommerce.gateways.stripe.enabled', false ) ) {
            return false;
        }

        if ( class_exists( StripeClient::class ) ) {
            return true;
        }

        if ( ! $this->stripeMissingLogged ) {
            $this->stripeMissingLogged = true;

            Log::channel( 'ecommerce' )->error( 'The Stripe gateway is enabled (artisanpack.ecommerce.gateways.stripe.enabled) but stripe/stripe-php is not installed, so it was not registered. Run `composer require stripe/stripe-php`.' );
        }

        return false;
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
            CartContainsProductTypeCondition::class,
            CustomerInGroupCondition::class,
            DayOfWeekCondition::class,
            CustomerFirstOrderCondition::class,
            MinQuantityCondition::class,
            CartContainsCategoryCondition::class,
            CartContainsTagCondition::class,
            CustomerLifetimeValueOverCondition::class,
            DateRangeCondition::class,
            CurrencyIsCondition::class,
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
            FixedOffProductAction::class,
        ] as $action ) {
            $actions->register( $action::KEY, $action );
        }
    }

    /**
     * Registers the built-in kanban card widgets and automation triggers
     * (parent plan §9.3 / §9.4, engine spec §5 rows 10–11). Satellites add
     * their own (`printful:order-status`, `notify-slack`, …) from their own
     * `boot()`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCoreKanban(): void
    {
        /** @var KanbanCardWidgetRegistry $widgets */
        $widgets = $this->app->make( KanbanCardWidgetRegistry::class );

        foreach ( [
            TotalWidget::class,
            ItemCountWidget::class,
            CustomerWidget::class,
            ShippingMethodWidget::class,
            TagsWidget::class,
            DaysInColumnWidget::class,
            PaymentStatusWidget::class,
            FulfillmentStatusWidget::class,
        ] as $widget ) {
            $widgets->register( $widget::KEY, $widget, [ 'provided_by' => 'ecommerce' ] );
        }

        /** @var KanbanAutomationRegistry $triggers */
        $triggers = $this->app->make( KanbanAutomationRegistry::class );

        foreach ( [
            SendEmailTrigger::class,
            DispatchJobTrigger::class,
            WebhookTrigger::class,
            UpdateOrderFieldTrigger::class,
            CreateShipmentTrigger::class,
            PrintShippingLabelTrigger::class,
        ] as $trigger ) {
            $triggers->register( $trigger::KEY, $trigger, [ 'provided_by' => 'ecommerce' ] );
        }
    }

    /**
     * Fills the settings allow-list (engine issue #145) and applies stored
     * values to config. Runs first in `boot()` so the rest of the boot
     * (gateway registration, listeners) reads the admin's values. Queue
     * workers and Octane re-apply them before each job / request so a
     * long-running worker sees changes made after it started. Gateway and
     * fraud-provider registration still happens at boot, so toggling Stripe
     * needs a worker restart.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCoreSettings(): void
    {
        // Booted by `config:cache` / `optimize`: keep stored values out of
        // the cached config file, or resetting them would never take effect.
        if ( $this->app->runningInConsole() && in_array( $_SERVER['argv'][1] ?? null, [ 'config:cache', 'optimize' ], true ) ) {
            $this->app->make( SettingsRepository::class )->setOverlayEnabled( false );
        }

        CoreSettings::register( $this->app->make( SettingsRegistry::class ) );

        $refresh = function (): void {
            $this->app->make( SettingsRepository::class )->refresh();
        };

        $events = $this->app->make( Dispatcher::class );
        $events->listen( JobProcessing::class, $refresh );
        // Octane keeps the app between requests: re-read per request.
        $events->listen( 'Laravel\\Octane\\Events\\RequestReceived', $refresh );
    }

    /**
     * Registers the core reports (engine issue #146, parent plan §10.2
     * item 7). Satellites add theirs to {@see ReportRegistry} from `boot()`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCoreReports(): void
    {
        $reports = $this->app->make( ReportRegistry::class );

        $reports->register( 'sales', SalesReport::class, [ 'label' => __( 'Sales over time' ), 'position' => 10 ] );
        $reports->register( 'top-products', TopProductsReport::class, [ 'label' => __( 'Top products' ), 'position' => 20 ] );
        $reports->register( 'revenue-by-category', CategoryRevenueReport::class, [ 'label' => __( 'Revenue by category' ), 'position' => 30 ] );
        $reports->register( 'tax', TaxCollectedReport::class, [ 'label' => __( 'Tax collected' ), 'position' => 40 ] );
        $reports->register( 'inventory', InventoryLevelsReport::class, [ 'label' => __( 'Inventory levels' ), 'position' => 50 ] );
        $reports->register( 'low-stock', LowStockReport::class, [ 'label' => __( 'Low stock' ), 'position' => 60 ] );
        $reports->register( 'summary', SummaryReport::class, [ 'label' => __( 'Summary' ), 'position' => 1000 ] );
    }

    /**
     * Wires the kanban engine: automations run on every card move; with
     * `artisanpack.ecommerce.kanban.auto_route`, orders are routed onto
     * boards when placed and re-routed when edited; with
     * `artisanpack.ecommerce.kanban.broadcast`, card moves are broadcast on
     * `private-ecommerce.kanban.board.{board}`, authorized by the
     * `kanbanBoard.view` ability.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerKanbanListeners(): void
    {
        $config = $this->app['config'];

        /** @var Dispatcher $events */
        $events = $this->app->make( Dispatcher::class );
        $events->listen( KanbanCardMoved::class, [ KanbanAutomationRunner::class, 'handle' ] );

        // Checked per order rather than at boot, so the admin setting
        // takes effect without a restart.
        addAction( 'ap.ecommerce.order.placed', function ( Order $order ): void {
            if ( (bool) $this->app['config']->get( 'artisanpack.ecommerce.kanban.auto_route', true ) ) {
                $this->app->make( KanbanRoutingService::class )->route( $order );
            }
        } );

        addAction( 'ap.ecommerce.order.edited', function ( Order $order ): void {
            if ( (bool) $this->app['config']->get( 'artisanpack.ecommerce.kanban.auto_route', true ) ) {
                $this->app->make( KanbanRoutingService::class )->reroute( $order );
            }
        } );

        if ( ! (bool) $config->get( 'artisanpack.ecommerce.kanban.broadcast', false ) ) {
            return;
        }

        $events->listen( KanbanCardMoved::class, BroadcastKanbanCardMoved::class );

        Broadcast::channel( BroadcastKanbanCardMoved::CHANNEL, function ( $user, $board ): bool {
            $model = KanbanBoard::query()->find( $board );

            return null !== $model && $this->app->make( EcommerceAuthorizer::class )->allows( $user, 'kanbanBoard', 'view', $model );
        } );
    }

    /**
     * Registers the built-in notification catalog (parent plan §14.4).
     * Satellites add their own templates from their own `boot()`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCoreNotificationTemplates(): void
    {
        /** @var NotificationTemplateRegistry $registry */
        $registry = $this->app->make( NotificationTemplateRegistry::class );

        foreach ( NotificationCatalog::definitions() as $definition ) {
            $registry->register( $definition->key(), $definition, [ 'provided_by' => 'ecommerce' ] );
        }
    }

    /**
     * Surfaces the Laravel channels the core delivers through (engine spec
     * §5 row 13).
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCoreNotificationChannels(): void
    {
        /** @var NotificationChannelRegistry $registry */
        $registry = $this->app->make( NotificationChannelRegistry::class );

        $registry->register( 'mail', 'mail', [ 'label' => static fn (): string => __( 'Email' ) ] );
        $registry->register( 'database', 'database', [ 'label' => static fn (): string => __( 'In-app' ) ] );
    }

    /**
     * Keeps each product's denormalized rating in step with its approved
     * reviews (parent plan §5.12): the aggregate is recomputed whenever a
     * review is approved, rejected, or marked as spam.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerReviewListeners(): void
    {
        $recalculate = function ( ProductReview $review ): void {
            $this->app->make( ProductRatingAggregator::class )->handle( $review );
        };

        foreach ( [ 'ap.ecommerce.review.approved', 'ap.ecommerce.review.rejected', 'ap.ecommerce.review.markedSpam' ] as $hook ) {
            addAction( $hook, $recalculate );
        }
    }

    /**
     * With `artisanpack.ecommerce.digital.auto_issue`, issues download
     * entitlements and license keys when an order is paid (parent plan
     * §5.13); with `.revoke_on_refund`, takes them back when the order is
     * fully refunded or cancelled.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerDigitalDeliveryListeners(): void
    {
        $config = $this->app['config'];

        if ( (bool) $config->get( 'artisanpack.ecommerce.digital.auto_issue', true ) ) {
            addAction( 'ap.ecommerce.payment.succeeded', function ( mixed $payment, Order $order ): void {
                $this->app->make( IssueDigitalDeliverables::class )->handle( $payment, $order );
            } );
        }

        if ( (bool) $config->get( 'artisanpack.ecommerce.digital.revoke_on_refund', true ) ) {
            addAction( 'ap.ecommerce.order.refunded', function ( Order $order, mixed $refund = null ): void {
                $this->app->make( RevokeDigitalDeliverables::class )->refunded( $order, $refund );
            } );

            addAction( 'ap.ecommerce.order.statusChanged', function ( Order $order, string $from, string $to ): void {
                $this->app->make( RevokeDigitalDeliverables::class )->statusChanged( $order, $from, $to );
            } );
        }
    }

    /**
     * Sends the notification catalog from the engine's lifecycle hooks
     * when `artisanpack.ecommerce.notifications.enabled` is on (parent
     * plan §14).
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerNotificationListeners(): void
    {
        if ( ! (bool) $this->app['config']->get( 'artisanpack.ecommerce.notifications.enabled', true ) ) {
            return;
        }

        $this->app->make( Dispatcher::class )->subscribe( SendCatalogNotifications::class );
    }

    /**
     * Registers the generic inbound-webhook route and the signed
     * notification unsubscribe routes.
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

        // Signed unsubscribe links in opt-out notification mail (H3).
        $unsubscribe = [ 'ecommerce.request-id', 'ecommerce.locale', 'ecommerce.rate-limit:ecommerce.notifications.unsubscribe', 'signed' ];

        $router->get( 'ecommerce/notifications/unsubscribe', [ NotificationUnsubscribeController::class, 'show' ] )
            ->middleware( $unsubscribe )
            ->name( 'ecommerce.notifications.unsubscribe' );
        $router->post( 'ecommerce/notifications/unsubscribe', [ NotificationUnsubscribeController::class, 'store' ] )
            ->middleware( $unsubscribe )
            ->name( 'ecommerce.notifications.unsubscribe.store' );
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

        // Signed service requests are only protected from replay when the
        // store is shared; an in-memory store forgets between requests.
        if ( [] !== (array) $config->get( 'artisanpack.ecommerce.api.services', [] ) && $this->app->isProduction() && 'array' === ServiceSignatureMiddleware::replayStoreDriver() ) {
            Log::channel( 'ecommerce' )->warning( 'The service-signature replay store uses the array cache driver; set artisanpack.ecommerce.api.signature_cache_store to a shared store.' );
        }

        /** @var Router $router */
        $router = $this->app->make( Router::class );

        // 401s on the API render as problem+json (engine spec §11.5).
        $handler = $this->app->make( ExceptionHandler::class );

        if ( method_exists( $handler, 'renderable' ) ) {
            $handler->renderable( static function ( AuthenticationException $e, $request ) {
                return $request->routeIs( 'ecommerce.api.*' )
                    ? Problem::make( 401, 'unauthenticated', __( 'Unauthenticated' ), __( 'Authentication is required.' ), $request )
                    : null;
            } );

            // Catalog writes refused by ProductService and friends (taken
            // slug or SKU, unknown type, bundle loops, …).
            $handler->renderable( static function ( ProductWriteException $e, $request ) {
                return $request->routeIs( 'ecommerce.api.*' )
                    ? Problem::make( 422, 'product-write-failed', __( 'Catalog change refused' ), $e->getMessage(), $request, $e->errors )
                    : null;
            } );

            // Customer writes refused by CustomerAddressService (missing
            // street, city, or country; bad country code; too long).
            $handler->renderable( static function ( CustomerWriteException $e, $request ) {
                return $request->routeIs( 'ecommerce.api.*' )
                    ? Problem::make( 422, 'customer-write-failed', __( 'Customer change refused' ), $e->getMessage(), $request, $e->errors )
                    : null;
            } );

            // Sub-status writes refused by OrderSubstatusService (unknown
            // system status, taken key, bad colour, still in use, …).
            $handler->renderable( static function ( OrderSubstatusWriteException $e, $request ) {
                return $request->routeIs( 'ecommerce.api.*' )
                    ? Problem::make( 422, 'substatus-write-failed', __( 'Sub-status change refused' ), $e->getMessage(), $request, $e->errors )
                    : null;
            } );

            // Settings writes refused by SettingsRepository (unknown key,
            // invalid value, unconfirmed base-currency change).
            $handler->renderable( static function ( SettingsWriteException $e, $request ) {
                return $request->routeIs( 'ecommerce.api.*' )
                    ? Problem::make( 422, 'settings-write-failed', __( 'Settings change refused' ), $e->getMessage(), $request, $e->errors )
                    : null;
            } );

            // Expected storefront cart and checkout failures (unknown product,
            // bad coupon, stock shortfall, payment in progress, …).
            $handler->renderable( static function ( CartOperationException $e, $request ) {
                return $request->routeIs( 'ecommerce.api.*' )
                    ? Problem::make( $e->httpStatus(), $e->errorCode, $e->title(), $e->getMessage(), $request, [
                        [ 'field' => $e->field, 'code' => $e->errorCode, 'message' => $e->getMessage() ],
                    ] )
                    : null;
            } );

            // Everything else on the API is problem+json too (audit F5): HTTP
            // errors keep their status (a missing model is a plain 404 that
            // never names a class), and an unexpected error is a 500 with no
            // details unless app.debug is on. Registered last, so the
            // handlers above win.
            $apiPrefix = trim( (string) $config->get( 'artisanpack.ecommerce.api_prefix', 'api/ecommerce' ), '/' );

            $handler->renderable( static function ( Throwable $e, $request ) use ( $apiPrefix ) {
                if ( ! $request->routeIs( 'ecommerce.api.*' ) && ! $request->is( $apiPrefix . '/*' ) ) {
                    return null;
                }

                if ( $e instanceof HttpResponseException || $e instanceof ValidationException || $e instanceof AuthenticationException ) {
                    return null;
                }

                if ( $e instanceof HttpExceptionInterface ) {
                    $status = $e->getStatusCode();

                    [ $slug, $title, $detail ] = match ( $status ) {
                        404     => [ 'not-found', __( 'Not found' ), __( 'The requested resource does not exist.' ) ],
                        405     => [ 'method-not-allowed', __( 'Method not allowed' ), __( 'This endpoint does not support that HTTP method.' ) ],
                        403     => [ 'forbidden', __( 'Forbidden' ), __( 'You are not allowed to do that.' ) ],
                        429     => [ 'too-many-requests', __( 'Too many requests' ), __( 'Too many requests. Try again later.' ) ],
                        default => [ 'http-' . $status, __( 'Request failed' ), null ],
                    };

                    return Problem::make( $status, $slug, $title, $detail, $request )->withHeaders( $e->getHeaders() );
                }

                if ( (bool) config( 'app.debug' ) ) {
                    return null;
                }

                return Problem::make( 500, 'server-error', __( 'Server error' ), __( 'Something went wrong on our end.' ), $request );
            } );
        }

        $router->prefix( trim( (string) $config->get( 'artisanpack.ecommerce.api_prefix', 'api/ecommerce' ), '/' ) . '/' . $config->get( 'artisanpack.ecommerce.api.version', 'v1' ) )
            ->middleware( array_values( array_unique( array_merge(
                [ 'ecommerce.json' ],
                (array) $config->get( 'artisanpack.ecommerce.api.middleware', [ 'api', 'ecommerce.request-id' ] ),
                // Added even to an older published middleware list (H2).
                [ 'ecommerce.locale' ],
            ) ) ) )
            ->name( 'ecommerce.api.' )
            ->group( __DIR__ . '/../../routes/api.php' );
    }

    /**
     * Adds the `ecommerce` schema (engine spec §10) to
     * rebing/graphql-laravel, served at `/{graphql.route.prefix}/ecommerce`,
     * when `artisanpack.ecommerce.features.graphql` is on.
     *
     * The schema's types live in their own registry (see
     * {@see \ArtisanPackUI\Ecommerce\GraphQL\TypeRegistry}), so they
     * never collide with a host app's GraphQL types; it is built on first
     * use of rebing's GraphQL service, after every provider has booted and
     * registered its `ap.ecommerce.graphql.extend` filters.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerGraphQLSchema(): void
    {
        $config = $this->app['config'];

        if ( ! class_exists( RebingGraphQL::class ) || ! (bool) $config->get( 'artisanpack.ecommerce.features.graphql', true ) ) {
            return;
        }

        $config->set( 'graphql.schemas.' . EcommerceSchema::NAME, [
            'method'               => [ 'GET', 'POST' ],
            'middleware'           => array_values( array_unique( [ ...(array) $config->get( 'artisanpack.ecommerce.graphql.middleware', [] ), 'ecommerce.locale' ] ) ),
            'execution_middleware' => [
                ValidateOperationParamsMiddleware::class,
                AutomaticPersistedQueriesMiddleware::class,
                AddAuthUserContextValueMiddleware::class,
                GuardOperations::class,
            ],
        ] );

        $register = function ( RebingGraphQL $graphql ): void {
            $graphql->addSchema( EcommerceSchema::NAME, $this->app->make( EcommerceSchema::class )->build() );
        };

        $this->app->afterResolving( RebingGraphQL::class, $register );

        if ( $this->app->resolved( RebingGraphQL::class ) ) {
            $register( $this->app->make( RebingGraphQL::class ) );
        }
    }

    /**
     * Broadcasts GraphQL subscription events (engine spec §10.4) on the
     * `private-ecommerce.admin` channel when
     * `artisanpack.ecommerce.graphql.subscriptions` is on and the optional
     * `rebing/graphql-laravel` package is installed. The channel is
     * authorized by the `order`, `product`, and `webhookSubscription`
     * `viewAny` abilities together.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerGraphQLSubscriptions(): void
    {
        if ( ! class_exists( RebingGraphQL::class ) || ! (bool) $this->app['config']->get( 'artisanpack.ecommerce.graphql.subscriptions', false ) ) {
            return;
        }

        $this->app->make( Dispatcher::class )->subscribe( BroadcastGraphQLSubscriptions::class );

        // The channel carries order, inventory, and webhook-delivery events,
        // so a member must be allowed to see all three.
        Broadcast::channel(
            Subscriptions::ADMIN_CHANNEL,
            function ( $user ): bool {
                $authorizer = $this->app->make( EcommerceAuthorizer::class );

                return $authorizer->allows( $user, 'order', 'viewAny' )
                    && $authorizer->allows( $user, 'product', 'viewAny' )
                    && $authorizer->allows( $user, 'webhookSubscription', 'viewAny' );
            },
        );
    }

    /**
     * Registers the engine policy set (engine spec §6.18) so
     * `$user->can( 'refund', $order )` works out of the box. A policy the
     * host app already registered for one of these models wins.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerPolicies(): void
    {
        $policies = [
            Product::class              => ProductPolicy::class,
            Order::class                => OrderPolicy::class,
            Refund::class               => RefundPolicy::class,
            Customer::class             => CustomerPolicy::class,
            CustomerAddress::class      => CustomerAddressPolicy::class,
            Promotion::class            => PromotionPolicy::class,
            Coupon::class               => CouponPolicy::class,
            TaxRate::class              => TaxRatePolicy::class,
            TaxClass::class             => TaxRatePolicy::class,
            ShippingZone::class         => ShippingZonePolicy::class,
            ShippingMethod::class       => ShippingZonePolicy::class,
            WebhookSubscription::class  => WebhookSubscriptionPolicy::class,
            KanbanBoard::class          => KanbanBoardPolicy::class,
            KanbanColumn::class         => KanbanBoardPolicy::class,
            KanbanAutomation::class     => KanbanBoardPolicy::class,
            OrderBoardAssignment::class => KanbanCardPolicy::class,
            ProductReview::class        => ReviewPolicy::class,
            DigitalFile::class          => DigitalFilePolicy::class,
            LicenseKey::class           => LicenseKeyPolicy::class,
            NotificationTemplate::class => NotificationTemplatePolicy::class,
            OrderSubstatus::class       => OrderSubstatusPolicy::class,
            InventoryItem::class        => InventoryPolicy::class,
            EcommerceSetting::class     => SettingsPolicy::class,
            Report::class               => ReportPolicy::class,
        ];

        $registered = Gate::policies();

        foreach ( $policies as $model => $policy ) {
            if ( ! array_key_exists( $model, $registered ) ) {
                Gate::policy( $model, $policy );
            }
        }
    }

    /**
     * The engine's side of the cms-framework integration (engine issue
     * #151): once every provider has booted — so satellites' additions to
     * `ap.ecommerce.abilities.catalog` count — define the engine abilities
     * as Gates rbac can grant, and sync the RBAC permissions and the
     * `shop-manager` role after each `migrate` run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCmsFrameworkPermissions(): void
    {
        $this->app->booted( function (): void {
            $this->bootCmsFrameworkPermissions( $this->app->make( CmsFrameworkPermissions::class ) );
        } );
    }

    /**
     * Bridges the domain events listed in
     * `artisanpack.ecommerce.webhooks.events` to outbound webhook
     * deliveries (engine spec §8.2).
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerWebhookListeners(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make( Dispatcher::class );

        foreach ( (array) $this->app['config']->get( 'artisanpack.ecommerce.webhooks.events', [] ) as $event ) {
            if ( is_string( $event ) && class_exists( $event ) ) {
                $events->listen( $event, DispatchWebhooksForEvent::class );
            }
        }
    }

    /**
     * Wires the customer-lifecycle listeners: on verified-email registration,
     * back-fill `customers.user_id` for the shopper (engine spec §5.8 / §3.22),
     * on login merge the guest cart into the account cart (parent plan §7.1),
     * keep customer stats current, and on a settled payment fire the `customer.firstOrder` /
     * `customer.becameVip` milestones.
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
        $events->listen( Login::class, MergeGuestCartOnLogin::class );

        $this->app->make( TrackCustomerMilestones::class )->subscribe();
        $this->app->make( UpdateCustomerStats::class )->subscribe();
    }

    /**
     * Applies the `registered*` filters (payment gateways, shipping method
     * types, product types, discount types) once every provider has booted,
     * so satellites can add their filter callbacks from any `boot()`
     * regardless of provider order. See {@see RegistryHookRegistrar}.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerRegistryHooks(): void
    {
        $this->app->booted( function (): void {
            $this->app->make( RegistryHookRegistrar::class )->apply();
        } );
    }

    /**
     * Observes product, variant, price, customer, promotion, and coupon
     * writes for the activity log (engine issue #147). The observer checks
     * `artisanpack.ecommerce.activity_log.enabled` on every write, so the
     * switch also works when flipped at runtime.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerActivityLogObservers(): void
    {
        foreach ( RecordModelActivity::MODELS as $model ) {
            $model::observe( RecordModelActivity::class );
        }
    }
}
