<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\GuestLookupLockedException;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Notifications\NotificationContext;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use ArtisanPackUI\Ecommerce\Services\GuestOrderLookupService;
use ArtisanPackUI\Ecommerce\Support\OrderViewToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses( RefreshDatabase::class );

const GUEST_API = '/api/ecommerce/v1/orders';

beforeEach( function (): void {
    $this->order = Order::factory()->create( [ 'customer_id' => null, 'email' => 'guest@example.test', 'order_number' => 'K7QM2XW9' ] );
    OrderItem::factory()->create( [ 'order_id' => $this->order->id ] );
} );

function lookups(): GuestOrderLookupService
{
    return app( GuestOrderLookupService::class );
}

it( 'finds an order by email (any case) and order number, and nothing otherwise', function (): void {
    expect( lookups()->find( ' GUEST@example.test ', 'K7QM2XW9', '203.0.113.1' )?->is( $this->order ) )->toBeTrue()
        ->and( lookups()->find( 'other@example.test', 'K7QM2XW9', '203.0.113.1' ) )->toBeNull()
        ->and( lookups()->find( 'guest@example.test', 'NOPE', '203.0.113.1' ) )->toBeNull();
} );

it( 'locks an order number out after repeated failures, even with the right email', function (): void {
    config()->set( 'artisanpack.ecommerce.checkout.guest_lookup.max_failures_per_order', 3 );

    foreach ( range( 1, 3 ) as $i ) {
        expect( lookups()->find( "wrong{$i}@example.test", 'K7QM2XW9', "198.51.100.{$i}" ) )->toBeNull();
    }

    expect( fn () => lookups()->find( 'guest@example.test', 'K7QM2XW9', '198.51.100.9' ) )->toThrow( GuestLookupLockedException::class );

    // The lock lifts after the window.
    Carbon::setTestNow( now()->addMinutes( 16 ) );
    expect( lookups()->find( 'guest@example.test', 'K7QM2XW9', '198.51.100.9' )?->is( $this->order ) )->toBeTrue();
    Carbon::setTestNow();
} );

it( 'locks a caller out after failures across order numbers', function (): void {
    config()->set( 'artisanpack.ecommerce.checkout.guest_lookup.max_failures_per_ip', 2 );

    lookups()->find( 'guest@example.test', 'A1', '203.0.113.5' );
    lookups()->find( 'guest@example.test', 'A2', '203.0.113.5' );

    expect( fn () => lookups()->find( 'guest@example.test', 'K7QM2XW9', '203.0.113.5' ) )->toThrow( GuestLookupLockedException::class )
        ->and( lookups()->find( 'guest@example.test', 'K7QM2XW9', '203.0.113.6' )?->is( $this->order ) )->toBeTrue();
} );

it( 'answers the same 404 for a wrong email and an unknown order over REST, then 429', function (): void {
    config()->set( 'artisanpack.ecommerce.checkout.guest_lookup.max_failures_per_ip', 2 );

    $this->getJson( GUEST_API . '/guest-lookup?email=guest@example.test&order_number=K7QM2XW9&include=items' )
        ->assertOk()
        ->assertJsonPath( 'data.order_number', 'K7QM2XW9' )
        ->assertJsonCount( 1, 'data.items' )
        ->assertHeader( 'Cache-Control', 'no-store, private' );

    $wrong   = $this->getJson( GUEST_API . '/guest-lookup?email=wrong@example.test&order_number=K7QM2XW9' )->assertNotFound()->json();
    $unknown = $this->getJson( GUEST_API . '/guest-lookup?email=guest@example.test&order_number=NOPE' )->assertNotFound()->json();

    expect( array_diff_key( $wrong, [ 'instance' => true, 'request_id' => true ] ) )->toBe( array_diff_key( $unknown, [ 'instance' => true, 'request_id' => true ] ) );

    $this->getJson( GUEST_API . '/guest-lookup?email=guest@example.test&order_number=K7QM2XW9' )->assertStatus( 429 )->assertHeader( 'Retry-After' );
    $this->getJson( GUEST_API . '/guest-lookup?order_number=K7QM2XW9' )->assertStatus( 422 );
} );

it( 'opens a signed order link until it expires, and refuses a tampered one', function (): void {
    $token = OrderViewToken::for( $this->order, now()->addDay() );

    expect( OrderViewToken::verify( $token )?->is( $this->order ) )->toBeTrue();

    $this->getJson( GUEST_API . "/view/{$token}" )->assertOk()->assertJsonPath( 'data.order_number', 'K7QM2XW9' );

    $other  = Order::factory()->create();
    $forged = preg_replace( '/^\d+/', (string) $other->id, $token );

    $this->getJson( GUEST_API . "/view/{$forged}" )->assertNotFound();

    Carbon::setTestNow( now()->addDays( 2 ) );
    $this->getJson( GUEST_API . "/view/{$token}" )->assertNotFound();
    Carbon::setTestNow();
} );

it( 'stops working once the order is anonymized', function (): void {
    $token = OrderViewToken::for( $this->order );
    $this->order->forceFill( [ 'email' => CustomerService::ANONYMIZED_EMAIL ] )->save();

    expect( OrderViewToken::verify( $token ) )->toBeNull()
        ->and( lookups()->find( CustomerService::ANONYMIZED_EMAIL, 'K7QM2XW9' ) )->toBeNull();
} );

it( 'gives guest confirmations a view link and customers none', function (): void {
    config()->set( 'artisanpack.ecommerce.checkout.order_view_url', 'https://shop.test/order/{token}' );

    $guest = app( NotificationContext::class )->order( $this->order );
    $owned = app( NotificationContext::class )->order( Order::factory()->forCustomer( ArtisanPackUI\Ecommerce\Models\Customer::factory()->create() )->create() );

    expect( $guest['view_url'] )->toStartWith( 'https://shop.test/order/' . $this->order->id . '-' )
        ->and( OrderViewToken::verify( substr( $guest['view_url'], strlen( 'https://shop.test/order/' ) ) )?->is( $this->order ) )->toBeTrue()
        ->and( $owned['view_url'] )->toBeNull();
} );

it( 'looks guest orders up over GraphQL', function (): void {
    $query = 'query ($e: String!, $n: String!) { guestOrderLookup(email: $e, order_number: $n) { order_number } }';

    $this->postJson( '/graphql/ecommerce', [ 'query' => $query, 'variables' => [ 'e' => 'guest@example.test', 'n' => 'K7QM2XW9' ] ] )
        ->assertOk()
        ->assertJsonPath( 'data.guestOrderLookup.order_number', 'K7QM2XW9' );

    $this->postJson( '/graphql/ecommerce', [ 'query' => $query, 'variables' => [ 'e' => 'x@example.test', 'n' => 'K7QM2XW9' ] ] )
        ->assertOk()
        ->assertJsonPath( 'data.guestOrderLookup', null );

    $token = OrderViewToken::for( $this->order );

    $this->postJson( '/graphql/ecommerce', [ 'query' => 'query ($t: String!) { orderByViewToken(token: $t) { order_number } }', 'variables' => [ 't' => $token ] ] )
        ->assertJsonPath( 'data.orderByViewToken.order_number', 'K7QM2XW9' );
} );
