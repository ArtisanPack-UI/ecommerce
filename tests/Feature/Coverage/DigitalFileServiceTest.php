<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\DigitalProductUpdated;
use ArtisanPackUI\Ecommerce\Exceptions\DigitalFileInUseException;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Services\DigitalFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

uses( RefreshDatabase::class );

afterEach( function (): void {
    removeAllActions( 'ap.ecommerce.digital.productUpdated' );
} );

function covDigitalFiles(): DigitalFileService
{
    return app( DigitalFileService::class );
}

it( 'creates a file, archived or not', function (): void {
    $product = Product::factory()->create();

    $live     = covDigitalFiles()->create( [ 'product_id' => $product->id, 'disk' => 'local', 'path' => 'a.pdf', 'label' => 'A', 'version' => '1.0' ] );
    $archived = covDigitalFiles()->create( [ 'product_id' => $product->id, 'disk' => 'local', 'path' => 'b.pdf', 'label' => 'B', 'version' => '1.0', 'is_archived' => true ] );

    expect( $live->exists )->toBeTrue()
        ->and( $live->archived_at )->toBeNull()
        ->and( $archived->archived_at )->not->toBeNull();
} );

it( 'keeps the original archive time when archiving again and clears it on unarchive', function (): void {
    Carbon::setTestNow( '2026-10-01 09:00:00' );
    $file = covDigitalFiles()->create( [ 'product_id' => Product::factory()->create()->id, 'disk' => 'local', 'path' => 'a.pdf', 'label' => 'A', 'is_archived' => true ] );

    Carbon::setTestNow( '2026-10-05 09:00:00' );
    covDigitalFiles()->update( $file, [ 'is_archived' => true ] );
    expect( $file->fresh()->archived_at->toDateTimeString() )->toBe( '2026-10-01 09:00:00' );

    covDigitalFiles()->update( $file, [ 'is_archived' => false ] );
    expect( $file->fresh()->archived_at )->toBeNull();

    Carbon::setTestNow();
} );

it( 'announces a new version of a product\'s file', function (): void {
    Event::fake( [ DigitalProductUpdated::class ] );
    $hooks   = [];
    $product = Product::factory()->create();
    $file    = DigitalFile::factory()->create( [ 'product_id' => $product->id, 'version' => '1.0.0' ] );

    addAction( 'ap.ecommerce.digital.productUpdated', function ( Product $updated, DigitalFile $changed ) use ( &$hooks ): void {
        $hooks[] = [ $updated->id, $changed->version ];
    } );

    covDigitalFiles()->update( $file, [ 'version' => '2.0.0' ] );

    expect( $hooks )->toBe( [ [ $product->id, '2.0.0' ] ] );
    Event::assertDispatched( DigitalProductUpdated::class, fn ( DigitalProductUpdated $event ): bool => $event->product->is( $product ) && $event->file->is( $file ) );
} );

it( 'announces a new version of a variant\'s file against the variant\'s product', function (): void {
    Event::fake( [ DigitalProductUpdated::class ] );
    $variant = ProductVariant::factory()->create();
    $file    = DigitalFile::factory()->create( [ 'product_id' => null, 'product_variant_id' => $variant->id, 'version' => '1.0.0' ] );

    covDigitalFiles()->update( $file, [ 'version' => '1.1.0' ] );

    Event::assertDispatched( DigitalProductUpdated::class, fn ( DigitalProductUpdated $event ): bool => $event->product->is( $variant->product ) );
} );

it( 'stays quiet for edits that don\'t change the version, and for a first version', function (): void {
    Event::fake( [ DigitalProductUpdated::class ] );
    $file      = DigitalFile::factory()->create( [ 'version' => '1.0.0' ] );
    $unversion = DigitalFile::factory()->create( [ 'version' => null ] );

    covDigitalFiles()->update( $file, [ 'label' => 'Renamed' ] );
    covDigitalFiles()->update( $file, [ 'version' => '1.0.0' ] );
    covDigitalFiles()->update( $unversion, [ 'version' => '1.0.0' ] );

    Event::assertNotDispatched( DigitalProductUpdated::class );
    expect( $file->fresh()->label )->toBe( 'Renamed' );
} );

it( 'deletes an unused file and refuses one customers have downloads for', function (): void {
    $unused = DigitalFile::factory()->create();
    $used   = DigitalFile::factory()->create();
    DigitalDownload::factory()->create( [ 'digital_file_id' => $used->id ] );

    covDigitalFiles()->delete( $unused );

    expect( DigitalFile::query()->find( $unused->id ) )->toBeNull()
        ->and( fn () => covDigitalFiles()->delete( $used ) )->toThrow( DigitalFileInUseException::class )
        ->and( DigitalFile::query()->find( $used->id ) )->not->toBeNull();

    try {
        covDigitalFiles()->delete( $used );
    } catch ( DigitalFileInUseException $e ) {
        expect( $e->context() )->toMatchArray( [ 'digital_file_id' => $used->id ] );
    }
} );
