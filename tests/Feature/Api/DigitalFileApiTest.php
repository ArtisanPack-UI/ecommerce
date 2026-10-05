<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\DigitalProductUpdated;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Notifications\EcommerceNotification;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

const DIGITAL_FILES_API = '/api/ecommerce/v1/admin/digital-files';

it( 'requires the digitalFile abilities', function (): void {
    $this->getJson( DIGITAL_FILES_API )->assertUnauthorized();
    $this->actingAs( ecommerceShopper(), 'sanctum' )->getJson( DIGITAL_FILES_API )->assertForbidden();
} );

it( 'creates, lists, updates, and deletes digital files', function (): void {
    $product = Product::factory()->digital()->create();
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $id = $this->postJson( DIGITAL_FILES_API, [
        'product_id' => $product->id,
        'disk'       => 'local',
        'path'       => 'digital/manual.pdf',
        'label'      => 'Manual',
        'version'    => '1.0.0',
    ], idem() )->assertCreated()->assertJsonPath( 'data.type', 'digitalFile' )->json( 'data.id' );

    $this->getJson( DIGITAL_FILES_API . "?filter[product_id]={$product->id}" )->assertOk()->assertJsonCount( 1, 'data' );

    $this->patchJson( DIGITAL_FILES_API . "/{$id}", [ 'is_streaming_only' => true ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.is_streaming_only', true );

    $this->deleteJson( DIGITAL_FILES_API . "/{$id}", [], idem() )->assertOk();
    expect( DigitalFile::query()->count() )->toBe( 0 );
} );

it( 'rejects paths that leave the disk and unknown disks', function ( array $payload, string $field ): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $fields = collect( $this->postJson( DIGITAL_FILES_API, $payload + [ 'label' => 'X' ], idem() )->assertStatus( 422 )->json( 'errors' ) )->pluck( 'field' );

    expect( $fields )->toContain( $field );
} )->with( [
    'parent traversal' => [ [ 'disk' => 'local', 'path' => '../.env' ], 'path' ],
    'absolute path'    => [ [ 'disk' => 'local', 'path' => '/etc/passwd' ], 'path' ],
    'unknown disk'     => [ [ 'disk' => 'nope', 'path' => 'a.pdf' ], 'disk' ],
    'disallowed disk'  => [ [ 'disk' => 'public', 'path' => 'a.pdf' ], 'disk' ],
    'no location'      => [ [], 'path' ],
] );

it( 'announces a new version to everyone who bought the file', function (): void {
    Event::fake( [ DigitalProductUpdated::class ] );

    $product = Product::factory()->digital()->create();
    $file    = DigitalFile::factory()->create( [ 'product_id' => $product->id, 'version' => '1.0.0' ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->patchJson( DIGITAL_FILES_API . "/{$file->id}", [ 'label' => 'Renamed' ], idem() )
        ->assertOk();

    Event::assertNotDispatched( DigitalProductUpdated::class );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->patchJson( DIGITAL_FILES_API . "/{$file->id}", [ 'version' => '2.0.0' ], idem() )
        ->assertOk();

    Event::assertDispatched( DigitalProductUpdated::class, fn ( DigitalProductUpdated $event ): bool => $event->file->is( $file ) && $event->product->is( $product ) );
} );

it( 'emails buyers once each when a file gets a new version', function (): void {
    Notification::fake();
    $product  = Product::factory()->digital()->create( [ 'name' => 'Field Guide' ] );
    $file     = DigitalFile::factory()->create( [ 'product_id' => $product->id, 'version' => '1.0.0' ] );
    $customer = Customer::factory()->create();

    foreach ( range( 1, 2 ) as $purchase ) {
        $item = OrderItem::factory()->create( [ 'order_id' => Order::factory()->forCustomer( $customer ), 'product_id' => $product->id ] );
        DigitalDownload::factory()->create( [ 'order_item_id' => $item->id, 'digital_file_id' => $file->id ] );
    }

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->patchJson( DIGITAL_FILES_API . "/{$file->id}", [ 'version' => '1.1.0' ], idem() )
        ->assertOk();

    Notification::assertSentToTimes( $customer, EcommerceNotification::class, 1 );
    Notification::assertSentTo( $customer, EcommerceNotification::class, fn ( EcommerceNotification $notification ): bool => NotificationCatalog::DIGITAL_PRODUCT_UPDATED === $notification->templateKey
        && str_contains( $notification->toMail( $customer )->htmlBody, '1.1.0' ) );
} );

it( 'refuses to delete a file customers hold downloads for, and lets it be archived instead', function (): void {
    $product  = Product::factory()->digital()->create();
    $file     = DigitalFile::factory()->create( [ 'product_id' => $product->id ] );
    $download = DigitalDownload::factory()->create( [ 'digital_file_id' => $file->id ] );
    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->deleteJson( DIGITAL_FILES_API . "/{$file->id}", [], idem() )
        ->assertStatus( 409 )
        ->assertHeader( 'Content-Type', 'application/problem+json' )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/digital-file-in-use' );

    expect( DigitalFile::query()->whereKey( $file->id )->exists() )->toBeTrue()
        ->and( DigitalDownload::query()->whereKey( $download->id )->exists() )->toBeTrue();

    $archivedAt = $this->patchJson( DIGITAL_FILES_API . "/{$file->id}", [ 'is_archived' => true ], idem() )
        ->assertOk()
        ->json( 'data.archived_at' );

    expect( $archivedAt )->not->toBeNull();

    // Saving an archived file again keeps its original archive time.
    $this->travel( 1 )->days();
    $this->patchJson( DIGITAL_FILES_API . "/{$file->id}", [ 'label' => 'Renamed', 'is_archived' => true ], idem() )
        ->assertJsonPath( 'data.archived_at', $archivedAt );

    $this->patchJson( DIGITAL_FILES_API . "/{$file->id}", [ 'is_archived' => false ], idem() )
        ->assertJsonPath( 'data.archived_at', null );
} );

it( 'stops issuing entitlements for an archived file', function (): void {
    $product = Product::factory()->digital()->create();
    $live    = DigitalFile::factory()->create( [ 'product_id' => $product->id ] );
    DigitalFile::factory()->create( [ 'product_id' => $product->id, 'archived_at' => now() ] );
    $item = OrderItem::factory()->create( [ 'product_id' => $product->id ] );

    expect( app( ArtisanPackUI\Ecommerce\Services\DigitalDownloadService::class )->filesFor( $item )->pluck( 'id' )->all() )->toBe( [ $live->id ] );
} );

it( 'cannot delete a sold file even from outside the engine', function (): void {
    $file = DigitalFile::factory()->create();
    DigitalDownload::factory()->create( [ 'digital_file_id' => $file->id ] );

    expect( fn () => $file->delete() )->toThrow( Illuminate\Database\QueryException::class );
} );
