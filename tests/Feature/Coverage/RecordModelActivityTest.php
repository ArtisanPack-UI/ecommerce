<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Listeners\RecordModelActivity;
use ArtisanPackUI\Ecommerce\Models\ActivityLogEntry;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

/**
 * The activity entries recorded against `$subject`, oldest first.
 *
 * @return array<int, string>
 */
function covActivityTypes( Model $subject ): array
{
    return ActivityLogEntry::query()
        ->where( 'subject_type', $subject->getMorphClass() )
        ->where( 'subject_id', $subject->getKey() )
        ->orderBy( 'id' )
        ->pluck( 'event_type' )
        ->all();
}

function covLatestActivity( Model $subject ): ActivityLogEntry
{
    return ActivityLogEntry::query()
        ->where( 'subject_type', $subject->getMorphClass() )
        ->where( 'subject_id', $subject->getKey() )
        ->orderByDesc( 'id' )
        ->firstOrFail();
}

it( 'observes the catalog, customer, and promotion models', function (): void {
    expect( RecordModelActivity::MODELS )->toBe( [ Product::class, ProductVariant::class, ProductPrice::class, Customer::class, Promotion::class, Coupon::class ] );
} );

it( 'records a product being created, changed, and deleted', function (): void {
    $product = Product::factory()->create( [ 'name' => 'Mug', 'status' => 'draft' ] );

    expect( covLatestActivity( $product )->event_type )->toBe( 'product.created' )
        ->and( covLatestActivity( $product )->payload )->toMatchArray( [ 'name' => 'Mug', 'status' => 'draft' ] );

    $product->update( [ 'name' => 'Big mug', 'status' => 'active' ] );

    expect( covLatestActivity( $product )->event_type )->toBe( 'product.updated' )
        ->and( covLatestActivity( $product )->payload['changes'] )->toEqual( [
            'name'   => [ 'before' => 'Mug', 'after' => 'Big mug' ],
            'status' => [ 'before' => 'draft', 'after' => 'active' ],
        ] );

    $product->delete();

    expect( covActivityTypes( $product ) )->toBe( [ 'product.created', 'product.updated', 'product.deleted' ] );
} );

it( 'ignores changes to maintained columns and no-op saves', function (): void {
    $product  = Product::factory()->create();
    $customer = Customer::factory()->create();
    $promo    = Promotion::factory()->create();

    $product->forceFill( [ 'avg_rating' => 4.5, 'reviews_count' => 2 ] )->save();
    $customer->forceFill( [ 'orders_count' => 3, 'total_spent_amount' => 9_000, 'last_ordered_at' => now() ] )->save();
    $promo->forceFill( [ 'times_used' => 7 ] )->save();
    $product->save();

    expect( covActivityTypes( $product ) )->toBe( [ 'product.created' ] )
        ->and( covActivityTypes( $customer ) )->toBe( [ 'customer.created' ] )
        ->and( covActivityTypes( $promo ) )->toBe( [ 'promotion.created' ] );
} );

it( 'files variant, price, and coupon changes under their product or promotion', function (): void {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create( [ 'product_id' => $product->id, 'sku' => 'MUG-L' ] );
    ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => 1_500 ] );
    ProductPrice::factory()->forPriceable( $variant )->create( [ 'currency' => 'EUR', 'price_amount' => 1_700 ] );

    $promotion = Promotion::factory()->coupon()->create();
    Coupon::factory()->create( [ 'promotion_id' => $promotion->id, 'code' => 'SAVE10' ] );

    expect( covActivityTypes( $product ) )->toBe( [ 'product.created', 'variant.created', 'price.created', 'price.created' ] );

    $variantPrice = ActivityLogEntry::query()->where( 'event_type', 'price.created' )->orderByDesc( 'id' )->first();

    expect( $variantPrice->payload )->toMatchArray( [ 'variant_id' => $variant->id, 'currency' => 'EUR', 'amount' => 1_700 ] )
        ->and( covActivityTypes( $promotion ) )->toBe( [ 'promotion.created', 'coupon.created' ] )
        ->and( covLatestActivity( $promotion )->payload )->toMatchArray( [ 'code' => 'SAVE10' ] );
} );

it( 'records nothing while the activity log is off', function (): void {
    config()->set( 'artisanpack.ecommerce.activity_log.enabled', false );

    $product = Product::factory()->create();
    $product->update( [ 'name' => 'Renamed' ] );

    expect( covActivityTypes( $product ) )->toBe( [] );
} );

it( 'never blocks the write it describes when recording fails', function (): void {
    $this->mock( ActivityLogService::class, function ( $mock ): void {
        $mock->shouldReceive( 'enabled' )->andReturn( true );
        $mock->shouldReceive( 'record' )->andThrow( new RuntimeException( 'activity table gone' ) );
    } );

    $product = Product::factory()->create( [ 'name' => 'Still saved' ] );

    expect( Product::query()->whereKey( $product->id )->value( 'name' ) )->toBe( 'Still saved' );
} );
