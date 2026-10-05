<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Listeners\IssueDigitalDeliverables;
use ArtisanPackUI\Ecommerce\Listeners\RevokeDigitalDeliverables;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Notifications\EcommerceNotification;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses( RefreshDatabase::class );

/**
 * A paid order of a licensed ebook with one downloadable file.
 *
 * @return array{0: Order, 1: OrderItem, 2: DigitalFile}
 */
function covDigitalOrder(): array
{
    $product = Product::factory()->create( [ 'type' => 'digital', 'meta' => [ 'licensing' => [ 'enabled' => true, 'activations_limit' => 2 ] ] ] );
    $file    = DigitalFile::factory()->create( [ 'product_id' => $product->id ] );
    $order   = Order::factory()->forCustomer( Customer::factory()->create() )->create( [ 'payment_status' => 'paid' ] );
    $item    = OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => $product->id ] );

    return [ $order, $item, $file ];
}

it( 'issues downloads and license keys for a paid order and tells the customer once', function (): void {
    Notification::fake();
    [ $order, $item, $file ] = covDigitalOrder();

    app( IssueDigitalDeliverables::class )->handle( null, $order );
    app( IssueDigitalDeliverables::class )->issue( $order );

    expect( DigitalDownload::query()->where( 'order_item_id', $item->id )->pluck( 'digital_file_id' )->all() )->toBe( [ $file->id ] )
        ->and( LicenseKey::query()->where( 'order_item_id', $item->id )->count() )->toBe( 1 )
        ->and( LicenseKey::query()->where( 'order_item_id', $item->id )->value( 'activations_limit' ) )->toBe( 2 );

    $sent = Notification::sent( $order->customer, EcommerceNotification::class )->filter( fn ( EcommerceNotification $n ): bool => NotificationCatalog::DOWNLOAD_READY === $n->templateKey );

    expect( $sent )->toHaveCount( 1 )
        ->and( $sent->first()->variables['Downloads'] )->toHaveCount( 1 )
        ->and( $sent->first()->variables['Licenses'] )->toHaveCount( 1 );
} );

it( 'sends nothing for an order with nothing digital', function (): void {
    Notification::fake();
    $order = Order::factory()->create();
    OrderItem::factory()->create( [ 'order_id' => $order->id ] );

    app( IssueDigitalDeliverables::class )->issue( $order );

    expect( DigitalDownload::query()->count() )->toBe( 0 );
    Notification::assertNothingSent();
} );

it( 'issues deliverables when payment succeeds through the hook', function (): void {
    Notification::fake();
    [ $order, $item ] = covDigitalOrder();

    doAction( 'ap.ecommerce.payment.succeeded', null, $order );

    expect( DigitalDownload::query()->where( 'order_item_id', $item->id )->exists() )->toBeTrue();
} );

it( 'revokes downloads and license keys when an order is fully refunded', function (): void {
    Carbon::setTestNow( '2026-10-05 12:00:00' );
    [ $order, $item, $file ] = covDigitalOrder();
    $live                    = DigitalDownload::factory()->create( [ 'order_item_id' => $item->id, 'digital_file_id' => $file->id, 'expires_at' => now()->addDays( 10 ) ] );
    $forever                 = DigitalDownload::factory()->create( [ 'order_item_id' => $item->id, 'digital_file_id' => $file->id, 'expires_at' => null ] );
    $expired                 = DigitalDownload::factory()->create( [ 'order_item_id' => $item->id, 'digital_file_id' => $file->id, 'expires_at' => '2026-09-01 00:00:00' ] );
    $key                     = LicenseKey::factory()->create( [ 'order_item_id' => $item->id ] );

    $order->forceFill( [ 'payment_status' => 'refunded' ] )->save();
    app( RevokeDigitalDeliverables::class )->refunded( $order );

    expect( $live->fresh()->isExpired() )->toBeTrue()
        ->and( $forever->fresh()->isExpired() )->toBeTrue()
        ->and( $expired->fresh()->expires_at->toDateTimeString() )->toBe( '2026-09-01 00:00:00' )
        ->and( $key->fresh()->is_revoked )->toBeTrue()
        ->and( $key->fresh()->meta['revoked_reason'] ?? null )->toBe( 'Order refunded' );

    Carbon::setTestNow();
} );

it( 'keeps deliverables after a partial refund', function (): void {
    [ $order, $item, $file ] = covDigitalOrder();
    $download                = DigitalDownload::factory()->create( [ 'order_item_id' => $item->id, 'digital_file_id' => $file->id, 'expires_at' => now()->addDays( 10 ) ] );
    $key                     = LicenseKey::factory()->create( [ 'order_item_id' => $item->id ] );

    $order->forceFill( [ 'payment_status' => 'partially_refunded' ] )->save();
    app( RevokeDigitalDeliverables::class )->refunded( $order );

    expect( $download->fresh()->isExpired() )->toBeFalse()
        ->and( $key->fresh()->is_revoked )->toBeFalse();
} );

it( 'revokes on cancellation only', function (): void {
    [ $order, $item ] = covDigitalOrder();
    $key              = LicenseKey::factory()->create( [ 'order_item_id' => $item->id ] );

    app( RevokeDigitalDeliverables::class )->statusChanged( $order, 'pending', 'processing' );
    expect( $key->fresh()->is_revoked )->toBeFalse();

    app( RevokeDigitalDeliverables::class )->statusChanged( $order, 'processing', 'cancelled' );
    expect( $key->fresh()->is_revoked )->toBeTrue()
        ->and( $key->fresh()->meta['revoked_reason'] ?? null )->toBe( 'Order cancelled' );
} );

it( 'leaves other orders\' deliverables alone', function (): void {
    [ $order ]           = covDigitalOrder();
    [ , $otherItem ]     = covDigitalOrder();
    $otherKey            = LicenseKey::factory()->create( [ 'order_item_id' => $otherItem->id ] );

    app( RevokeDigitalDeliverables::class )->revoke( $order, 'test' );

    expect( $otherKey->fresh()->is_revoked )->toBeFalse();
} );
