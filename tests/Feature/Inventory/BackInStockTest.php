<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\BackInStockSubscription;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerNotificationPreference;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Notifications\EcommerceNotification;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Services\BackInStockService;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use ArtisanPackUI\Ecommerce\Services\InventoryService;
use ArtisanPackUI\Ecommerce\Services\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->service   = app( BackInStockService::class );
    $this->inventory = app( InventoryService::class );
} );

function stockRow( Product|ProductVariant $stockable, int $onHand, int $reserved = 0 ): InventoryItem
{
    return InventoryItem::factory()->create( [
        'stockable_type'    => $stockable->getMorphClass(),
        'stockable_id'      => $stockable->id,
        'quantity_on_hand'  => $onHand,
        'quantity_reserved' => $reserved,
        'track_inventory'   => true,
        'allow_backorder'   => false,
    ] );
}

/**
 * Back-in-stock notifications sent on demand to `$email`.
 */
function backInStockSentTo( string $email ): int
{
    $count = 0;

    Notification::assertSentOnDemand( EcommerceNotification::class, function ( EcommerceNotification $notification, array $channels, AnonymousNotifiable $notifiable ) use ( $email, &$count ): bool {
        if ( NotificationCatalog::BACK_IN_STOCK === $notification->templateKey && $email === $notifiable->routeNotificationFor( 'mail' ) ) {
            $count++;
        }

        return true;
    } );

    return $count;
}

it( 'subscribes with a normalized email and is idempotent', function (): void {
    $product = Product::factory()->create();

    $first  = $this->service->subscribe( $product, null, '  Ada@Example.TEST ', locale: 'fr' );
    $second = $this->service->subscribe( $product, null, 'ada@example.test' );

    expect( $first->email )->toBe( 'ada@example.test' )
        ->and( $first->locale )->toBe( 'fr' )
        ->and( $second->is( $first ) )->toBeTrue()
        ->and( BackInStockSubscription::query()->count() )->toBe( 1 )
        ->and( $this->service->isSubscribed( $product, null, 'ADA@example.test' ) )->toBeTrue();

    // A variant subscription is separate from the product-level one.
    $variant = ProductVariant::factory()->for( $product )->create();
    $this->service->subscribe( $product, $variant, 'ada@example.test' );

    expect( BackInStockSubscription::query()->count() )->toBe( 2 )
        ->and( $this->service->isSubscribed( $product, $variant, 'ada@example.test' ) )->toBeTrue();
} );

it( 'links the customer with that email and fires the subscribed hook', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'grace@example.test' ] );
    $product  = Product::factory()->create();
    $fired    = [];
    addAction( 'ap.ecommerce.backInStock.subscribed', function ( BackInStockSubscription $subscription ) use ( &$fired ): void {
        $fired[] = $subscription->id;
    } );

    $subscription = $this->service->subscribe( $product, null, 'Grace@example.test' );

    expect( $subscription->customer_id )->toBe( $customer->id )
        ->and( $fired )->toBe( [ $subscription->id ] );

    removeAllActions( 'ap.ecommerce.backInStock.subscribed' );
} );

it( 'refuses an invalid email or another product\'s variant', function (): void {
    $product = Product::factory()->create();

    expect( fn () => $this->service->subscribe( $product, null, 'not-an-email' ) )->toThrow( InvalidArgumentException::class )
        ->and( fn () => $this->service->subscribe( $product, ProductVariant::factory()->create(), 'ada@example.test' ) )->toThrow( InvalidArgumentException::class );
} );

it( 'creates a new subscription once the previous one was notified or unsubscribed', function (): void {
    $product = Product::factory()->create();
    $first   = $this->service->subscribe( $product, null, 'ada@example.test' );

    $this->service->unsubscribe( $first );

    expect( $first->refresh()->unsubscribed_at )->not->toBeNull()
        ->and( $this->service->isSubscribed( $product, null, 'ada@example.test' ) )->toBeFalse()
        ->and( $this->service->subscribe( $product, null, 'ada@example.test' )->is( $first ) )->toBeFalse();
} );

it( 'notifies pending subscribers when stock comes back, once', function (): void {
    Notification::fake();
    $product = Product::factory()->create( [ 'name' => 'Poster' ] );
    $row     = stockRow( $product, 0 );
    $pending = $this->service->subscribe( $product, null, 'ada@example.test' );
    $done    = BackInStockSubscription::factory()->notified()->create( [ 'product_id' => $product->id, 'email' => 'old@example.test' ] );
    $gone    = BackInStockSubscription::factory()->unsubscribed()->create( [ 'product_id' => $product->id, 'email' => 'gone@example.test' ] );
    $other   = BackInStockSubscription::factory()->create( [ 'email' => 'other@example.test' ] );

    $this->inventory->adjust( $row, 5, 'Restock' );

    expect( backInStockSentTo( 'ada@example.test' ) )->toBe( 1 )
        ->and( backInStockSentTo( 'old@example.test' ) )->toBe( 0 )
        ->and( backInStockSentTo( 'gone@example.test' ) )->toBe( 0 )
        ->and( backInStockSentTo( 'other@example.test' ) )->toBe( 0 )
        ->and( $pending->refresh()->notified_at )->not->toBeNull();

    // Selling out and restocking again doesn't notify the same subscription twice.
    $this->inventory->adjust( $row->refresh(), -5, 'Sold' );
    $this->inventory->adjust( $row->refresh(), 2, 'Restock' );

    expect( backInStockSentTo( 'ada@example.test' ) )->toBe( 1 );
} );

it( 'does not notify on a rise that was never out of stock', function (): void {
    Notification::fake();
    $product = Product::factory()->create();
    $row     = stockRow( $product, 3 );
    $pending = $this->service->subscribe( $product, null, 'ada@example.test' );

    $this->inventory->adjust( $row, 2, 'Restock' );

    Notification::assertNothingSent();
    expect( $pending->refresh()->isPending() )->toBeTrue();
} );

it( 'notifies when a released reservation frees the last units', function (): void {
    Notification::fake();
    $product = Product::factory()->create();
    $row     = stockRow( $product, 1, 1 );
    $cart    = ArtisanPackUI\Ecommerce\Models\Cart::factory()->create();
    InventoryReservation::factory()->create( [ 'inventory_item_id' => $row->id, 'reservable_type' => $cart->getMorphClass(), 'reservable_id' => $cart->id, 'quantity' => 1 ] );
    $this->service->subscribe( $product, null, 'ada@example.test' );

    $this->inventory->releaseFor( $cart );

    expect( backInStockSentTo( 'ada@example.test' ) )->toBe( 1 );
} );

it( 'notifies variant subscribers and product-level subscribers when a variant restocks', function (): void {
    Notification::fake();
    $product = Product::factory()->create( [ 'type' => 'variable' ] );
    $red     = ProductVariant::factory()->for( $product )->create( [ 'name' => 'Red' ] );
    $blue    = ProductVariant::factory()->for( $product )->create( [ 'name' => 'Blue' ] );
    $row     = stockRow( $red, 0 );
    $this->service->subscribe( $product, $red, 'red@example.test' );
    $this->service->subscribe( $product, $blue, 'blue@example.test' );
    $this->service->subscribe( $product, null, 'any@example.test' );

    $this->inventory->adjust( $row, 4, 'Restock' );

    expect( backInStockSentTo( 'red@example.test' ) )->toBe( 1 )
        ->and( backInStockSentTo( 'any@example.test' ) )->toBe( 1 )
        ->and( backInStockSentTo( 'blue@example.test' ) )->toBe( 0 );
} );

it( 'respects the customer\'s back-in-stock preference', function (): void {
    Notification::fake();
    $optedOut = Customer::factory()->create( [ 'email' => 'quiet@example.test' ] );
    $optedIn  = Customer::factory()->create( [ 'email' => 'keen@example.test', 'first_name' => 'Keen' ] );
    CustomerNotificationPreference::factory()->create( [ 'customer_id' => $optedOut->id, 'channel' => 'mail', 'category' => 'back-in-stock', 'is_enabled' => false ] );
    $product  = Product::factory()->create();
    $row      = stockRow( $product, 0 );
    $quiet    = $this->service->subscribe( $product, null, 'quiet@example.test' );
    $this->service->subscribe( $product, null, 'keen@example.test' );

    $this->inventory->adjust( $row, 1, 'Restock' );

    Notification::assertNotSentTo( $optedOut, EcommerceNotification::class );
    Notification::assertSentTo( $optedIn, EcommerceNotification::class, fn ( EcommerceNotification $notification ): bool => NotificationCatalog::BACK_IN_STOCK === $notification->templateKey );
    expect( $quiet->refresh()->isPending() )->toBeTrue();
} );

it( 'renders the product and carries the category\'s unsubscribe link', function (): void {
    config()->set( 'artisanpack.ecommerce.notifications.product_url', 'https://shop.test/products/{slug}' );
    Notification::fake();
    $product = Product::factory()->create( [ 'name' => 'Engine Poster', 'slug' => 'engine-poster' ] );
    $variant = ProductVariant::factory()->for( $product )->create( [ 'name' => '16 × 20' ] );
    $row     = stockRow( $variant, 0 );
    $this->service->subscribe( $product, $variant, 'ada@example.test', locale: 'en' );

    $this->inventory->adjust( $row, 1, 'Restock' );

    Notification::assertSentOnDemand( EcommerceNotification::class, function ( EcommerceNotification $notification, array $channels, AnonymousNotifiable $notifiable ): bool {
        $unsubscribe = $notification->unsubscribeUrl( $notifiable );
        $rendered    = $notification->render( $unsubscribe );

        return NotificationCatalog::BACK_IN_STOCK === $notification->templateKey
            && 'en' === $notification->locale
            && str_contains( (string) $rendered['subject'], 'Engine Poster is back in stock' )
            && str_contains( $rendered['body'], '(16 × 20)' )
            && str_contains( $rendered['body'], 'https://shop.test/products/engine-poster' )
            && null !== $unsubscribe
            && str_contains( $unsubscribe, 'category=back-in-stock' )
            && str_contains( $rendered['body'], htmlspecialchars( $unsubscribe ) );
    } );
} );

it( 'stays pending for a guest who unsubscribed from back-in-stock mail', function (): void {
    Notification::fake();
    app( NotificationPreferenceService::class )->unsubscribe( 'guest@example.test', 'mail', 'back-in-stock' );
    $product = Product::factory()->create();
    $row     = stockRow( $product, 0 );
    $sub     = BackInStockSubscription::factory()->create( [ 'product_id' => $product->id, 'email' => 'guest@example.test' ] );

    $this->inventory->adjust( $row, 1, 'Restock' );

    expect( $sub->refresh()->isPending() )->toBeTrue();
} );

it( 'fires the notified hook and honours shouldNotify', function (): void {
    Notification::fake();
    $product = Product::factory()->create();
    $row     = stockRow( $product, 0 );
    $skip    = $this->service->subscribe( $product, null, 'skip@example.test' );
    $send    = $this->service->subscribe( $product, null, 'send@example.test' );
    $fired   = [];
    addFilter( 'ap.ecommerce.backInStock.shouldNotify', fn ( bool $notify, BackInStockSubscription $subscription ): bool => 'skip@example.test' !== $subscription->email );
    addAction( 'ap.ecommerce.backInStock.notified', function ( BackInStockSubscription $subscription ) use ( &$fired ): void {
        $fired[] = $subscription->email;
    } );

    $this->inventory->adjust( $row, 1, 'Restock' );

    expect( $fired )->toBe( [ 'send@example.test' ] )
        ->and( $skip->refresh()->isPending() )->toBeTrue()
        ->and( $send->refresh()->isPending() )->toBeFalse();

    removeAllFilters( 'ap.ecommerce.backInStock.shouldNotify' );
    removeAllActions( 'ap.ecommerce.backInStock.notified' );
} );

it( 'exports subscriptions as personal data', function (): void {
    Carbon::setTestNow( '2026-11-01 10:00:00' );
    $customer = Customer::factory()->create( [ 'email' => 'ada@example.test' ] );
    $product  = Product::factory()->create( [ 'name' => 'Poster' ] );
    $this->service->subscribe( $product, null, 'ada@example.test' );
    BackInStockSubscription::factory()->create( [ 'email' => 'someone@example.test' ] );

    $rows = $this->service->exportFor( 'ADA@example.test', $customer );

    expect( $rows )->toHaveCount( 1 )
        ->and( $rows[0] )->toMatchArray( [ 'product_id' => $product->id, 'product' => 'Poster', 'email' => 'ada@example.test', 'notified_at' => null ] );

    // Through the artisanpack-ui/privacy export filter, for the customer as subject.
    $data = applyFilters( 'ap.privacy.exportData', [ 'subject' => [] ], $customer );

    expect( $data['ecommerce']['back_in_stock_subscriptions'] )->toHaveCount( 1 );

    Carbon::setTestNow();
} );

it( 'erases subscriptions with the customer', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'ada@example.test' ] );
    $product  = Product::factory()->create();
    BackInStockSubscription::factory()->forCustomer( $customer )->create( [ 'product_id' => $product->id ] );
    BackInStockSubscription::factory()->create( [ 'product_id' => $product->id, 'email' => 'ada@example.test' ] );
    $kept = BackInStockSubscription::factory()->create( [ 'product_id' => $product->id, 'email' => 'other@example.test' ] );

    app( CustomerService::class )->delete( $customer );

    expect( BackInStockSubscription::query()->pluck( 'id' )->all() )->toBe( [ $kept->id ] );
} );
