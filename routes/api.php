<?php

/**
 * Ecommerce REST routes (`/api/ecommerce/v1`).
 *
 * Loaded by {@see \ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider::registerRestRoutes()}
 * inside a group carrying the prefix, `ecommerce.api.` name prefix, and the
 * `artisanpack.ecommerce.api.middleware` stack. Each route adds its own
 * auth / ability / rate-limit / idempotency middleware per engine spec §9:
 *
 * - public reads   → `ecommerce.catalog.read` rate policy;
 * - cart by token  → `ecommerce.cart.mutate` rate policy;
 * - admin reads    → auth + `ecommerce.can:{resource},{action}` + `ecommerce.admin.mutate`;
 * - admin writes   → the above + `ecommerce.idempotency` (Idempotency-Key required).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\CartController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\CouponController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\CustomerController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\InventoryController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\OrderController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\OrderShipmentController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ProductController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\PromotionController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ShippingMethodController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ShippingZoneController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\TaxClassController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\TaxRateController;
use Illuminate\Support\Facades\Route;

$auth = (array) config( 'artisanpack.ecommerce.api.auth_middleware', [ 'auth:sanctum' ] );

/**
 * Middleware for an admin-gated route.
 *
 * @var Closure(string, string, bool): array<int, string> $admin
 */
$admin = static fn ( string $resource, string $action, bool $mutates = false ): array => array_merge(
    $auth,
    [ sprintf( 'ecommerce.can:%s,%s', $resource, $action ), 'ecommerce.rate-limit:ecommerce.admin.mutate' ],
    $mutates ? [ 'ecommerce.idempotency' ] : [],
);

// Catalog (public).
Route::middleware( 'ecommerce.rate-limit:ecommerce.catalog.read' )->group( function (): void {
    Route::get( 'products', [ ProductController::class, 'index' ] )->name( 'products.index' );
    Route::get( 'products/{product}', [ ProductController::class, 'show' ] )->whereNumber( 'product' )->name( 'products.show' );
    Route::get( 'products/{product}/variants', [ ProductController::class, 'variants' ] )->whereNumber( 'product' )->name( 'products.variants' );
} );

// Cart (token is the credential).
Route::get( 'carts/{cart}', [ CartController::class, 'show' ] )
    ->where( 'cart', '[A-Za-z0-9]{40}' )
    ->middleware( 'ecommerce.rate-limit:ecommerce.cart.mutate' )
    ->name( 'carts.show' );

// Orders.
Route::get( 'orders', [ OrderController::class, 'index' ] )->middleware( $admin( 'order', 'viewAny' ) )->name( 'orders.index' );
Route::get( 'orders/{order}', [ OrderController::class, 'show' ] )->middleware( $admin( 'order', 'view' ) )->name( 'orders.show' );
Route::patch( 'orders/{order}', [ OrderController::class, 'update' ] )->middleware( $admin( 'order', 'update', true ) )->name( 'orders.update' );
Route::post( 'orders/{order}/shipments', [ OrderShipmentController::class, 'store' ] )->middleware( $admin( 'order', 'update', true ) )->name( 'orders.shipments.store' );
Route::patch( 'orders/{order}/shipments/{shipment}', [ OrderShipmentController::class, 'update' ] )
    ->scopeBindings()
    ->middleware( $admin( 'order', 'update', true ) )
    ->name( 'orders.shipments.update' );

// Customers.
Route::get( 'customers', [ CustomerController::class, 'index' ] )->middleware( $admin( 'customer', 'viewAny' ) )->name( 'customers.index' );
Route::get( 'customers/{customer}', [ CustomerController::class, 'show' ] )->middleware( $admin( 'customer', 'view' ) )->name( 'customers.show' );
Route::patch( 'customers/{customer}', [ CustomerController::class, 'update' ] )->middleware( $admin( 'customer', 'update', true ) )->name( 'customers.update' );

Route::prefix( 'admin' )->name( 'admin.' )->group( function () use ( $admin ): void {
    // Inventory.
    Route::get( 'inventory', [ InventoryController::class, 'index' ] )->middleware( $admin( 'product', 'viewAny' ) )->name( 'inventory.index' );

    // Tax.
    Route::get( 'tax-classes', [ TaxClassController::class, 'index' ] )->middleware( $admin( 'taxRate', 'viewAny' ) )->name( 'tax-classes.index' );
    Route::post( 'tax-classes', [ TaxClassController::class, 'store' ] )->middleware( $admin( 'taxRate', 'create', true ) )->name( 'tax-classes.store' );
    Route::get( 'tax-rates', [ TaxRateController::class, 'index' ] )->middleware( $admin( 'taxRate', 'viewAny' ) )->name( 'tax-rates.index' );
    Route::post( 'tax-rates', [ TaxRateController::class, 'store' ] )->middleware( $admin( 'taxRate', 'create', true ) )->name( 'tax-rates.store' );
    Route::patch( 'tax-rates/{rate}', [ TaxRateController::class, 'update' ] )->middleware( $admin( 'taxRate', 'update', true ) )->name( 'tax-rates.update' );
    Route::delete( 'tax-rates/{rate}', [ TaxRateController::class, 'destroy' ] )->middleware( $admin( 'taxRate', 'delete', true ) )->name( 'tax-rates.destroy' );

    // Shipping.
    Route::get( 'shipping-zones', [ ShippingZoneController::class, 'index' ] )->middleware( $admin( 'shippingZone', 'viewAny' ) )->name( 'shipping-zones.index' );
    Route::post( 'shipping-zones', [ ShippingZoneController::class, 'store' ] )->middleware( $admin( 'shippingZone', 'create', true ) )->name( 'shipping-zones.store' );
    Route::patch( 'shipping-zones/{zone}', [ ShippingZoneController::class, 'update' ] )->middleware( $admin( 'shippingZone', 'update', true ) )->name( 'shipping-zones.update' );
    Route::delete( 'shipping-zones/{zone}', [ ShippingZoneController::class, 'destroy' ] )->middleware( $admin( 'shippingZone', 'delete', true ) )->name( 'shipping-zones.destroy' );
    Route::post( 'shipping-zones/{zone}/methods', [ ShippingMethodController::class, 'store' ] )->middleware( $admin( 'shippingZone', 'update', true ) )->name( 'shipping-methods.store' );
    Route::patch( 'shipping-methods/{method}', [ ShippingMethodController::class, 'update' ] )->middleware( $admin( 'shippingZone', 'update', true ) )->name( 'shipping-methods.update' );
    Route::delete( 'shipping-methods/{method}', [ ShippingMethodController::class, 'destroy' ] )->middleware( $admin( 'shippingZone', 'update', true ) )->name( 'shipping-methods.destroy' );

    // Promotions + coupons.
    Route::get( 'promotions', [ PromotionController::class, 'index' ] )->middleware( $admin( 'promotion', 'viewAny' ) )->name( 'promotions.index' );
    Route::post( 'promotions', [ PromotionController::class, 'store' ] )->middleware( $admin( 'promotion', 'create', true ) )->name( 'promotions.store' );
    Route::patch( 'promotions/{promotion}', [ PromotionController::class, 'update' ] )->middleware( $admin( 'promotion', 'update', true ) )->name( 'promotions.update' );
    Route::delete( 'promotions/{promotion}', [ PromotionController::class, 'destroy' ] )->middleware( $admin( 'promotion', 'delete', true ) )->name( 'promotions.destroy' );
    Route::post( 'promotions/{promotion}/coupons', [ CouponController::class, 'store' ] )->middleware( $admin( 'coupon', 'create', true ) )->name( 'coupons.store' );
    Route::patch( 'coupons/{coupon}', [ CouponController::class, 'update' ] )->middleware( $admin( 'coupon', 'update', true ) )->name( 'coupons.update' );
    Route::delete( 'coupons/{coupon}', [ CouponController::class, 'destroy' ] )->middleware( $admin( 'coupon', 'delete', true ) )->name( 'coupons.destroy' );
} );
