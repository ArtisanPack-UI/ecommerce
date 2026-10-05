<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\ProductWriteException;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Services\ProductService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses( RefreshDatabase::class );

/*
 * NULLs are distinct in unique indexes on every supported database, so
 * uniqueness that relied on nullable columns never held (audit B4).
 */

it( 'keeps one inventory row per stockable in the default warehouse', function (): void {
    $product = Product::factory()->create();
    $service = app( ProductService::class );

    $first  = $service->inventoryItemFor( $product );
    $second = $service->inventoryItemFor( $product );

    expect( $second->id )->toBe( $first->id )
        ->and( $first->warehouse_id )->toBe( InventoryItem::DEFAULT_WAREHOUSE )
        ->and( InventoryItem::query()->count() )->toBe( 1 );
} );

it( 'refuses a second inventory row for the same stockable at the database', function (): void {
    $product = Product::factory()->create();
    $row     = [ 'stockable_type' => $product->getMorphClass(), 'stockable_id' => $product->id, 'created_at' => now(), 'updated_at' => now() ];

    DB::table( 'ecommerce_inventory_items' )->insert( $row );

    expect( fn () => DB::table( 'ecommerce_inventory_items' )->insert( $row ) )->toThrow( UniqueConstraintViolationException::class );
} );

it( 'refuses a duplicate base price at the database', function (): void {
    $product = Product::factory()->create();
    $attrs   = [ 'priceable_type' => $product->getMorphClass(), 'priceable_id' => $product->id, 'currency' => 'USD', 'price_amount' => 1_000 ];

    ProductPrice::query()->create( $attrs );

    expect( fn () => ProductPrice::query()->create( $attrs ) )->toThrow( UniqueConstraintViolationException::class );
} );

it( 'allows the same currency in different windows', function (): void {
    $product = Product::factory()->create();
    $base    = [ 'priceable_type' => $product->getMorphClass(), 'priceable_id' => $product->id, 'currency' => 'USD', 'price_amount' => 1_000 ];

    ProductPrice::query()->create( $base );
    $sale = ProductPrice::query()->create( $base + [ 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays( 3 ) ] );

    expect( $sale->window_key )->toStartWith( 'USD|' )->not->toBe( 'USD||' )
        ->and( ProductPrice::query()->count() )->toBe( 2 );
} );

it( 'upserts a price on its currency and window instead of duplicating it', function (): void {
    $product = Product::factory()->create();
    $service = app( ProductService::class );

    $service->upsertPrice( $product, [ 'currency' => 'USD', 'price_amount' => 1_000 ] );
    $updated = $service->upsertPrice( $product, [ 'currency' => 'usd', 'price_amount' => 1_200 ] );

    expect( ProductPrice::query()->count() )->toBe( 1 )
        ->and( $updated->price_amount )->toBe( 1_200 );
} );

it( 'refuses to move a price onto another row\'s window', function (): void {
    $product = Product::factory()->create();
    $service = app( ProductService::class );

    $service->upsertPrice( $product, [ 'currency' => 'USD', 'price_amount' => 1_000 ] );
    $eur = $service->upsertPrice( $product, [ 'currency' => 'EUR', 'price_amount' => 900 ] );

    $service->updatePrice( $eur, [ 'currency' => 'USD' ] );
} )->throws( ProductWriteException::class, 'Two prices share this currency and schedule.' );
