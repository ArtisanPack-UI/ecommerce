<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Mail\HtmlToText;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Notifications\EcommerceNotification;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Notifications\NotificationContext;
use ArtisanPackUI\Ecommerce\Notifications\NotificationDispatcher;
use ArtisanPackUI\Ecommerce\Services\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'mail.default', 'array' );
} );

/**
 * Sends `$templateKey` about a fresh order to `$recipient` right away and
 * returns the mail that went out.
 */
function mailFor( string $templateKey, mixed $recipient, ?string $locale = null ): Email
{
    $order = Order::factory()->create( [ 'order_number' => 'K7QM2XW9', 'locale' => $locale ] );
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_snapshot' => [ 'name' => 'Poster', 'sku' => 'P-1' ] ] );

    $notification = new EcommerceNotification( $templateKey, 'mail', [ 'Store' => app( NotificationContext::class )->store(), 'Order' => app( NotificationContext::class )->order( $order ) ] );

    if ( null !== $locale ) {
        $notification->locale( $locale );
    }

    Notification::sendNow( $recipient, $notification );

    return app( 'mailer' )->getSymfonyTransport()->messages()->last()->getOriginalMessage();
}

it( 'sends HTML with its language and a plain-text part', function (): void {
    $email = mailFor( NotificationCatalog::ORDER_CONFIRMATION, Notification::route( 'mail', 'ada@example.test' ), 'de' );

    expect( $email->getHtmlBody() )->toContain( '<html lang="de" dir="ltr">' )
        ->and( $email->getHtmlBody() )->toContain( '<table role="presentation">' )
        ->and( $email->getSubject() )->toContain( 'K7QM2XW9' )
        ->and( $email->getTextBody() )->toContain( 'Poster' )
        ->and( $email->getTextBody() )->not->toContain( '<' )
        // Transactional mail can't be unsubscribed from.
        ->and( $email->getHeaders()->has( 'List-Unsubscribe' ) )->toBeFalse();
} );

it( 'adds one-click unsubscribe to opt-out categories', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'ada@example.test' ] );
    $email    = mailFor( NotificationCatalog::REVIEW_REQUEST, $customer );

    $header = $email->getHeaders()->get( 'List-Unsubscribe' )?->getBodyAsString();

    expect( $header )->toStartWith( '<' )->toContain( '/ecommerce/notifications/unsubscribe' )->toContain( 'signature=' )
        ->and( $email->getHeaders()->get( 'List-Unsubscribe-Post' )?->getBodyAsString() )->toBe( 'List-Unsubscribe=One-Click' )
        ->and( $email->getHtmlBody() )->toContain( 'Unsubscribe from these emails' )
        ->and( $email->getTextBody() )->toContain( 'Unsubscribe from these emails' );
} );

it( 'unsubscribes with a signed POST and never on a GET', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'ada@example.test' ] );
    $url      = app( NotificationPreferenceService::class )->unsubscribeUrl( 'ada@example.test', 'mail', 'review-requests' );

    $this->get( $url )->assertOk()->assertSee( '<form method="post"', false );

    expect( app( NotificationPreferenceService::class )->allows( $customer, 'mail', 'review-requests' ) )->toBeTrue();

    $this->post( $url, [ 'List-Unsubscribe' => 'One-Click' ] )->assertOk();

    expect( app( NotificationPreferenceService::class )->allows( $customer, 'mail', 'review-requests' ) )->toBeFalse()
        ->and( app( NotificationPreferenceService::class )->allows( $customer, 'mail', 'transactional' ) )->toBeTrue();
} );

it( 'rejects tampered or unsigned unsubscribe links', function (): void {
    Customer::factory()->create( [ 'email' => 'ada@example.test' ] );
    $url = app( NotificationPreferenceService::class )->unsubscribeUrl( 'ada@example.test', 'mail', 'review-requests' );

    $this->post( str_replace( 'ada%40example.test', 'sam%40example.test', $url ) )->assertForbidden();
    $this->post( URL::route( 'ecommerce.notifications.unsubscribe.store', [ 'email' => 'ada@example.test', 'channel' => 'mail', 'category' => 'review-requests' ] ) )->assertForbidden();
} );

it( 'remembers a guest\'s unsubscribe for later guest orders', function (): void {
    Notification::fake();
    $this->post( app( NotificationPreferenceService::class )->unsubscribeUrl( 'guest@example.test', 'mail', 'review-requests' ) )->assertOk();

    $sent = app( NotificationDispatcher::class )->send( NotificationCatalog::REVIEW_REQUEST, [ Notification::route( 'mail', 'guest@example.test' ) ], [] );

    expect( $sent )->toBe( 0 )
        ->and( app( NotificationDispatcher::class )->send( NotificationCatalog::ORDER_CONFIRMATION, [ Notification::route( 'mail', 'guest@example.test' ) ], [] ) )->toBe( 1 );
} );

it( 'converts HTML to readable text with links kept', function (): void {
    $text = HtmlToText::convert( '<p>Hi <strong>Ada</strong>,</p><ul><li>One</li><li>Two &amp; three</li></ul><table><tr><td>Poster</td><td>$10</td></tr></table><p><a href="https://x.test/track">Track it</a><br>Thanks</p>' );

    expect( $text )->toBe( "Hi Ada,\n\n- One\n- Two & three\n\nPoster\t\$10\n\nTrack it (https://x.test/track)\nThanks" );
} );
