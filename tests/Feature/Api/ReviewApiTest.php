<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

const REVIEWS_API = '/api/ecommerce/v1';

it( 'lists only approved reviews of a visible product, without moderation details', function (): void {
    $product = Product::factory()->create();
    ProductReview::factory()->approved()->create( [ 'product_id' => $product->id, 'rating' => 5, 'author_email' => 'secret@example.test' ] );
    ProductReview::factory()->create( [ 'product_id' => $product->id ] );
    ProductReview::factory()->create( [ 'product_id' => $product->id, 'status' => ProductReview::STATUS_SPAM ] );

    $this->getJson( REVIEWS_API . "/products/{$product->id}/reviews" )
        ->assertOk()
        ->assertJsonCount( 1, 'data' )
        ->assertJsonPath( 'data.0.type', 'review' )
        ->assertJsonPath( 'data.0.rating', 5 )
        ->assertJsonMissingPath( 'data.0.author_email' )
        ->assertJsonMissingPath( 'data.0.status' );

    $this->getJson( REVIEWS_API . '/products/' . Product::factory()->draft()->create()->id . '/reviews' )->assertNotFound();
} );

it( 'accepts a guest review into the moderation queue', function (): void {
    $product = Product::factory()->create();

    $this->postJson( REVIEWS_API . "/products/{$product->id}/reviews", [ 'rating' => 4 ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'author_name' );

    $this->postJson( REVIEWS_API . "/products/{$product->id}/reviews", [ 'rating' => 4 ] )->assertStatus( 400 );

    $this->postJson( REVIEWS_API . "/products/{$product->id}/reviews", [
        'rating'       => 4,
        'title'        => 'Solid',
        'author_name'  => 'Grace',
        'author_email' => 'grace@example.test',
    ], idem() )->assertCreated()->assertJsonPath( 'data.title', 'Solid' )->assertJsonMissingPath( 'data.status' );

    expect( ProductReview::query()->sole()->status )->toBe( ProductReview::STATUS_PENDING );
} );

it( 'refuses guests when guest reviews are off', function (): void {
    config()->set( 'artisanpack.ecommerce.reviews.allow_guests', false );
    $product = Product::factory()->create();

    $this->postJson( REVIEWS_API . "/products/{$product->id}/reviews", [ 'rating' => 4, 'author_name' => 'G', 'author_email' => 'g@example.test' ], idem() )
        ->assertUnauthorized();
} );

it( 'verifies a signed-in customer citing their paid order', function (): void {
    $product  = Product::factory()->create();
    $customer = Customer::factory()->forUser( 2 )->create();
    $order    = Order::factory()->forCustomer( $customer )->create( [ 'payment_status' => 'paid' ] );
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => $product->id ] );

    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->postJson( REVIEWS_API . "/products/{$product->id}/reviews", [ 'rating' => 5, 'order_id' => $order->id ], idem() )
        ->assertCreated()
        ->assertJsonPath( 'data.is_verified_purchase', true );

    expect( ProductReview::query()->sole()->customer_id )->toBe( $customer->id );
} );

it( 'answers honeypot submissions like real ones but files them as spam', function (): void {
    $product = Product::factory()->create();

    $this->postJson( REVIEWS_API . "/products/{$product->id}/reviews", [
        'rating'       => 5,
        'author_name'  => 'Bot',
        'author_email' => 'bot@example.test',
        'website'      => 'https://spam.example',
    ], idem() )->assertCreated()->assertJsonMissingPath( 'data.status' );

    expect( ProductReview::query()->sole()->status )->toBe( ProductReview::STATUS_SPAM );

    // An array in the honeypot field is still a filled-in honeypot.
    $this->postJson( REVIEWS_API . "/products/{$product->id}/reviews", [
        'rating'       => 5,
        'author_name'  => 'Bot',
        'author_email' => 'bot@example.test',
        'website'      => [ 'x' ],
    ], idem() )->assertCreated();

    expect( ProductReview::query()->where( 'status', ProductReview::STATUS_SPAM )->count() )->toBe( 2 );
} );

it( 'rate-limits review submissions per customer', function (): void {
    Customer::factory()->forUser( 2 )->create();
    $this->actingAs( ecommerceShopper(), 'sanctum' );

    // Different products: one customer reviews each product once (#181).
    foreach ( range( 1, 3 ) as $attempt ) {
        $this->postJson( REVIEWS_API . '/products/' . Product::factory()->create()->id . '/reviews', [ 'rating' => 5 ], idem() )->assertCreated();
    }

    $this->postJson( REVIEWS_API . '/products/' . Product::factory()->create()->id . '/reviews', [ 'rating' => 5 ], idem() )
        ->assertStatus( 429 )
        ->assertHeader( 'Retry-After' );
} );

it( 'rate-limits review submissions per IP across accounts', function (): void {
    $product = Product::factory()->create();

    foreach ( range( 10, 19 ) as $id ) {
        Customer::factory()->forUser( $id )->create();
        $this->actingAs( new GenericUser( [ 'id' => $id ] ), 'sanctum' )
            ->postJson( REVIEWS_API . "/products/{$product->id}/reviews", [ 'rating' => 5 ], idem() )
            ->assertCreated();
    }

    Customer::factory()->forUser( 20 )->create();
    $this->actingAs( new GenericUser( [ 'id' => 20 ] ), 'sanctum' )
        ->postJson( REVIEWS_API . "/products/{$product->id}/reviews", [ 'rating' => 5 ], idem() )
        ->assertStatus( 429 );
} );

it( 'moderates reviews through the admin queue and updates the product rating', function (): void {
    $product = Product::factory()->create();
    $review  = ProductReview::factory()->create( [ 'product_id' => $product->id, 'rating' => 4 ] );
    ProductReview::factory()->approved()->create( [ 'rating' => 1 ] );

    $this->getJson( REVIEWS_API . '/admin/reviews' )->assertUnauthorized();
    $this->actingAs( ecommerceShopper(), 'sanctum' )->getJson( REVIEWS_API . '/admin/reviews' )->assertForbidden();

    $this->actingAs( ecommerceAdmin(), 'sanctum' );

    $this->getJson( REVIEWS_API . '/admin/reviews?filter[status]=pending' )
        ->assertOk()
        ->assertJsonCount( 1, 'data' )
        ->assertJsonPath( 'data.0.status', 'pending' )
        ->assertJsonPath( 'data.0.author_email', $review->author_email );

    $this->postJson( REVIEWS_API . "/admin/reviews/{$review->id}/moderate", [ 'action' => 'publish' ], idem() )->assertStatus( 422 );

    $this->postJson( REVIEWS_API . "/admin/reviews/{$review->id}/moderate", [ 'action' => 'approve' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.status', 'approved' )
        ->assertJsonPath( 'data.reviewed_by_user_id', 1 );

    expect( $product->fresh()->reviews_count )->toBe( 1 )->and( $product->fresh()->avg_rating )->toBe( 4.0 );

    $this->postJson( REVIEWS_API . "/admin/reviews/{$review->id}/moderate", [ 'action' => 'reject', 'reason' => 'Off-topic' ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.status', 'rejected' );

    expect( $product->fresh()->reviews_count )->toBe( 0 );

    $this->deleteJson( REVIEWS_API . "/admin/reviews/{$review->id}", [], idem() )->assertOk();
    expect( ProductReview::query()->whereKey( $review->id )->exists() )->toBeFalse();
} );

it( 'lets a moderator without delete rights moderate but not delete', function (): void {
    Gate::define( 'ecommerce.review.moderate', fn (): bool => true );
    $review = ProductReview::factory()->create();

    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->postJson( REVIEWS_API . "/admin/reviews/{$review->id}/moderate", [ 'action' => 'spam' ], idem() )
        ->assertOk();

    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->deleteJson( REVIEWS_API . "/admin/reviews/{$review->id}", [], idem() )
        ->assertForbidden();
} );
