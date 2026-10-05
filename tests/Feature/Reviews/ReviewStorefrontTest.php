<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\ReviewNotAllowedException;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Reviews\ProductRatingAggregator;
use ArtisanPackUI\Ecommerce\Reviews\ReviewEligibility;
use ArtisanPackUI\Ecommerce\Reviews\ReviewMediaStore;
use ArtisanPackUI\Ecommerce\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

require_once __DIR__ . '/../Api/ApiTestHelpers.php';

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->product  = Product::factory()->create();
    $this->customer = Customer::factory()->forUser( 2 )->create();
} );

function reviewsService(): ReviewService
{
    return app( ReviewService::class );
}

/**
 * A paid order of `$customer` for `$product`.
 */
function paidPurchase( Customer $customer, Product $product, string $paymentStatus = 'paid' ): Order
{
    $order = Order::factory()->forCustomer( $customer )->create( [ 'payment_status' => $paymentStatus ] );
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => $product->id ] );

    return $order;
}

it( 'marks a buyer\'s review verified without an order id', function (): void {
    $order = paidPurchase( $this->customer, $this->product );

    expect( reviewsService()->eligibility( $this->product, $this->customer )->toArray() )->toBe( [ 'allowed' => true, 'reason' => null, 'verified_purchase' => true ] );

    $review = reviewsService()->submit( $this->product, [ 'rating' => 5 ], $this->customer );

    expect( $review->is_verified_purchase )->toBeTrue()
        ->and( $review->order_id )->toBe( $order->id );
} );

it( 'does not verify an unpaid or someone else\'s purchase', function (): void {
    paidPurchase( $this->customer, $this->product, 'pending' );
    paidPurchase( Customer::factory()->create(), $this->product );

    expect( reviewsService()->submit( $this->product, [ 'rating' => 4 ], $this->customer )->is_verified_purchase )->toBeFalse();
} );

it( 'allows one live review per customer unless configured otherwise', function (): void {
    $first = reviewsService()->submit( $this->product, [ 'rating' => 5 ], $this->customer );

    expect( reviewsService()->eligibility( $this->product, $this->customer )->reason )->toBe( ReviewEligibility::ALREADY_REVIEWED )
        ->and( fn () => reviewsService()->submit( $this->product, [ 'rating' => 1 ], $this->customer ) )->toThrow( ReviewNotAllowedException::class );

    // A rejected review doesn't block a new one.
    reviewsService()->reject( $first, 'off-topic' );
    expect( reviewsService()->eligibility( $this->product, $this->customer )->allowed )->toBeTrue();

    config()->set( 'artisanpack.ecommerce.reviews.allow_multiple', true );
    reviewsService()->submit( $this->product, [ 'rating' => 3 ], $this->customer );
    expect( reviewsService()->eligibility( $this->product, $this->customer )->allowed )->toBeTrue();
} );

it( 'requires a purchase, or a signed-in shopper, when configured', function (): void {
    config()->set( 'artisanpack.ecommerce.reviews.require_purchase', true );

    expect( reviewsService()->eligibility( $this->product, $this->customer )->reason )->toBe( ReviewEligibility::PURCHASE_REQUIRED )
        ->and( reviewsService()->eligibility( $this->product, null )->reason )->toBe( ReviewEligibility::PURCHASE_REQUIRED );

    paidPurchase( $this->customer, $this->product, 'partially_refunded' );
    expect( reviewsService()->eligibility( $this->product, $this->customer )->allowed )->toBeTrue();

    config()->set( 'artisanpack.ecommerce.reviews.allow_guests', false );
    expect( reviewsService()->eligibility( $this->product, null )->reason )->toBe( ReviewEligibility::GUESTS_NOT_ALLOWED );
} );

it( 'counts approved reviews per star', function (): void {
    foreach ( [ 5, 5, 4, 1 ] as $rating ) {
        ProductReview::factory()->create( [ 'product_id' => $this->product->id, 'rating' => $rating, 'status' => ProductReview::STATUS_APPROVED ] );
    }

    ProductReview::factory()->create( [ 'product_id' => $this->product->id, 'rating' => 3, 'status' => ProductReview::STATUS_PENDING ] );

    expect( app( ProductRatingAggregator::class )->histogram( $this->product ) )->toBe( [ 5 => 2, 4 => 1, 3 => 0, 2 => 0, 1 => 1 ] );

    $this->getJson( "/api/ecommerce/v1/products/{$this->product->id}/reviews" )
        ->assertOk()
        ->assertJsonPath( 'meta.histogram.5', 2 )
        ->assertJsonPath( 'meta.histogram.3', 0 );
} );

it( 'answers eligibility and refuses ineligible reviews over REST', function (): void {
    paidPurchase( $this->customer, $this->product );
    $this->actingAs( ecommerceShopper(), 'sanctum' );

    $this->getJson( "/api/ecommerce/v1/products/{$this->product->id}/reviews/eligibility" )
        ->assertOk()
        ->assertJsonPath( 'data', [ 'allowed' => true, 'reason' => null, 'verified_purchase' => true ] );

    $this->postJson( "/api/ecommerce/v1/products/{$this->product->id}/reviews", [ 'rating' => 5 ], idem() )->assertCreated()->assertJsonPath( 'data.is_verified_purchase', true );
    $this->postJson( "/api/ecommerce/v1/products/{$this->product->id}/reviews", [ 'rating' => 5 ], idem() )
        ->assertForbidden()
        ->assertJsonPath( 'type', fn ( string $type ): bool => str_ends_with( $type, 'already-reviewed' ) );
} );

it( 'stores review photos through the media store, and refuses them without one', function (): void {
    $this->actingAs( ecommerceShopper(), 'sanctum' );
    $photo = UploadedFile::fake()->image( 'mug.jpg' );

    $this->post( "/api/ecommerce/v1/products/{$this->product->id}/reviews", [ 'rating' => 5, 'media' => [ $photo ] ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'media' );

    app()->instance( ReviewMediaStore::class, new class extends ReviewMediaStore {
        public function available(): bool
        {
            return true;
        }

        public function store( UploadedFile $file ): int
        {
            return 4_242;
        }
    } );

    $this->post( "/api/ecommerce/v1/products/{$this->product->id}/reviews", [ 'rating' => 5, 'media' => [ $photo ] ], idem() )
        ->assertCreated()
        ->assertJsonPath( 'data.media_ids', [ 4_242 ] );
} );
