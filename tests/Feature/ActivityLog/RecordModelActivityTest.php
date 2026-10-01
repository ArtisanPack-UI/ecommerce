<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\ActivityLogEntry;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Services\ActivityLogService;
use ArtisanPackUI\Ecommerce\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses( RefreshDatabase::class );

/**
 * Entries for `$subject`, oldest first.
 *
 * @return array<int, ActivityLogEntry>
 */
function activityFor( Illuminate\Database\Eloquent\Model $subject ): array
{
    return ActivityLogEntry::query()->forSubject( $subject )->orderBy( 'id' )->get()->all();
}

/**
 * The latest entry of `$type` for `$subject`.
 */
function lastActivity( Illuminate\Database\Eloquent\Model $subject, string $type ): ?ActivityLogEntry
{
    return ActivityLogEntry::query()->forSubject( $subject )->where( 'event_type', $type )->orderByDesc( 'id' )->first();
}

it( 'records product created, updated, and deleted', function (): void {
    $product = Product::factory()->create( [ 'name' => 'Mug', 'sku' => 'MUG-1' ] );
    $product->update( [ 'name' => 'Big mug' ] );
    $product->delete();

    $types = array_map( fn ( ActivityLogEntry $entry ): string => $entry->event_type, activityFor( $product ) );

    expect( $types )->toBe( [ 'product.created', 'product.updated', 'product.deleted' ] )
        ->and( lastActivity( $product, 'product.created' )->payload )->toMatchArray( [ 'name' => 'Mug', 'sku' => 'MUG-1' ] )
        ->and( lastActivity( $product, 'product.updated' )->payload['changes'] )->toBe( [ 'name' => [ 'before' => 'Mug', 'after' => 'Big mug' ] ] );
} );

it( 'skips updates that only touch engine-maintained counters', function (): void {
    $product = Product::factory()->create();
    $product->update( [ 'reviews_count' => 4, 'avg_rating' => 4.5 ] );

    $promotion = Promotion::factory()->create();
    $promotion->increment( 'times_used' );

    $customer = Customer::factory()->create();
    $customer->update( [ 'orders_count' => 2, 'total_spent_amount' => 1_000 ] );

    expect( lastActivity( $product, 'product.updated' ) )->toBeNull()
        ->and( lastActivity( $promotion, 'promotion.updated' ) )->toBeNull()
        ->and( lastActivity( $customer, 'customer.updated' ) )->toBeNull();
} );

it( 'files variant writes against the product', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create( [ 'product_id' => $product->id, 'sku' => 'V-1' ] );
    $variant->update( [ 'sku' => 'V-2' ] );
    $variant->delete();

    expect( lastActivity( $product, 'variant.created' )->payload )->toMatchArray( [ 'variant_id' => $variant->id, 'sku' => 'V-1' ] )
        ->and( lastActivity( $product, 'variant.updated' )->payload['changes']['sku'] )->toBe( [ 'before' => 'V-1', 'after' => 'V-2' ] )
        ->and( lastActivity( $product, 'variant.deleted' )->payload['variant_id'] )->toBe( $variant->id );
} );

it( 'files product and variant price writes against the product', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create( [ 'product_id' => $product->id ] );

    $price = ProductPrice::factory()->forPriceable( $product )->create( [ 'price_amount' => 1_000, 'currency' => 'USD' ] );
    $price->update( [ 'price_amount' => 1_200 ] );

    $variantPrice = ProductPrice::factory()->forPriceable( $variant )->create( [ 'price_amount' => 500 ] );
    $variantPrice->delete();

    expect( lastActivity( $product, 'price.created' )->payload )->toMatchArray( [ 'price_id' => $variantPrice->id, 'variant_id' => $variant->id, 'amount' => 500 ] )
        ->and( lastActivity( $product, 'price.updated' )->payload )->toMatchArray( [
            'price_id'   => $price->id,
            'variant_id' => null,
            'currency'   => 'USD',
            'changes'    => [ 'price_amount' => [ 'before' => 1_000, 'after' => 1_200 ] ],
        ] )
        ->and( lastActivity( $product, 'price.deleted' )->payload['price_id'] )->toBe( $variantPrice->id );
} );

it( 'records inventory adjustments against the owning product', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create( [ 'product_id' => $product->id ] );
    $item    = InventoryItem::factory()->create( [ 'stockable_type' => ProductVariant::class, 'stockable_id' => $variant->id, 'quantity_on_hand' => 10 ] );

    app( InventoryService::class )->adjust( $item, -3, 'manual count' );

    expect( lastActivity( $product, 'inventory.adjusted' )->payload )->toMatchArray( [
        'inventory_item_id' => $item->id,
        'variant_id'        => $variant->id,
        'delta'             => -3,
        'quantity_on_hand'  => [ 'before' => 10, 'after' => 7 ],
        'reason'            => 'manual count',
    ] );
} );

it( 'records customer created, updated, and deleted', function (): void {
    $customer = Customer::factory()->create( [ 'first_name' => 'Jane' ] );
    $customer->update( [ 'first_name' => 'Janet' ] );
    $customer->delete();

    expect( lastActivity( $customer, 'customer.created' )->payload['first_name'] )->toBe( 'Jane' )
        ->and( lastActivity( $customer, 'customer.updated' )->payload['changes']['first_name'] )->toBe( [ 'before' => 'Jane', 'after' => 'Janet' ] )
        ->and( lastActivity( $customer, 'customer.deleted' ) )->not->toBeNull();
} );

it( 'records promotion and coupon writes against the promotion', function (): void {
    $promotion = Promotion::factory()->create( [ 'name' => 'Spring' ] );
    $promotion->update( [ 'is_active' => false ] );

    $coupon = Coupon::factory()->create( [ 'promotion_id' => $promotion->id, 'code' => 'SPRING10' ] );
    $coupon->update( [ 'code' => 'SPRING15' ] );
    $coupon->delete();

    $promotion->delete();

    expect( lastActivity( $promotion, 'promotion.created' )->payload['name'] )->toBe( 'Spring' )
        ->and( lastActivity( $promotion, 'promotion.updated' )->payload['changes'] )->toBe( [ 'is_active' => [ 'before' => true, 'after' => false ] ] )
        ->and( lastActivity( $promotion, 'coupon.created' )->payload )->toMatchArray( [ 'coupon_id' => $coupon->id, 'code' => 'SPRING10' ] )
        ->and( lastActivity( $promotion, 'coupon.updated' )->payload['changes']['code'] )->toBe( [ 'before' => 'SPRING10', 'after' => 'SPRING15' ] )
        ->and( lastActivity( $promotion, 'coupon.deleted' )->payload['code'] )->toBe( 'SPRING15' )
        ->and( lastActivity( $promotion, 'promotion.deleted' ) )->not->toBeNull();
} );

it( 'records nothing from observers when disabled', function (): void {
    config()->set( 'artisanpack.ecommerce.activity_log.enabled', false );

    $product = Product::factory()->create();
    $product->update( [ 'name' => 'x' ] );
    app( InventoryService::class )->adjust( InventoryItem::factory()->create( [ 'stockable_id' => $product->id ] ), 1, 'x' );

    expect( DB::table( 'ecommerce_activity_log' )->count() )->toBe( 0 );
} );

it( 'only records event types it documents', function (): void {
    $product = Product::factory()->create();
    $product->update( [ 'name' => 'x' ] );
    Coupon::factory()->create();
    Customer::factory()->create()->update( [ 'phone' => '1' ] );

    $types = ActivityLogEntry::query()->distinct()->pluck( 'event_type' )->all();

    expect( array_diff( $types, array_keys( ActivityLogService::EVENT_TYPES ) ) )->toBe( [] );
} );

it( 'never records a price\'s cost', function (): void {
    $price = ProductPrice::factory()->create( [ 'cost_amount' => 100 ] );
    $price->update( [ 'cost_amount' => 250, 'price_amount' => 999 ] );

    expect( json_encode( ActivityLogEntry::query()->where( 'event_type', 'price.updated' )->sole()->payload ) )
        ->not->toContain( 'cost_amount' );
} );

it( 'does not fail the business write when recording throws', function (): void {
    addFilter( 'ap.ecommerce.activity.recording', function (): array {
        throw new RuntimeException( 'log store down' );
    } );

    $product = Product::factory()->create( [ 'name' => 'Still saved' ] );

    expect( $product->exists )->toBeTrue();

    removeAllFilters( 'ap.ecommerce.activity.recording' );
} );

it( 'keeps a stock adjustment when its activity entry fails', function (): void {
    $item = InventoryItem::factory()->create( [ 'quantity_on_hand' => 5 ] );

    addFilter( 'ap.ecommerce.activity.recording', function (): array {
        throw new RuntimeException( 'log store down' );
    } );

    app( InventoryService::class )->adjust( $item, 3, 'intake' );

    removeAllFilters( 'ap.ecommerce.activity.recording' );

    expect( $item->fresh()->quantity_on_hand )->toBe( 8 );
} );
