<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Reviews\ProductRatingAggregator;
use ArtisanPackUI\Ecommerce\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->product = Product::factory()->create();
} );

/**
 * A pending review of `$product` rated `$rating`.
 */
function covPendingReview( Product $product, int $rating ): ProductReview
{
    return ProductReview::factory()->create( [ 'product_id' => $product->id, 'rating' => $rating, 'status' => ProductReview::STATUS_PENDING ] );
}

it( 'starts with no rating and counts only approved reviews', function (): void {
    covPendingReview( $this->product, 5 );

    app( ProductRatingAggregator::class )->recalculate( $this->product->id );

    expect( (int) $this->product->fresh()->reviews_count )->toBe( 0 )
        ->and( (float) $this->product->fresh()->avg_rating )->toBe( 0.0 );
} );

it( 'updates the aggregate columns as reviews are approved, rejected, and deleted', function (): void {
    $reviews = app( ReviewService::class );
    $five    = covPendingReview( $this->product, 5 );
    $four    = covPendingReview( $this->product, 4 );
    $two     = covPendingReview( $this->product, 2 );

    $reviews->approve( $five );
    $reviews->approve( $four );
    $reviews->approve( $two );

    expect( (int) $this->product->fresh()->reviews_count )->toBe( 3 )
        ->and( (float) $this->product->fresh()->avg_rating )->toBe( 3.67 );

    $reviews->reject( $two, 'spam-like' );

    expect( (int) $this->product->fresh()->reviews_count )->toBe( 2 )
        ->and( (float) $this->product->fresh()->avg_rating )->toBe( 4.5 );

    $reviews->markSpam( $four );

    expect( (int) $this->product->fresh()->reviews_count )->toBe( 1 )
        ->and( (float) $this->product->fresh()->avg_rating )->toBe( 5.0 );

    $reviews->delete( $five );

    expect( (int) $this->product->fresh()->reviews_count )->toBe( 0 )
        ->and( (float) $this->product->fresh()->avg_rating )->toBe( 0.0 );
} );

it( 'leaves other products\' aggregates alone', function (): void {
    $other = Product::factory()->create();
    app( ReviewService::class )->approve( covPendingReview( $other, 1 ) );
    app( ReviewService::class )->approve( covPendingReview( $this->product, 5 ) );

    expect( (float) $this->product->fresh()->avg_rating )->toBe( 5.0 )
        ->and( (float) $other->fresh()->avg_rating )->toBe( 1.0 );
} );

it( 'recalculates from a review through handle() and ignores a missing product', function (): void {
    $review = ProductReview::factory()->create( [ 'product_id' => $this->product->id, 'rating' => 3, 'status' => ProductReview::STATUS_APPROVED ] );

    app( ProductRatingAggregator::class )->handle( $review );
    app( ProductRatingAggregator::class )->recalculate( 999_999 );

    expect( (int) $this->product->fresh()->reviews_count )->toBe( 1 )
        ->and( (float) $this->product->fresh()->avg_rating )->toBe( 3.0 );
} );

it( 'builds a five-to-one star histogram of approved reviews', function (): void {
    foreach ( [ 5, 5, 3, 1, 1, 1 ] as $rating ) {
        ProductReview::factory()->create( [ 'product_id' => $this->product->id, 'rating' => $rating, 'status' => ProductReview::STATUS_APPROVED ] );
    }

    covPendingReview( $this->product, 4 );
    ProductReview::factory()->create( [ 'product_id' => $this->product->id, 'rating' => 2, 'status' => ProductReview::STATUS_REJECTED ] );

    $histogram = app( ProductRatingAggregator::class )->histogram( $this->product );

    expect( $histogram )->toBe( [ 5 => 2, 4 => 0, 3 => 1, 2 => 0, 1 => 3 ] )
        ->and( array_keys( app( ProductRatingAggregator::class )->histogram( Product::factory()->create() ) ) )->toBe( [ 5, 4, 3, 2, 1 ] );
} );
