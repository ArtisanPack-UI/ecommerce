<?php

/**
 * Ecommerce REST routes (`/api/ecommerce/v1`).
 *
 * Loaded by {@see ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider::registerRestRoutes()}
 * inside a group carrying the prefix, `ecommerce.api.` name prefix, and the
 * `artisanpack.ecommerce.api.middleware` stack. Each route adds its own
 * auth / ability / rate-limit / idempotency middleware per engine spec §9:
 *
 * - public reads   → `ecommerce.catalog.read` rate policy;
 * - cart by token  → `ecommerce.cart.mutate` rate policy (coupon attempts use
 *                    `ecommerce.coupon.attempt`); cart writes also require
 *                    an Idempotency-Key;
 * - admin reads    → auth + `ecommerce.can:{resource},{action}` + `ecommerce.admin.mutate`;
 * - admin writes   → the above + `ecommerce.idempotency` (Idempotency-Key required);
 * - downloads      → the token in the URL is the credential (`ecommerce.catalog.read`);
 * - license check  → public, `ecommerce.license.validate` + Idempotency-Key;
 * - me/*           → auth + `ecommerce.admin.mutate` (the shopper's own data).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ActivityLogController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\CartController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\CatalogController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\CheckoutController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ConfigCatalogController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\CouponController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\CustomerAddressController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\CustomerController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\CustomerNoteController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\DigitalDownloadController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\DigitalFileController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\InventoryController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\KanbanAssignmentController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\KanbanAutomationController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\KanbanBoardController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\KanbanCardController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\KanbanCatalogController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\KanbanColumnController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\LicenseKeyController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\MeController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\NotificationPreferenceController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\NotificationTemplateController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\OrderCancelController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\OrderController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\OrderNoteController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\OrderRefundController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\OrderShipmentController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\OrderSubstatusController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\OrderTimelineController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ProductAdminController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ProductAttributeController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ProductCategoryController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ProductController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ProductImageController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ProductLinkController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ProductPriceController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ProductReviewController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ProductTagController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ProductVariantController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\PromotionController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ReportController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ReviewController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\SearchController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\SettingsController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ShippingMethodController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\ShippingZoneController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\TaxClassController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\TaxRateController;
use ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\WebhookSubscriptionController;
use Illuminate\Support\Facades\Route;

$auth = (array) config( 'artisanpack.ecommerce.api.auth_middleware', [ 'ecommerce.service-signature', 'auth:sanctum' ] );

/**
 * Middleware for an admin-gated route.
 *
 * @var Closure(string, string, bool=): array<int, string> $admin
 */
$admin = static fn ( string $resource, string $action, bool $mutates = false ): array => array_merge(
    $auth,
    [ sprintf( 'ecommerce.can:%s,%s', $resource, $action ), 'ecommerce.rate-limit:ecommerce.admin.mutate' ],
    $mutates ? [ 'ecommerce.idempotency' ] : [],
);

// Catalog (public).
Route::middleware( [ 'ecommerce.rate-limit:ecommerce.catalog.read', 'ecommerce.cache:public' ] )->group( function (): void {
    Route::get( 'products', [ ProductController::class, 'index' ] )->name( 'products.index' );
    Route::get( 'products/{product}', [ ProductController::class, 'show' ] )->whereNumber( 'product' )->name( 'products.show' );
    Route::get( 'products/{product}/variants', [ ProductController::class, 'variants' ] )->whereNumber( 'product' )->name( 'products.variants' );
    Route::get( 'products/{product}/reviews', [ ProductReviewController::class, 'index' ] )->whereNumber( 'product' )->name( 'products.reviews.index' );
    Route::get( 'search', [ SearchController::class, 'index' ] )->name( 'search' );
    Route::get( 'categories', [ CatalogController::class, 'categories' ] )->name( 'categories.index' );
    Route::get( 'categories/{category}', [ CatalogController::class, 'category' ] )->where( 'category', '[A-Za-z0-9_-]+' )->name( 'categories.show' );
    Route::get( 'categories/{category}/products', [ CatalogController::class, 'categoryProducts' ] )->where( 'category', '[A-Za-z0-9_-]+' )->name( 'categories.products' );
    Route::get( 'tags', [ CatalogController::class, 'tags' ] )->name( 'tags.index' );
} );

// Reviews (engine spec §9.1): signed-in customers or, when allowed, guests.
Route::post( 'products/{product}/reviews', [ ProductReviewController::class, 'store' ] )
    ->whereNumber( 'product' )
    ->middleware( [ 'ecommerce.optional-auth', 'ecommerce.rate-limit:ecommerce.review.submit', 'ecommerce.idempotency' ] )
    ->name( 'products.reviews.store' );

// Digital delivery (engine spec §9.9): the token is the credential.
Route::where( [ 'token' => '[A-Za-z0-9]{64}' ] )
    ->middleware( 'ecommerce.rate-limit:ecommerce.catalog.read' )
    ->group( function (): void {
        Route::get( 'downloads/{token}', [ DigitalDownloadController::class, 'show' ] )->name( 'downloads.show' );
        Route::get( 'downloads/{token}/stream', [ DigitalDownloadController::class, 'stream' ] )->name( 'downloads.stream' );
    } );

Route::post( 'license/validate', [ LicenseKeyController::class, 'validateKey' ] )
    ->middleware( [ 'ecommerce.rate-limit:ecommerce.license.validate', 'ecommerce.idempotency' ] )
    ->name( 'license.validate' );
Route::post( 'license/deactivate', [ LicenseKeyController::class, 'deactivate' ] )
    ->middleware( [ 'ecommerce.rate-limit:ecommerce.license.validate', 'ecommerce.idempotency' ] )
    ->name( 'license.deactivate' );

// Cart (token is the credential).
Route::post( 'carts', [ CartController::class, 'store' ] )
    ->middleware( [ 'ecommerce.optional-auth', 'ecommerce.rate-limit:ecommerce.cart.mutate', 'ecommerce.idempotency' ] )
    ->name( 'carts.store' );

// A guest cart's token is its credential; an account's cart also needs that
// account's session or Sanctum token, so these routes resolve the user.
Route::where( [ 'cart' => '[A-Za-z0-9]{40}', 'item' => '[0-9]+' ] )->middleware( [ 'ecommerce.optional-auth', 'ecommerce.cache:private' ] )->group( function (): void {
    Route::get( 'carts/{cart}', [ CartController::class, 'show' ] )
        ->middleware( 'ecommerce.rate-limit:ecommerce.cart.mutate' )
        ->name( 'carts.show' );
    Route::get( 'carts/{cart}/shipping-rates', [ CartController::class, 'shippingRates' ] )
        ->middleware( 'ecommerce.rate-limit:ecommerce.cart.mutate' )
        ->name( 'carts.shipping-rates.index' );

    Route::middleware( [ 'ecommerce.rate-limit:ecommerce.cart.mutate', 'ecommerce.idempotency' ] )->group( function (): void {
        Route::post( 'carts/{cart}/items', [ CartController::class, 'addItem' ] )->name( 'carts.items.store' );
        Route::patch( 'carts/{cart}/items/{item}', [ CartController::class, 'updateItem' ] )->name( 'carts.items.update' );
        Route::delete( 'carts/{cart}/items/{item}', [ CartController::class, 'removeItem' ] )->name( 'carts.items.destroy' );
        Route::delete( 'carts/{cart}/coupons/{code}', [ CartController::class, 'removeCoupon' ] )->name( 'carts.coupons.destroy' );
        Route::post( 'carts/{cart}/merge', [ CartController::class, 'merge' ] )->name( 'carts.merge' );
        Route::patch( 'carts/{cart}', [ CartController::class, 'update' ] )->name( 'carts.update' );
        Route::delete( 'carts/{cart}/items', [ CartController::class, 'clear' ] )->name( 'carts.items.clear' );
        Route::put( 'carts/{cart}/shipping-rate', [ CartController::class, 'selectShippingRate' ] )->name( 'carts.shipping-rate.update' );
    } );

    Route::post( 'carts/{cart}/coupons', [ CartController::class, 'applyCoupon' ] )
        ->middleware( [ 'ecommerce.rate-limit:ecommerce.coupon.attempt', 'ecommerce.idempotency' ] )
        ->name( 'carts.coupons.store' );
} );

// Checkout (engine spec §9.2): the cart token is the credential for a guest
// cart; an account's cart also needs that account's session.
Route::where( [ 'cart' => '[A-Za-z0-9]{40}' ] )->middleware( [ 'ecommerce.optional-auth', 'ecommerce.cache:private' ] )->group( function (): void {
    Route::get( 'checkout/{cart}', [ CheckoutController::class, 'show' ] )
        ->middleware( 'ecommerce.rate-limit:ecommerce.cart.mutate' )
        ->name( 'checkout.show' );

    Route::middleware( [ 'ecommerce.rate-limit:ecommerce.cart.mutate', 'ecommerce.idempotency' ] )->group( function (): void {
        Route::post( 'checkout/{cart}/start', [ CheckoutController::class, 'start' ] )->name( 'checkout.start' );
        Route::post( 'checkout/{cart}/address', [ CheckoutController::class, 'address' ] )->name( 'checkout.address' );
        Route::post( 'checkout/{cart}/shipping-method', [ CheckoutController::class, 'shippingMethod' ] )->name( 'checkout.shipping-method' );
        Route::post( 'checkout/{cart}/payment-gateway', [ CheckoutController::class, 'paymentGateway' ] )->name( 'checkout.payment-gateway' );
    } );

    Route::middleware( [ 'ecommerce.rate-limit:ecommerce.checkout.finalize', 'ecommerce.idempotency' ] )->group( function (): void {
        Route::post( 'checkout/{cart}/session', [ CheckoutController::class, 'session' ] )->name( 'checkout.session' );
        Route::post( 'checkout/{cart}/finalize', [ CheckoutController::class, 'finalize' ] )->name( 'checkout.finalize' );
    } );
} );

// Orders.
Route::get( 'orders', [ OrderController::class, 'index' ] )->middleware( $admin( 'order', 'viewAny' ) )->name( 'orders.index' );
Route::get( 'orders/{order}', [ OrderController::class, 'show' ] )->middleware( $admin( 'order', 'view' ) )->name( 'orders.show' );
Route::patch( 'orders/{order}', [ OrderController::class, 'update' ] )->middleware( $admin( 'order', 'update', true ) )->name( 'orders.update' );
Route::post( 'orders/{order}/cancel', [ OrderCancelController::class, 'store' ] )->middleware( $admin( 'order', 'cancel', true ) )->name( 'orders.cancel' );
Route::post( 'orders/{order}/refunds', [ OrderRefundController::class, 'store' ] )->middleware( $admin( 'order', 'refund', true ) )->name( 'orders.refunds.store' );
Route::get( 'orders/{order}/timeline', [ OrderTimelineController::class, 'index' ] )->middleware( $admin( 'order', 'view' ) )->name( 'orders.timeline.index' );
Route::post( 'orders/{order}/notes', [ OrderNoteController::class, 'store' ] )->middleware( $admin( 'order', 'update', true ) )->name( 'orders.notes.store' );
Route::post( 'orders/{order}/shipments', [ OrderShipmentController::class, 'store' ] )->middleware( $admin( 'order', 'update', true ) )->name( 'orders.shipments.store' );
Route::patch( 'orders/{order}/shipments/{shipment}', [ OrderShipmentController::class, 'update' ] )
    ->scopeBindings()
    ->middleware( $admin( 'order', 'update', true ) )
    ->name( 'orders.shipments.update' );

// Customers.
Route::get( 'customers', [ CustomerController::class, 'index' ] )->middleware( $admin( 'customer', 'viewAny' ) )->name( 'customers.index' );
Route::get( 'customers/{customer}', [ CustomerController::class, 'show' ] )->middleware( $admin( 'customer', 'view' ) )->name( 'customers.show' );
Route::patch( 'customers/{customer}', [ CustomerController::class, 'update' ] )->middleware( $admin( 'customer', 'update', true ) )->name( 'customers.update' );
Route::delete( 'customers/{customer}', [ CustomerController::class, 'destroy' ] )->middleware( $admin( 'customer', 'delete', true ) )->name( 'customers.destroy' );
Route::post( 'customers/{customer}/addresses', [ CustomerAddressController::class, 'store' ] )->middleware( $admin( 'customer', 'update', true ) )->name( 'customers.addresses.store' );
Route::patch( 'customers/{customer}/addresses/{address}', [ CustomerAddressController::class, 'update' ] )
    ->scopeBindings()
    ->middleware( $admin( 'customer', 'update', true ) )
    ->name( 'customers.addresses.update' );
Route::delete( 'customers/{customer}/addresses/{address}', [ CustomerAddressController::class, 'destroy' ] )
    ->scopeBindings()
    ->middleware( $admin( 'customer', 'update', true ) )
    ->name( 'customers.addresses.destroy' );
Route::get( 'customers/{customer}/notes', [ CustomerNoteController::class, 'index' ] )->middleware( $admin( 'customer', 'view' ) )->name( 'customers.notes.index' );
Route::post( 'customers/{customer}/notes', [ CustomerNoteController::class, 'store' ] )->middleware( $admin( 'customer', 'update', true ) )->name( 'customers.notes.store' );
Route::delete( 'customers/{customer}/notes/{note}', [ CustomerNoteController::class, 'destroy' ] )
    ->scopeBindings()
    ->middleware( $admin( 'customer', 'update', true ) )
    ->name( 'customers.notes.destroy' );

// The signed-in shopper (engine spec §9.4).
Route::get( 'me/notification-preferences', [ NotificationPreferenceController::class, 'show' ] )
    ->middleware( array_merge( $auth, [ 'ecommerce.rate-limit:ecommerce.admin.mutate' ] ) )
    ->name( 'me.notification-preferences.show' );
Route::patch( 'me/notification-preferences', [ NotificationPreferenceController::class, 'update' ] )
    ->middleware( array_merge( $auth, [ 'ecommerce.rate-limit:ecommerce.admin.mutate', 'ecommerce.idempotency' ] ) )
    ->name( 'me.notification-preferences.update' );

Route::where( [ 'order' => '[0-9]+', 'address' => '[0-9]+' ] )->group( function () use ( $auth ): void {
    Route::middleware( array_merge( $auth, [ 'ecommerce.rate-limit:ecommerce.admin.mutate' ] ) )->group( function (): void {
        Route::get( 'me', [ MeController::class, 'show' ] )->name( 'me.show' );
        Route::get( 'me/addresses', [ MeController::class, 'addresses' ] )->name( 'me.addresses.index' );
        Route::get( 'me/orders', [ MeController::class, 'orders' ] )->name( 'me.orders.index' );
        Route::get( 'me/orders/{order}', [ MeController::class, 'order' ] )->name( 'me.orders.show' );
    } );

    Route::middleware( array_merge( $auth, [ 'ecommerce.rate-limit:ecommerce.admin.mutate', 'ecommerce.idempotency' ] ) )->group( function (): void {
        Route::patch( 'me', [ MeController::class, 'update' ] )->name( 'me.update' );
        Route::post( 'me/addresses', [ MeController::class, 'storeAddress' ] )->name( 'me.addresses.store' );
        Route::patch( 'me/addresses/{address}', [ MeController::class, 'updateAddress' ] )->name( 'me.addresses.update' );
        Route::delete( 'me/addresses/{address}', [ MeController::class, 'destroyAddress' ] )->name( 'me.addresses.destroy' );
    } );

    Route::post( 'me/claims', [ MeController::class, 'claim' ] )
        ->middleware( array_merge( $auth, [ 'ecommerce.rate-limit:ecommerce.claim.attempt', 'ecommerce.idempotency' ] ) )
        ->name( 'me.claims.store' );
} );

// Kanban (engine spec §9.10, parent plan §9.5).
Route::prefix( 'kanban' )
    ->name( 'kanban.' )
    ->where( [ 'board' => '[0-9]+', 'column' => '[0-9]+', 'automation' => '[0-9]+', 'order' => '[0-9]+' ] )
    ->group( function () use ( $admin ): void {
        Route::get( 'boards', [ KanbanBoardController::class, 'index' ] )->middleware( $admin( 'kanbanBoard', 'viewAny' ) )->name( 'boards.index' );
        Route::post( 'boards', [ KanbanBoardController::class, 'store' ] )->middleware( $admin( 'kanbanBoard', 'create', true ) )->name( 'boards.store' );
        Route::get( 'boards/{board}', [ KanbanBoardController::class, 'show' ] )->middleware( $admin( 'kanbanBoard', 'view' ) )->name( 'boards.show' );
        Route::patch( 'boards/{board}', [ KanbanBoardController::class, 'update' ] )->middleware( $admin( 'kanbanBoard', 'update', true ) )->name( 'boards.update' );
        Route::delete( 'boards/{board}', [ KanbanBoardController::class, 'destroy' ] )->middleware( $admin( 'kanbanBoard', 'delete', true ) )->name( 'boards.destroy' );
        Route::get( 'boards/{board}/cards', [ KanbanCardController::class, 'index' ] )->middleware( $admin( 'kanbanBoard', 'view' ) )->name( 'cards.index' );
        Route::post( 'cards/{order}/move', [ KanbanCardController::class, 'move' ] )->middleware( $admin( 'kanbanCard', 'move', true ) )->name( 'cards.move' );

        Route::post( 'boards/{board}/columns', [ KanbanColumnController::class, 'store' ] )->middleware( $admin( 'kanbanBoard', 'update', true ) )->name( 'columns.store' );
        Route::patch( 'columns/{column}', [ KanbanColumnController::class, 'update' ] )->middleware( $admin( 'kanbanBoard', 'update', true ) )->name( 'columns.update' );
        Route::delete( 'columns/{column}', [ KanbanColumnController::class, 'destroy' ] )->middleware( $admin( 'kanbanBoard', 'update', true ) )->name( 'columns.destroy' );

        Route::post( 'boards/{board}/automations', [ KanbanAutomationController::class, 'store' ] )->middleware( $admin( 'kanbanBoard', 'update', true ) )->name( 'automations.store' );
        Route::patch( 'automations/{automation}', [ KanbanAutomationController::class, 'update' ] )->middleware( $admin( 'kanbanBoard', 'update', true ) )->name( 'automations.update' );
        Route::delete( 'automations/{automation}', [ KanbanAutomationController::class, 'destroy' ] )->middleware( $admin( 'kanbanBoard', 'update', true ) )->name( 'automations.destroy' );

        Route::post( 'boards/{board}/assignments/{order}', [ KanbanAssignmentController::class, 'store' ] )->middleware( $admin( 'kanbanCard', 'move', true ) )->name( 'assignments.store' );
        Route::delete( 'boards/{board}/assignments/{order}', [ KanbanAssignmentController::class, 'destroy' ] )->middleware( $admin( 'kanbanCard', 'move', true ) )->name( 'assignments.destroy' );

        Route::get( 'widgets', [ KanbanCatalogController::class, 'widgets' ] )->middleware( $admin( 'kanbanBoard', 'viewAny' ) )->name( 'widgets.index' );
        Route::get( 'triggers', [ KanbanCatalogController::class, 'triggers' ] )->middleware( $admin( 'kanbanBoard', 'viewAny' ) )->name( 'triggers.index' );
    } );

Route::prefix( 'admin' )->name( 'admin.' )->group( function () use ( $admin, $auth ): void {
    // Catalog (engine spec §9.5): products and everything hanging off them.
    Route::where( [ 'product' => '[0-9]+', 'variant' => '[0-9]+', 'price' => '[0-9]+', 'image' => '[0-9]+', 'attribute' => '[0-9]+', 'category' => '[0-9]+', 'tag' => '[0-9]+' ] )->group( function () use ( $admin, $auth ): void {
        Route::get( 'products', [ ProductAdminController::class, 'index' ] )->middleware( $admin( 'product', 'viewAny' ) )->name( 'products.index' );
        Route::post( 'products', [ ProductAdminController::class, 'store' ] )->middleware( $admin( 'product', 'create', true ) )->name( 'products.store' );
        Route::get( 'products/{product}', [ ProductAdminController::class, 'show' ] )->middleware( $admin( 'product', 'view' ) )->name( 'products.show' );
        Route::patch( 'products/{product}', [ ProductAdminController::class, 'update' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.update' );
        Route::delete( 'products/{product}', [ ProductAdminController::class, 'destroy' ] )->middleware( $admin( 'product', 'delete', true ) )->name( 'products.destroy' );

        Route::post( 'products/{product}/variants', [ ProductVariantController::class, 'store' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.variants.store' );
        Route::post( 'products/{product}/variants/generate', [ ProductVariantController::class, 'generate' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.variants.generate' );
        Route::post( 'products/{product}/variants/reorder', [ ProductVariantController::class, 'reorder' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.variants.reorder' );
        Route::patch( 'products/{product}/variants/{variant}', [ ProductVariantController::class, 'update' ] )
            ->scopeBindings()
            ->middleware( $admin( 'product', 'update', true ) )
            ->name( 'products.variants.update' );
        Route::delete( 'products/{product}/variants/{variant}', [ ProductVariantController::class, 'destroy' ] )
            ->scopeBindings()
            ->middleware( $admin( 'product', 'update', true ) )
            ->name( 'products.variants.destroy' );

        Route::post( 'products/{product}/prices', [ ProductPriceController::class, 'store' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.prices.store' );
        Route::patch( 'products/{product}/prices/{price}', [ ProductPriceController::class, 'update' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.prices.update' );
        Route::delete( 'products/{product}/prices/{price}', [ ProductPriceController::class, 'destroy' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.prices.destroy' );

        Route::post( 'products/{product}/images', [ ProductImageController::class, 'store' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.images.store' );
        Route::post( 'products/{product}/images/reorder', [ ProductImageController::class, 'reorder' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.images.reorder' );
        Route::patch( 'products/{product}/images/{image}', [ ProductImageController::class, 'update' ] )
            ->scopeBindings()
            ->middleware( $admin( 'product', 'update', true ) )
            ->name( 'products.images.update' );
        Route::delete( 'products/{product}/images/{image}', [ ProductImageController::class, 'destroy' ] )
            ->scopeBindings()
            ->middleware( $admin( 'product', 'update', true ) )
            ->name( 'products.images.destroy' );

        Route::post( 'products/{product}/attributes', [ ProductAttributeController::class, 'store' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.attributes.store' );
        Route::patch( 'products/{product}/attributes/{attribute}', [ ProductAttributeController::class, 'update' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.attributes.update' );
        Route::delete( 'products/{product}/attributes/{attribute}', [ ProductAttributeController::class, 'destroy' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.attributes.destroy' );

        Route::post( 'products/{product}/categories', [ ProductLinkController::class, 'categories' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.categories' );
        Route::post( 'products/{product}/tags', [ ProductLinkController::class, 'tags' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.tags' );
        Route::post( 'products/{product}/children', [ ProductLinkController::class, 'children' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'products.children' );
        // Stock adjustments use the inventory ability (engine issue #148), so
        // warehouse staff can count stock without editing products.
        Route::post( 'products/{product}/stock', [ ProductLinkController::class, 'stock' ] )->middleware( $admin( 'inventory', 'adjust', true ) )->name( 'products.stock' );

        Route::get( 'product-categories', [ ProductCategoryController::class, 'index' ] )->middleware( $admin( 'product', 'viewAny' ) )->name( 'product-categories.index' );
        Route::post( 'product-categories', [ ProductCategoryController::class, 'store' ] )->middleware( $admin( 'product', 'create', true ) )->name( 'product-categories.store' );
        Route::post( 'product-categories/reorder', [ ProductCategoryController::class, 'reorder' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'product-categories.reorder' );
        Route::patch( 'product-categories/{category}', [ ProductCategoryController::class, 'update' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'product-categories.update' );
        Route::delete( 'product-categories/{category}', [ ProductCategoryController::class, 'destroy' ] )->middleware( $admin( 'product', 'delete', true ) )->name( 'product-categories.destroy' );

        Route::get( 'product-tags', [ ProductTagController::class, 'index' ] )->middleware( $admin( 'product', 'viewAny' ) )->name( 'product-tags.index' );
        Route::post( 'product-tags', [ ProductTagController::class, 'store' ] )->middleware( $admin( 'product', 'create', true ) )->name( 'product-tags.store' );
        Route::patch( 'product-tags/{tag}', [ ProductTagController::class, 'update' ] )->middleware( $admin( 'product', 'update', true ) )->name( 'product-tags.update' );
        Route::delete( 'product-tags/{tag}', [ ProductTagController::class, 'destroy' ] )->middleware( $admin( 'product', 'delete', true ) )->name( 'product-tags.destroy' );
        // Merging deletes the source tag, so it needs product.delete as well as product.update.
        Route::post( 'product-tags/{tag}/merge', [ ProductTagController::class, 'merge' ] )
            ->middleware( array_merge( $auth, [ 'ecommerce.can:product,update', 'ecommerce.can:product,delete', 'ecommerce.rate-limit:ecommerce.admin.mutate', 'ecommerce.idempotency' ] ) )
            ->name( 'product-tags.merge' );
    } );

    // Inventory.
    Route::get( 'inventory', [ InventoryController::class, 'index' ] )->middleware( $admin( 'inventory', 'viewAny' ) )->name( 'inventory.index' );
    Route::patch( 'inventory/{item}', [ InventoryController::class, 'update' ] )->whereNumber( 'item' )->middleware( $admin( 'inventory', 'adjust', true ) )->name( 'inventory.update' );
    Route::post( 'inventory/{item}/adjust', [ InventoryController::class, 'adjust' ] )->whereNumber( 'item' )->middleware( $admin( 'inventory', 'adjust', true ) )->name( 'inventory.adjust' );

    // Settings (engine issue #145).
    Route::get( 'settings', [ SettingsController::class, 'index' ] )->middleware( $admin( 'settings', 'view' ) )->name( 'settings.index' );
    Route::get( 'settings/{group}', [ SettingsController::class, 'show' ] )->where( 'group', '[a-z0-9_-]+' )->middleware( $admin( 'settings', 'view' ) )->name( 'settings.show' );
    Route::patch( 'settings/{group}', [ SettingsController::class, 'update' ] )->where( 'group', '[a-z0-9_-]+' )->middleware( $admin( 'settings', 'update', true ) )->name( 'settings.update' );

    // Reports (engine issue #146).
    Route::get( 'reports', [ ReportController::class, 'index' ] )->middleware( $admin( 'report', 'view' ) )->name( 'reports.index' );
    Route::get( 'reports/{report}', [ ReportController::class, 'show' ] )->where( 'report', '[a-z0-9_-]+' )->middleware( $admin( 'report', 'view' ) )->name( 'reports.show' );

    // Activity log (products, customers, promotions; orders use their timeline).
    Route::where( [ 'product' => '[0-9]+', 'customer' => '[0-9]+', 'promotion' => '[0-9]+' ] )->group( function () use ( $admin ): void {
        Route::get( 'activity/products/{product}', [ ActivityLogController::class, 'product' ] )->middleware( $admin( 'product', 'view' ) )->name( 'activity.products' );
        Route::get( 'activity/customers/{customer}', [ ActivityLogController::class, 'customer' ] )->middleware( $admin( 'customer', 'view' ) )->name( 'activity.customers' );
        Route::get( 'activity/promotions/{promotion}', [ ActivityLogController::class, 'promotion' ] )->middleware( $admin( 'promotion', 'view' ) )->name( 'activity.promotions' );
    } );

    // Order sub-statuses.
    Route::get( 'order-substatuses', [ OrderSubstatusController::class, 'index' ] )->middleware( $admin( 'orderSubstatus', 'viewAny' ) )->name( 'order-substatuses.index' );
    Route::post( 'order-substatuses', [ OrderSubstatusController::class, 'store' ] )->middleware( $admin( 'orderSubstatus', 'create', true ) )->name( 'order-substatuses.store' );
    Route::post( 'order-substatuses/reorder', [ OrderSubstatusController::class, 'reorder' ] )->middleware( $admin( 'orderSubstatus', 'update', true ) )->name( 'order-substatuses.reorder' );
    Route::patch( 'order-substatuses/{substatus}', [ OrderSubstatusController::class, 'update' ] )->whereNumber( 'substatus' )->middleware( $admin( 'orderSubstatus', 'update', true ) )->name( 'order-substatuses.update' );
    Route::delete( 'order-substatuses/{substatus}', [ OrderSubstatusController::class, 'destroy' ] )->whereNumber( 'substatus' )->middleware( $admin( 'orderSubstatus', 'delete', true ) )->name( 'order-substatuses.destroy' );

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
    Route::get( 'shipping-method-types', [ ConfigCatalogController::class, 'shippingMethodTypes' ] )->middleware( $admin( 'shippingZone', 'viewAny' ) )->name( 'shipping-method-types.index' );
    Route::post( 'shipping-zones/{zone}/methods', [ ShippingMethodController::class, 'store' ] )->middleware( $admin( 'shippingZone', 'update', true ) )->name( 'shipping-methods.store' );
    Route::patch( 'shipping-methods/{method}', [ ShippingMethodController::class, 'update' ] )->middleware( $admin( 'shippingZone', 'update', true ) )->name( 'shipping-methods.update' );
    Route::delete( 'shipping-methods/{method}', [ ShippingMethodController::class, 'destroy' ] )->middleware( $admin( 'shippingZone', 'update', true ) )->name( 'shipping-methods.destroy' );

    // Promotions + coupons.
    Route::get( 'promotions', [ PromotionController::class, 'index' ] )->middleware( $admin( 'promotion', 'viewAny' ) )->name( 'promotions.index' );
    Route::get( 'promotion-conditions', [ ConfigCatalogController::class, 'promotionConditions' ] )->middleware( $admin( 'promotion', 'viewAny' ) )->name( 'promotion-conditions.index' );
    Route::get( 'promotion-actions', [ ConfigCatalogController::class, 'promotionActions' ] )->middleware( $admin( 'promotion', 'viewAny' ) )->name( 'promotion-actions.index' );
    Route::post( 'promotions', [ PromotionController::class, 'store' ] )->middleware( $admin( 'promotion', 'create', true ) )->name( 'promotions.store' );
    Route::patch( 'promotions/{promotion}', [ PromotionController::class, 'update' ] )->middleware( $admin( 'promotion', 'update', true ) )->name( 'promotions.update' );
    Route::delete( 'promotions/{promotion}', [ PromotionController::class, 'destroy' ] )->middleware( $admin( 'promotion', 'delete', true ) )->name( 'promotions.destroy' );
    Route::post( 'promotions/{promotion}/coupons', [ CouponController::class, 'store' ] )->middleware( $admin( 'coupon', 'create', true ) )->name( 'coupons.store' );
    Route::patch( 'coupons/{coupon}', [ CouponController::class, 'update' ] )->middleware( $admin( 'coupon', 'update', true ) )->name( 'coupons.update' );
    Route::delete( 'coupons/{coupon}', [ CouponController::class, 'destroy' ] )->middleware( $admin( 'coupon', 'delete', true ) )->name( 'coupons.destroy' );

    // Reviews (moderation queue).
    Route::get( 'reviews', [ ReviewController::class, 'index' ] )->middleware( $admin( 'review', 'viewAny' ) )->name( 'reviews.index' );
    Route::get( 'reviews/{review}', [ ReviewController::class, 'show' ] )->middleware( $admin( 'review', 'view' ) )->name( 'reviews.show' );
    Route::post( 'reviews/{review}/moderate', [ ReviewController::class, 'moderate' ] )->middleware( $admin( 'review', 'moderate', true ) )->name( 'reviews.moderate' );
    Route::delete( 'reviews/{review}', [ ReviewController::class, 'destroy' ] )->middleware( $admin( 'review', 'delete', true ) )->name( 'reviews.destroy' );

    // Digital files + license keys.
    Route::get( 'digital-files', [ DigitalFileController::class, 'index' ] )->middleware( $admin( 'digitalFile', 'viewAny' ) )->name( 'digital-files.index' );
    Route::post( 'digital-files', [ DigitalFileController::class, 'store' ] )->middleware( $admin( 'digitalFile', 'create', true ) )->name( 'digital-files.store' );
    Route::patch( 'digital-files/{file}', [ DigitalFileController::class, 'update' ] )->middleware( $admin( 'digitalFile', 'update', true ) )->name( 'digital-files.update' );
    Route::delete( 'digital-files/{file}', [ DigitalFileController::class, 'destroy' ] )->middleware( $admin( 'digitalFile', 'delete', true ) )->name( 'digital-files.destroy' );
    Route::get( 'license-keys', [ LicenseKeyController::class, 'index' ] )->middleware( $admin( 'licenseKey', 'view' ) )->name( 'license-keys.index' );
    Route::post( 'license-keys/{key}/revoke', [ LicenseKeyController::class, 'revoke' ] )->middleware( $admin( 'licenseKey', 'revoke', true ) )->name( 'license-keys.revoke' );

    // Notification templates.
    Route::get( 'notification-templates', [ NotificationTemplateController::class, 'index' ] )->middleware( $admin( 'notificationTemplate', 'viewAny' ) )->name( 'notification-templates.index' );
    Route::get( 'notification-templates/{template}', [ NotificationTemplateController::class, 'show' ] )->middleware( $admin( 'notificationTemplate', 'view' ) )->name( 'notification-templates.show' );
    Route::patch( 'notification-templates/{template}', [ NotificationTemplateController::class, 'update' ] )->middleware( $admin( 'notificationTemplate', 'update', true ) )->name( 'notification-templates.update' );
    Route::post( 'notification-templates/{template}/preview', [ NotificationTemplateController::class, 'preview' ] )->middleware( $admin( 'notificationTemplate', 'update' ) )->name( 'notification-templates.preview' );

    // Outbound webhooks.
    Route::get( 'webhook-subscriptions', [ WebhookSubscriptionController::class, 'index' ] )->middleware( $admin( 'webhookSubscription', 'viewAny' ) )->name( 'webhook-subscriptions.index' );
    Route::post( 'webhook-subscriptions', [ WebhookSubscriptionController::class, 'store' ] )->middleware( $admin( 'webhookSubscription', 'create', true ) )->name( 'webhook-subscriptions.store' );
    Route::patch( 'webhook-subscriptions/{subscription}', [ WebhookSubscriptionController::class, 'update' ] )->middleware( $admin( 'webhookSubscription', 'update', true ) )->name( 'webhook-subscriptions.update' );
    Route::delete( 'webhook-subscriptions/{subscription}', [ WebhookSubscriptionController::class, 'destroy' ] )->middleware( $admin( 'webhookSubscription', 'delete', true ) )->name( 'webhook-subscriptions.destroy' );
    Route::get( 'webhook-subscriptions/{subscription}/deliveries', [ WebhookSubscriptionController::class, 'deliveries' ] )
        ->middleware( $admin( 'webhookSubscription', 'viewAny' ) )
        ->name( 'webhook-subscriptions.deliveries.index' );
    Route::get( 'webhook-subscriptions/{subscription}/deliveries/{delivery}', [ WebhookSubscriptionController::class, 'delivery' ] )
        ->scopeBindings()
        ->middleware( $admin( 'webhookSubscription', 'viewAny' ) )
        ->name( 'webhook-subscriptions.deliveries.show' );
    Route::post( 'webhook-subscriptions/{subscription}/replay-parked', [ WebhookSubscriptionController::class, 'replayParked' ] )
        ->middleware( $admin( 'webhookSubscription', 'update', true ) )
        ->name( 'webhook-subscriptions.replay-parked' );
    Route::post( 'webhook-subscriptions/{subscription}/replay/{delivery}', [ WebhookSubscriptionController::class, 'replay' ] )
        ->scopeBindings()
        ->middleware( $admin( 'webhookSubscription', 'update', true ) )
        ->name( 'webhook-subscriptions.replay' );
} );
