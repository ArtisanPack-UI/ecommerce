<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerNote;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Services\CustomerNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

it( 'lists a product\'s activity newest first', function (): void {
    $product = Product::factory()->create( [ 'name' => 'Mug' ] );
    $product->update( [ 'name' => 'Big mug' ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( "/api/ecommerce/v1/admin/activity/products/{$product->id}" )
        ->assertOk()
        ->assertJsonCount( 2, 'data' )
        ->assertJsonPath( 'data.0.type', 'activityLogEntry' )
        ->assertJsonPath( 'data.0.event_type', 'product.updated' )
        ->assertJsonPath( 'data.0.payload.changes.name.after', 'Big mug' )
        ->assertJsonPath( 'data.1.event_type', 'product.created' );
} );

it( 'filters activity by event type', function (): void {
    $promotion = Promotion::factory()->create();
    $promotion->update( [ 'name' => 'Renamed' ] );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( "/api/ecommerce/v1/admin/activity/promotions/{$promotion->id}?filter[event_type]=promotion.created" )
        ->assertOk()
        ->assertJsonCount( 1, 'data' )
        ->assertJsonPath( 'data.0.event_type', 'promotion.created' );
} );

it( 'requires the subject\'s view ability for activity', function (): void {
    Gate::define( 'ecommerce.admin', fn (): bool => true );
    Gate::define( 'ecommerce.customer.view', fn (): bool => false );

    $customer = Customer::factory()->create();

    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->getJson( "/api/ecommerce/v1/admin/activity/customers/{$customer->id}" )
        ->assertForbidden();
} );

it( 'adds, lists, and deletes customer notes', function (): void {
    $customer = Customer::factory()->create();

    $id = $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/customers/{$customer->id}/notes", [ 'body' => 'Prefers phone contact' ], idem() )
        ->assertCreated()
        ->assertJsonPath( 'data.type', 'customerNote' )
        ->assertJsonPath( 'data.body', 'Prefers phone contact' )
        ->assertJsonPath( 'data.author_user_id', 1 )
        ->json( 'data.id' );

    $this->getJson( "/api/ecommerce/v1/customers/{$customer->id}/notes" )
        ->assertOk()
        ->assertJsonCount( 1, 'data' );

    $this->deleteJson( "/api/ecommerce/v1/customers/{$customer->id}/notes/{$id}", [], idem() )
        ->assertOk();

    expect( CustomerNote::query()->count() )->toBe( 0 );
} );

it( 'validates the note body', function (): void {
    $customer = Customer::factory()->create();

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/customers/{$customer->id}/notes", [ 'body' => '' ], idem() )
        ->assertUnprocessable();
} );

it( 'scopes note deletion to the customer', function (): void {
    $customer = Customer::factory()->create();
    $note     = app( CustomerNoteService::class )->add( Customer::factory()->create(), 'Not yours' );

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->deleteJson( "/api/ecommerce/v1/customers/{$customer->id}/notes/{$note->id}", [], idem() )
        ->assertNotFound();

    expect( CustomerNote::query()->count() )->toBe( 1 );
} );

it( 'requires customer.update to add a note', function (): void {
    Gate::define( 'ecommerce.admin', fn (): bool => true );
    Gate::define( 'ecommerce.customer.update', fn (): bool => false );

    $customer = Customer::factory()->create();

    $this->actingAs( ecommerceShopper(), 'sanctum' )
        ->postJson( "/api/ecommerce/v1/customers/{$customer->id}/notes", [ 'body' => 'x' ], idem() )
        ->assertForbidden();
} );
