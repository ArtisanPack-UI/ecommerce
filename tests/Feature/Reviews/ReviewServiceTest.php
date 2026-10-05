<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\ReviewModerator;
use ArtisanPackUI\Ecommerce\Events\ReviewApproved;
use ArtisanPackUI\Ecommerce\Events\ReviewSubmitted;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses( RefreshDatabase::class );

/**
 * A paid order by `$customer` containing `$product`.
 */
function paidOrderFor( Customer $customer, Product $product, string $paymentStatus = 'paid' ): Order
{
    $order = Order::factory()->forCustomer( $customer )->create( [ 'payment_status' => $paymentStatus ] );
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => $product->id ] );

    return $order;
}

function reviews(): ReviewService
{
    return app( ReviewService::class );
}

it( 'files a new review as pending and leaves the rating alone', function (): void {
    Event::fake( [ ReviewSubmitted::class ] );
    $product   = Product::factory()->create();
    $submitted = [];
    addAction( 'ap.ecommerce.review.submitted', function ( ProductReview $review ) use ( &$submitted ): void {
        $submitted[] = $review->id;
    } );

    $review = reviews()->submit( $product, [ 'rating' => 4, 'title' => 'Nice', 'author_name' => 'Ada', 'author_email' => 'ada@example.test' ] );

    expect( $review->status )->toBe( ProductReview::STATUS_PENDING )
        ->and( $review->is_verified_purchase )->toBeFalse()
        ->and( $submitted )->toBe( [ $review->id ] )
        ->and( $product->fresh()->reviews_count )->toBe( 0 )
        ->and( $product->fresh()->avg_rating )->toBe( 0.0 );

    Event::assertDispatched( ReviewSubmitted::class, fn ( ReviewSubmitted $event ): bool => $event->review->is( $review ) );
} );

it( 'recalculates the denormalized rating on approval, rejection, spam, requeue, and delete', function (): void {
    Event::fake( [ ReviewApproved::class ] );
    $product = Product::factory()->create();
    $five    = ProductReview::factory()->create( [ 'product_id' => $product->id, 'rating' => 5 ] );
    $four    = ProductReview::factory()->create( [ 'product_id' => $product->id, 'rating' => 4 ] );
    $two     = ProductReview::factory()->create( [ 'product_id' => $product->id, 'rating' => 2 ] );

    reviews()->approve( $five, 1 );
    reviews()->approve( $four, 1 );
    reviews()->approve( $two, 1 );

    expect( $product->fresh()->reviews_count )->toBe( 3 )
        ->and( $product->fresh()->avg_rating )->toBe( 3.67 )
        ->and( $five->fresh()->reviewed_by_user_id )->toBe( 1 );

    Event::assertDispatchedTimes( ReviewApproved::class, 3 );

    reviews()->reject( $two, 'Off topic' );
    expect( $product->fresh()->reviews_count )->toBe( 2 )->and( $product->fresh()->avg_rating )->toBe( 4.5 );

    reviews()->markSpam( $four );
    expect( $product->fresh()->reviews_count )->toBe( 1 )->and( $product->fresh()->avg_rating )->toBe( 5.0 );

    reviews()->requeue( $five );
    expect( $product->fresh()->reviews_count )->toBe( 0 )->and( $product->fresh()->avg_rating )->toBe( 0.0 );

    reviews()->approve( $five );
    reviews()->delete( $five );
    expect( $product->fresh()->reviews_count )->toBe( 0 );
} );

it( 'only counts approved reviews of the same product', function (): void {
    $product = Product::factory()->create();
    $other   = Product::factory()->create();
    ProductReview::factory()->approved()->create( [ 'product_id' => $other->id, 'rating' => 1 ] );
    $review = ProductReview::factory()->create( [ 'product_id' => $product->id, 'rating' => 5 ] );
    ProductReview::factory()->create( [ 'product_id' => $product->id, 'rating' => 1 ] );

    reviews()->approve( $review );

    expect( $product->fresh()->avg_rating )->toBe( 5.0 )->and( $product->fresh()->reviews_count )->toBe( 1 );
} );

it( 'marks reviews backed by a paid order for the product as verified purchases', function (): void {
    $product  = Product::factory()->create();
    $customer = Customer::factory()->create();
    $order    = paidOrderFor( $customer, $product );

    $review = reviews()->submit( $product, [ 'rating' => 5, 'order_id' => $order->id ], $customer );

    expect( $review->is_verified_purchase )->toBeTrue()
        ->and( $review->order_id )->toBe( $order->id )
        ->and( $review->customer_id )->toBe( $customer->id )
        ->and( $review->author_name )->toBe( trim( $customer->first_name . ' ' . $customer->last_name ) )
        ->and( $review->author_email )->toBe( $customer->email );
} );

it( 'drops an order that does not prove the purchase', function ( Closure $order ): void {
    $product  = Product::factory()->create();
    $customer = Customer::factory()->create();

    $review = reviews()->submit( $product, [ 'rating' => 5, 'order_id' => $order( $customer, $product )->id ], $customer );

    expect( $review->is_verified_purchase )->toBeFalse()->and( $review->order_id )->toBeNull();
} )->with( [
    "another customer's order"  => [ fn ( Customer $customer, Product $product ): Order => paidOrderFor( Customer::factory()->create(), $product ) ],
    'an unpaid order'           => [ fn ( Customer $customer, Product $product ): Order => paidOrderFor( $customer, $product, 'pending' ) ],
    'an order without the item' => [ fn ( Customer $customer, Product $product ): Order => paidOrderFor( $customer, Product::factory()->create() ) ],
] );

it( 'never verifies a guest, whatever order it cites', function (): void {
    $product = Product::factory()->create();
    $order   = paidOrderFor( Customer::factory()->create(), $product );

    $review = reviews()->submit( $product, [ 'rating' => 5, 'order_id' => $order->id, 'author_name' => 'Guest' ] );

    expect( $review->is_verified_purchase )->toBeFalse()->and( $review->order_id )->toBeNull();
} );

it( 'lets the submitting filter rewrite or abort a review', function (): void {
    $product = Product::factory()->create();

    addFilter( 'ap.ecommerce.review.submitting', fn ( array $attributes ): ?array => str_contains( (string) $attributes['body'], 'buy now' ) ? null : [ ...$attributes, 'title' => 'Filtered' ] );

    expect( reviews()->submit( $product, [ 'rating' => 1, 'body' => 'buy now!!' ] ) )->toBeNull()
        ->and( ProductReview::query()->count() )->toBe( 0 )
        ->and( reviews()->submit( $product, [ 'rating' => 3, 'body' => 'fine' ] )->title )->toBe( 'Filtered' );
} );

it( 'applies the bound moderator verdict', function ( string $verdict, string $status, int $count ): void {
    app()->instance( ReviewModerator::class, new class( $verdict ) implements ReviewModerator {
        public function __construct( private string $verdict )
        {
        }

        public function key(): string
        {
            return 'test';
        }

        public function moderate( ProductReview $review ): string
        {
            return $this->verdict;
        }
    } );

    $product = Product::factory()->create();
    $review  = reviews()->submit( $product, [ 'rating' => 4 ] );

    expect( $review->status )->toBe( $status )->and( $product->fresh()->reviews_count )->toBe( $count );
} )->with( [
    'approve' => [ 'approve', ProductReview::STATUS_APPROVED, 1 ],
    'reject'  => [ 'reject', ProductReview::STATUS_REJECTED, 0 ],
    'spam'    => [ 'spam', ProductReview::STATUS_SPAM, 0 ],
    'pending' => [ 'pending', ProductReview::STATUS_PENDING, 0 ],
] );

it( 'files honeypot submissions straight to spam without asking the moderator', function (): void {
    Event::fake( [ ReviewSubmitted::class ] );
    $submitted = 0;
    addAction( 'ap.ecommerce.review.submitted', function () use ( &$submitted ): void {
        $submitted++;
    } );
    $spam = [];
    addAction( 'ap.ecommerce.review.markedSpam', function ( ProductReview $review ) use ( &$spam ): void {
        $spam[] = $review->id;
    } );

    $review = reviews()->submit( Product::factory()->create(), [ 'rating' => 5 ], null, true );

    expect( $review->status )->toBe( ProductReview::STATUS_SPAM )->and( $spam )->toBe( [ $review->id ] )->and( $submitted )->toBe( 0 );

    Event::assertNotDispatched( ReviewSubmitted::class );
} );

it( 'rejects unknown moderation actions', function (): void {
    reviews()->moderate( ProductReview::factory()->create(), 'publish' );
} )->throws( InvalidArgumentException::class );
