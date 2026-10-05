<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\NotificationTemplate;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Notifications\EcommerceNotification;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Notifications\NotificationContext;
use ArtisanPackUI\Ecommerce\Services\NotificationTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.notifications.admin_emails', [ 'ops@example.test' ] );
    app( NotificationTemplateService::class )->sync();
} );

/**
 * A placed order in `$locale`, for a customer with no preference of their own.
 */
function localeOrder( ?string $locale, ?Customer $customer = null ): Order
{
    $order = Order::factory()->forCustomer( $customer ?? Customer::factory()->create( [ 'first_name' => 'Ada' ] ) )->create( [ 'order_number' => 'K7QM2XW9', 'locale' => $locale ] );
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_snapshot' => [ 'name' => 'Poster', 'sku' => 'P-1' ] ] );

    return $order;
}

/**
 * The confirmation sent to `$notifiable`.
 */
function localeConfirmation( mixed $notifiable ): EcommerceNotification
{
    return Notification::sent( $notifiable, EcommerceNotification::class )
        ->first( fn ( EcommerceNotification $notification ): bool => NotificationCatalog::ORDER_CONFIRMATION === $notification->templateKey );
}

it( 'sends an order confirmation in the order\'s language, from the catalog translation', function (): void {
    Notification::fake();
    $order = localeOrder( 'de' );

    doAction( 'ap.ecommerce.order.placed', $order );

    $notification = localeConfirmation( $order->customer );
    $store        = app( NotificationContext::class )->store()['name'];

    expect( $notification->locale )->toBe( 'de' )
        ->and( $notification->render()['subject'] )->toBe( __( 'Your :store order :number', [ 'store' => $store, 'number' => 'K7QM2XW9' ], 'de' ) )
        ->and( $notification->render()['subject'] )->not->toBe( __( 'Your :store order :number', [ 'store' => $store, 'number' => 'K7QM2XW9' ], 'en' ) );
} );

it( 'prefers the store\'s own copy in that language', function (): void {
    Notification::fake();
    NotificationTemplate::query()->create( [
        'key'       => NotificationCatalog::ORDER_CONFIRMATION,
        'channel'   => 'mail',
        'locale'    => 'de',
        'subject'   => 'Danke! {{ Order.number }}',
        'body'      => '<p>Hallo</p>',
        'is_active' => true,
    ] );
    $order = localeOrder( 'de' );

    doAction( 'ap.ecommerce.order.placed', $order );

    expect( localeConfirmation( $order->customer )->render()['subject'] )->toBe( 'Danke! K7QM2XW9' );
} );

it( 'falls back to the store\'s default-language copy for a language the engine does not ship', function (): void {
    Notification::fake();
    NotificationTemplate::query()->where( 'key', NotificationCatalog::ORDER_CONFIRMATION )->where( 'locale', 'en' )->update( [ 'subject' => 'Custom {{ Order.number }}' ] );
    $order = localeOrder( 'ja' );

    doAction( 'ap.ecommerce.order.placed', $order );

    expect( localeConfirmation( $order->customer )->render()['subject'] )->toBe( 'Custom K7QM2XW9' );
} );

it( 'uses the customer\'s preference when the order has no language', function (): void {
    Notification::fake();
    $order = localeOrder( null, Customer::factory()->create( [ 'locale' => 'fr' ] ) );

    doAction( 'ap.ecommerce.order.placed', $order );

    expect( localeConfirmation( $order->customer )->locale )->toBe( 'fr' )
        ->and( $order->customer->preferredLocale() )->toBe( 'fr' );
} );

it( 'sends guest order confirmations in the order\'s language too', function (): void {
    Notification::fake();
    $order = Order::factory()->create( [ 'customer_id' => null, 'email' => 'guest@example.test', 'locale' => 'es' ] );

    doAction( 'ap.ecommerce.order.placed', $order );

    Notification::assertSentOnDemand( EcommerceNotification::class, fn ( EcommerceNotification $notification, array $channels, AnonymousNotifiable $notifiable ): bool => NotificationCatalog::ORDER_CONFIRMATION !== $notification->templateKey
        || 'es' === $notification->locale );
} );

it( 'keeps staff notifications in the store\'s default language', function (): void {
    Notification::fake();
    app()->setLocale( 'de' );
    $order = localeOrder( 'de' );

    doAction( 'ap.ecommerce.payment.succeeded', null, $order );

    Notification::assertSentOnDemand( EcommerceNotification::class, fn ( EcommerceNotification $notification ): bool => NotificationCatalog::ORDER_PAID_ADMIN === $notification->templateKey
        && 'en' === $notification->locale
        && str_starts_with( (string) $notification->render()['subject'], 'Order K7QM2XW9 paid' ) );
} );

it( 'translates catalog labels when they are read, not when the catalog is registered', function (): void {
    $definition = app( ArtisanPackUI\Ecommerce\Registries\NotificationTemplateRegistry::class )->get( NotificationCatalog::ORDER_CONFIRMATION );

    app()->setLocale( 'de' );
    $german = $definition->label();
    app()->setLocale( 'en' );

    expect( $german )->toBe( __( 'Order confirmation', [], 'de' ) )
        ->and( $definition->label() )->toBe( 'Order confirmation' )
        ->and( $definition->defaultSubject( 'fr' ) )->toBe( __( 'Your :store order :number', [ 'store' => '{{ Store.name }}', 'number' => '{{ Order.number }}' ], 'fr' ) );
} );
