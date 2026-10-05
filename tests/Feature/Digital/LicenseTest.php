<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\LicenseActivated;
use ArtisanPackUI\Ecommerce\Events\LicenseDeactivated;
use ArtisanPackUI\Ecommerce\Events\LicenseIssued;
use ArtisanPackUI\Ecommerce\Events\LicenseRevoked;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Notifications\EcommerceNotification;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Services\LicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/../Api/ApiTestHelpers.php';

uses( RefreshDatabase::class );

const LICENSE_API = '/api/ecommerce/v1';

function licenses(): LicenseService
{
    return app( LicenseService::class );
}

it( 'issues readable, unique keys', function (): void {
    Event::fake( [ LicenseIssued::class ] );

    $key = licenses()->issue( OrderItem::factory()->create(), null, 3 );

    expect( $key->key )->toMatch( '/^([A-HJ-NP-Z2-9]{5}-){4}[A-HJ-NP-Z2-9]{5}$/' )
        ->and( $key->activations_limit )->toBe( 3 );

    Event::assertDispatched( LicenseIssued::class );
} );

it( 'activates a new machine and recognises it again', function (): void {
    Event::fake( [ LicenseActivated::class ] );
    $key = LicenseKey::factory()->create( [ 'activations_limit' => 2 ] );

    $first = licenses()->validate( $key->key, 'machine-a', '203.0.113.1' );
    $again = licenses()->validate( strtolower( " {$key->key} " ), 'machine-a' );

    expect( $first )->toMatchArray( [ 'valid' => true, 'revoked' => false, 'reason' => null ] )
        ->and( $first['product']['name'] )->toBe( $key->orderItem->product_snapshot['name'] )
        ->and( $again['valid'] )->toBeTrue()
        ->and( $key->fresh()->activations_count )->toBe( 1 )
        ->and( $key->activations()->sole()->ip_address )->toBe( '203.0.113.1' );

    Event::assertDispatchedTimes( LicenseActivated::class, 1 );
} );

it( 'refuses a machine beyond the activation limit', function (): void {
    $key = LicenseKey::factory()->create( [ 'activations_limit' => 5 ] );

    foreach ( range( 1, 5 ) as $machine ) {
        expect( licenses()->validate( $key->key, "machine-{$machine}" )['valid'] )->toBeTrue();
    }

    expect( licenses()->validate( $key->key, 'machine-6' ) )->toMatchArray( [ 'valid' => false, 'reason' => 'activation-limit-reached' ] )
        ->and( licenses()->validate( $key->key, 'machine-3' )['valid'] )->toBeTrue()
        ->and( $key->fresh()->activations_count )->toBe( 5 );
} );

it( 'treats fingerprints case- and whitespace-insensitively', function (): void {
    $key = LicenseKey::factory()->create( [ 'activations_limit' => 1 ] );

    expect( licenses()->validate( $key->key, 'Laptop-ABC' )['valid'] )->toBeTrue()
        ->and( licenses()->validate( $key->key, ' laptop-abc ' )['valid'] )->toBeTrue()
        ->and( $key->fresh()->activations_count )->toBe( 1 )
        ->and( $key->activations()->sole()->machine_fingerprint )->toBe( 'laptop-abc' );
} );

it( 'reports revoked, expired, and unknown keys as invalid', function (): void {
    Event::fake( [ LicenseRevoked::class ] );
    $revoked = licenses()->revoke( LicenseKey::factory()->create(), 'Chargeback' );
    $expired = LicenseKey::factory()->create( [ 'expires_at' => now()->subDay() ] );

    expect( licenses()->validate( $revoked->key, 'm' ) )->toMatchArray( [ 'valid' => false, 'revoked' => true, 'reason' => 'revoked' ] )
        ->and( $revoked->meta['revoked_reason'] )->toBe( 'Chargeback' )
        ->and( licenses()->validate( $expired->key, 'm' ) )->toMatchArray( [ 'valid' => false, 'reason' => 'expired' ] )
        ->and( licenses()->validate( 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', 'm' ) )->toMatchArray( [ 'valid' => false, 'product' => null, 'reason' => 'not-found' ] );

    Event::assertDispatched( LicenseRevoked::class, fn ( LicenseRevoked $event ): bool => 'Chargeback' === $event->reason );
} );

it( 'lets the validating filter adjust the result', function (): void {
    $key = LicenseKey::factory()->create();

    addFilter( 'ap.ecommerce.license.validating', fn ( array $result, string $sent, string $fingerprint ): array => [ ...$result, 'tier' => 'pro:' . $fingerprint ] );

    expect( licenses()->validate( $key->key, 'box' )['tier'] )->toBe( 'pro:box' );
} );

it( 'issues keys for licensed products on paid orders', function (): void {
    Notification::fake();
    $customer = Customer::factory()->create();
    $licensed = Product::factory()->digital()->create( [ 'meta' => [ 'licensing' => [ 'enabled' => true, 'activations_limit' => 2, 'expires_in_days' => 365 ] ] ] );
    $plain    = Product::factory()->digital()->create();
    $order    = Order::factory()->forCustomer( $customer )->create( [ 'payment_status' => 'paid' ] );
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => $licensed->id, 'quantity' => 3 ] );
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => $plain->id ] );

    $issued = licenses()->issueForOrder( $order );

    expect( $issued )->toHaveCount( 1 )
        ->and( $issued[0]->activations_limit )->toBe( 6 )
        ->and( $issued[0]->expires_at->isSameDay( now()->addDays( 365 ) ) )->toBeTrue()
        ->and( licenses()->issueForOrder( $order ) )->toBe( [] );
} );

it( 'tells the customer when their license is activated on a new machine', function (): void {
    Notification::fake();
    $customer = Customer::factory()->create();
    $item     = OrderItem::factory()->create( [ 'order_id' => Order::factory()->forCustomer( $customer ) ] );
    $key      = LicenseKey::factory()->create( [ 'order_item_id' => $item->id ] );

    licenses()->validate( $key->key, 'laptop' );
    licenses()->validate( $key->key, 'laptop' );

    Notification::assertSentToTimes( $customer, EcommerceNotification::class, 1 );
    Notification::assertSentTo( $customer, EcommerceNotification::class, fn ( EcommerceNotification $notification ): bool => NotificationCatalog::LICENSE_ACTIVATED === $notification->templateKey
        && substr( $key->key, -5 ) === $notification->variables['License']['key_hint']
        && ! str_contains( json_encode( $notification->variables ), $key->key ) );
} );

it( 'validates keys over the public endpoint', function (): void {
    $key = LicenseKey::factory()->create( [ 'activations_limit' => 1 ] );

    $this->postJson( LICENSE_API . '/license/validate', [ 'key' => $key->key, 'fingerprint' => 'a' ] )->assertStatus( 400 );
    $this->postJson( LICENSE_API . '/license/validate', [ 'key' => $key->key ], idem() )->assertStatus( 422 );

    $this->postJson( LICENSE_API . '/license/validate', [ 'key' => $key->key, 'fingerprint' => 'a' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.valid', true )
        ->assertJsonPath( 'data.revoked', false )
        ->assertJsonStructure( [ 'data' => [ 'valid', 'expires_at', 'product' => [ 'id', 'name' ], 'revoked', 'reason' ] ] );

    $this->postJson( LICENSE_API . '/license/validate', [ 'key' => $key->key, 'fingerprint' => 'b' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.valid', false )
        ->assertJsonPath( 'data.reason', 'activation-limit-reached' );
} );

it( 'rate-limits validation per license key', function (): void {
    config()->set( 'artisanpack.ecommerce.rate_limits.license.validate.per_license', 2 );
    $key = LicenseKey::factory()->create();

    foreach ( [ $key->key, strtolower( $key->key ) ] as $variant ) {
        $this->postJson( LICENSE_API . '/license/validate', [ 'key' => $variant, 'fingerprint' => 'a' ], idem() )->assertOk();
    }

    $this->postJson( LICENSE_API . '/license/validate', [ 'key' => $key->key, 'fingerprint' => 'a' ], idem() )->assertStatus( 429 );
    $this->postJson( LICENSE_API . '/license/validate', [ 'key' => 'OTHER-KEY', 'fingerprint' => 'a' ], idem() )->assertOk();
} );

it( 'lets admins find and revoke keys', function (): void {
    $key = LicenseKey::factory()->create();

    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->postJson( LICENSE_API . "/admin/license-keys/{$key->id}/revoke", [], idem() )
        ->assertForbidden();

    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->getJson( LICENSE_API . '/admin/license-keys?filter[key]=' . strtolower( $key->key ) . '&include=activations' )
        ->assertOk()
        ->assertJsonPath( 'data.0.id', $key->id )
        ->assertJsonPath( 'data.0.activations', [] );

    $this->postJson( LICENSE_API . "/admin/license-keys/{$key->id}/revoke", [ 'reason' => 'Refunded' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.is_revoked', true );

    $this->postJson( LICENSE_API . '/license/validate', [ 'key' => $key->key, 'fingerprint' => 'a' ], idem() )
        ->assertJsonPath( 'data.revoked', true )
        ->assertJsonPath( 'data.valid', false );
} );

it( 'stores keys encrypted and looks them up by hash', function (): void {
    $license = app( LicenseService::class )->issue( OrderItem::factory()->create() );
    $row     = Illuminate\Support\Facades\DB::table( 'ecommerce_license_keys' )->where( 'id', $license->id )->first();

    expect( $row->key )->not->toBe( $license->key )
        ->and( $row->key )->not->toContain( $license->key )
        ->and( $row->key_hash )->toBe( LicenseKey::hashFor( $license->key ) )
        ->and( $license->toArray() )->not->toHaveKey( 'key_hash' );

    expect( app( LicenseService::class )->validate( strtolower( " {$license->key} " ), 'machine-1' )['valid'] )->toBeTrue()
        ->and( app( LicenseService::class )->validate( 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', 'machine-1' )['reason'] )->toBe( 'not-found' );
} );

it( 'keeps validating keys after an app key rotation', function (): void {
    $license = app( LicenseService::class )->issue( OrderItem::factory()->create() );
    $oldKey  = config( 'app.key' );
    $oldHash = $license->key_hash;

    // Rotate: the old key moves to previous_keys, as Laravel documents.
    config()->set( 'app.previous_keys', [ $oldKey ] );
    config()->set( 'app.key', 'base64:' . base64_encode( random_bytes( 32 ) ) );
    app()->forgetInstance( 'encrypter' );
    Illuminate\Support\Facades\Crypt::clearResolvedInstance( 'encrypter' );

    expect( app( LicenseService::class )->validate( $license->key, 'machine-1' )['valid'] )->toBeTrue()
        ->and( $license->fresh()->key_hash )->not->toBe( $oldHash )
        ->and( $license->fresh()->key_hash )->toBe( LicenseKey::hashFor( $license->key ) );
} );

it( 'never stores a validation response for replay', function (): void {
    $license = app( LicenseService::class )->issue( OrderItem::factory()->create() );

    $this->postJson( LICENSE_API . '/license/validate', [ 'key' => $license->key, 'fingerprint' => 'a' ], idem() )->assertOk();

    expect( (string) ArtisanPackUI\Ecommerce\Models\IdempotencyRecord::query()->value( 'response_body' ) )->not->toContain( 'valid' );
} );

it( 'filters admin listings by the plain key', function (): void {
    $license = app( LicenseService::class )->issue( OrderItem::factory()->create() );
    app( LicenseService::class )->issue( OrderItem::factory()->create() );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( LICENSE_API . '/admin/license-keys?filter[key]=' . strtolower( $license->key ) )
        ->assertOk()
        ->assertJsonCount( 1, 'data' )
        ->assertJsonPath( 'data.0.key', $license->key );
} );

it( 'frees an activation slot when a machine is deactivated', function (): void {
    Event::fake( [ LicenseDeactivated::class ] );
    $key = LicenseKey::factory()->create( [ 'activations_limit' => 2 ] );

    licenses()->validate( $key->key, 'machine-a' );
    licenses()->validate( $key->key, 'machine-b' );

    expect( licenses()->validate( $key->key, 'machine-c' )['reason'] )->toBe( 'activation-limit-reached' );

    expect( licenses()->deactivate( $key->fresh(), ' MACHINE-A ' ) )->toBeTrue()
        ->and( $key->fresh()->activations_count )->toBe( 1 )
        ->and( licenses()->validate( $key->key, 'machine-c' )['valid'] )->toBeTrue()
        ->and( licenses()->deactivate( $key->fresh(), 'machine-a' ) )->toBeFalse();

    Event::assertDispatchedTimes( LicenseDeactivated::class, 1 );
} );

it( 'deactivates through the public endpoint', function (): void {
    $key = LicenseKey::factory()->create( [ 'activations_limit' => 1 ] );
    licenses()->validate( $key->key, 'machine-a' );

    $this->postJson( LICENSE_API . '/license/deactivate', [ 'key' => $key->key, 'fingerprint' => 'machine-a' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.deactivated', true )
        ->assertJsonPath( 'data.activations_count', 0 )
        ->assertJsonPath( 'data.activations_limit', 1 );

    $this->postJson( LICENSE_API . '/license/deactivate', [ 'key' => $key->key, 'fingerprint' => 'machine-a' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.deactivated', false )
        ->assertJsonPath( 'data.reason', 'not-activated' );

    $this->postJson( LICENSE_API . '/license/deactivate', [ 'key' => 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', 'fingerprint' => 'machine-a' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.reason', 'not-found' );

    $this->postJson( LICENSE_API . '/license/deactivate', [ 'key' => $key->key ], idem() )->assertStatus( 422 );
    $this->postJson( LICENSE_API . '/license/deactivate', [ 'key' => $key->key, 'fingerprint' => 'machine-a' ] )->assertStatus( 400 );
} );
