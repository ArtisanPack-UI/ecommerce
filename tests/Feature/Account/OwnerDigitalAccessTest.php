<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\LicenseActivation;
use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Services\DigitalDownloadService;
use ArtisanPackUI\Ecommerce\Services\LicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\ApiUser;

uses( RefreshDatabase::class );

const OWNER_API = '/api/ecommerce/v1/me';

beforeEach( function (): void {
    Storage::fake( 'local' );
    Storage::disk( 'local' )->put( 'digital/book.pdf', 'book-bytes' );

    $this->owner = Customer::factory()->create( [ 'email' => 'ada@example.test', 'user_id' => 7 ] );
    $this->other = Customer::factory()->create( [ 'email' => 'sam@example.test' ] );
} );

/**
 * An entitlement to `digital/book.pdf` on an order of `$customer`.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $file
 */
function ownedDownload( Customer $customer, array $attributes = [], array $file = [] ): DigitalDownload
{
    $order = Order::factory()->forCustomer( $customer )->create();
    $item  = OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_snapshot' => [ 'name' => 'The Book' ] ] );
    $pdf   = DigitalFile::factory()->create( $file + [ 'disk' => 'local', 'path' => 'digital/book.pdf', 'label' => 'The Book', 'version' => '2.0' ] );

    $download = app( DigitalDownloadService::class )->issue( $item, $pdf, $attributes['downloads_remaining'] ?? 3, $attributes['expires_at'] ?? now()->addDays( 30 ) );

    return $download->fresh();
}

function actingAsOwner(): void
{
    Sanctum::actingAs( ApiUser::make( 7, 'ada@example.test' ), [ 'ecommerce:storefront' ] );
}

it( 'lists only the signed-in owner\'s downloads, with file and order line, and no storage path', function (): void {
    $mine = ownedDownload( $this->owner );
    ownedDownload( $this->other );

    expect( app( DigitalDownloadService::class )->forCustomer( $this->owner )->pluck( 'id' )->all() )->toBe( [ $mine->id ] );

    actingAsOwner();

    $this->getJson( OWNER_API . '/downloads?include=file,order_item' )
        ->assertOk()
        ->assertJsonCount( 1, 'data' )
        ->assertJsonPath( 'data.0.id', $mine->id )
        ->assertJsonPath( 'data.0.downloads_remaining', 3 )
        ->assertJsonPath( 'data.0.file.label', 'The Book' )
        ->assertJsonPath( 'data.0.file.version', '2.0' )
        ->assertJsonPath( 'data.0.order_item.product_snapshot.name', 'The Book' )
        ->assertJsonMissingPath( 'data.0.file.path' )
        ->assertJsonMissingPath( 'data.0.file.disk' );
} );

it( 'downloads an owned file by entitlement id and counts it', function (): void {
    $mine = ownedDownload( $this->owner );
    actingAsOwner();

    $response = $this->get( OWNER_API . "/downloads/{$mine->id}" );

    $response->assertOk();
    expect( $response->streamedContent() )->toBe( 'book-bytes' )
        ->and( $mine->fresh()->downloads_remaining )->toBe( 2 )
        ->and( $mine->fresh()->download_count )->toBe( 1 );
} );

it( 'refuses another customer\'s entitlement, an expired one, and a used-up one', function (): void {
    $theirs  = ownedDownload( $this->other );
    $expired = ownedDownload( $this->owner, [ 'expires_at' => now()->subDay() ] );
    $used    = ownedDownload( $this->owner, [ 'downloads_remaining' => 0 ] );
    actingAsOwner();

    $this->getJson( OWNER_API . "/downloads/{$theirs->id}" )->assertNotFound()->assertJsonPath( 'type', fn ( string $type ): bool => str_ends_with( $type, 'download-not-found' ) );
    $this->getJson( OWNER_API . "/downloads/{$expired->id}" )->assertStatus( 410 );
    $this->getJson( OWNER_API . "/downloads/{$used->id}" )->assertStatus( 410 );

    expect( $theirs->fresh()->download_count )->toBe( 0 );
} );

it( 'streams a streaming-only file and refuses to download it', function (): void {
    $video = ownedDownload( $this->owner, [], [ 'is_streaming_only' => true ] );
    actingAsOwner();

    $this->getJson( OWNER_API . "/downloads/{$video->id}" )->assertForbidden();
    $this->get( OWNER_API . "/downloads/{$video->id}/stream" )->assertOk();

    expect( $video->fresh()->download_count )->toBe( 1 );
} );

it( 'needs a signed-in shopper', function (): void {
    $mine = ownedDownload( $this->owner );

    $this->getJson( OWNER_API . '/downloads' )->assertUnauthorized();
    $this->getJson( OWNER_API . "/downloads/{$mine->id}" )->assertUnauthorized();
} );

it( 'lists the owner\'s license keys in full with their activations', function (): void {
    $order = Order::factory()->forCustomer( $this->owner )->create();
    $mine  = app( LicenseService::class )->issue( OrderItem::factory()->create( [ 'order_id' => $order->id ] ), null, 3 );
    LicenseActivation::factory()->create( [ 'license_key_id' => $mine->id, 'machine_fingerprint' => 'laptop' ] );
    LicenseActivation::factory()->create( [ 'license_key_id' => $mine->id, 'machine_fingerprint' => 'desktop' ] );
    $mine->forceFill( [ 'activations_count' => 2, 'meta' => [ 'revoked_reason' => 'internal note' ] ] )->save();

    LicenseKey::factory()->create( [ 'order_item_id' => OrderItem::factory()->create( [ 'order_id' => Order::factory()->forCustomer( $this->other )->create()->id ] )->id ] );

    expect( app( LicenseService::class )->forCustomer( $this->owner )->pluck( 'id' )->all() )->toBe( [ $mine->id ] );

    actingAsOwner();

    $this->getJson( OWNER_API . '/license-keys?include=activations' )
        ->assertOk()
        ->assertJsonCount( 1, 'data' )
        ->assertJsonPath( 'data.0.key', $mine->key )
        ->assertJsonPath( 'data.0.activations_count', 2 )
        ->assertJsonPath( 'data.0.activations_limit', 3 )
        ->assertJsonCount( 2, 'data.0.activations' )
        ->assertJsonMissingPath( 'data.0.meta' );
} );
