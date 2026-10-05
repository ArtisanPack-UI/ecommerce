<?php

/**
 * Main Ecommerce class.
 *
 * The engine's front door, reached through the `Ecommerce` facade or the
 * `ecommerce()` helper: the installed version, migration control, and
 * typed accessors for the main services.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce;

use ArtisanPackUI\Ecommerce\Catalog\CatalogQuery;
use ArtisanPackUI\Ecommerce\Orders\OrderServices;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use ArtisanPackUI\Ecommerce\Services\InventoryService;
use ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use Composer\InstalledVersions;
use Throwable;

/**
 * Main Ecommerce class: the engine's entry point behind the `Ecommerce`
 * facade and the `ecommerce()` helper (audit I4). Besides
 * {@see self::version()} and {@see self::ignoreMigrations()}, it hands out
 * the main services, resolved from the container so host bindings apply:
 *
 *     ecommerce()->cart()->addItem( $cart, $productId, null, 2 );
 *     ecommerce()->checkout()->finalize( $cart, $paymentReference );
 *     ecommerce()->orders()->refunds->issue( $order, $lines );
 *     ecommerce()->catalog()->inCategory( 'mugs' )->onSale()->builder()->paginate();
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class Ecommerce
{
    /**
     * The Composer package name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PACKAGE = 'artisanpack-ui/ecommerce';

    /**
     * Whether the service provider loads the engine's migrations. Turned
     * off by {@see self::ignoreMigrations()}.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public static bool $runsMigrations = true;

    /**
     * Stops the service provider from loading the engine's migrations, for
     * hosts that publish them (`vendor:publish --tag=ecommerce-migrations`)
     * and run their own copies. Call it from a service provider's
     * `register()` method.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public static function ignoreMigrations(): static
    {
        static::$runsMigrations = false;

        return new static();
    }

    /**
     * Whether the service provider should load the engine's migrations.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public static function shouldRunMigrations(): bool
    {
        return static::$runsMigrations;
    }

    /**
     * The installed engine version (`1.0.0`), or `dev` when Composer can't
     * report it (a path repository without a version, for example).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function version(): string
    {
        try {
            if ( class_exists( InstalledVersions::class ) && InstalledVersions::isInstalled( self::PACKAGE ) ) {
                return (string) ( InstalledVersions::getPrettyVersion( self::PACKAGE ) ?? 'dev' );
            }
        } catch ( Throwable ) {
            // Composer's runtime data is unavailable; report a development build.
        }

        return 'dev';
    }

    /**
     * Storefront carts: create, add and change lines, coupons, shipping
     * quotes, totals.
     *
     * @since 1.0.0
     *
     * @return StorefrontCartService
     */
    public function cart(): StorefrontCartService
    {
        return app( StorefrontCartService::class );
    }

    /**
     * Checkout: email, addresses, shipping and payment choice, payment
     * sessions, finalizing into an order.
     *
     * @since 1.0.0
     *
     * @return CheckoutService
     */
    public function checkout(): CheckoutService
    {
        return app( CheckoutService::class );
    }

    /**
     * The order services: placement, status, edits, cancellation, refunds,
     * shipments, and notes.
     *
     * @since 1.0.0
     *
     * @return OrderServices
     */
    public function orders(): OrderServices
    {
        return app( OrderServices::class );
    }

    /**
     * Payments: capture, finalize, and resume through the registered
     * gateways.
     *
     * @since 1.0.0
     *
     * @return PaymentOrchestrator
     */
    public function payments(): PaymentOrchestrator
    {
        return app( PaymentOrchestrator::class );
    }

    /**
     * A fresh storefront catalog query (filters, sorts, facets).
     *
     * @since 1.0.0
     *
     * @return CatalogQuery
     */
    public function catalog(): CatalogQuery
    {
        return app( CatalogQuery::class );
    }

    /**
     * Customers: find or create by email, link users, profiles, GDPR
     * delete.
     *
     * @since 1.0.0
     *
     * @return CustomerService
     */
    public function customers(): CustomerService
    {
        return app( CustomerService::class );
    }

    /**
     * Stock: adjust, reserve, release.
     *
     * @since 1.0.0
     *
     * @return InventoryService
     */
    public function inventory(): InventoryService
    {
        return app( InventoryService::class );
    }
}
