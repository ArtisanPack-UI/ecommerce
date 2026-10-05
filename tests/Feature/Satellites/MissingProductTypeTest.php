<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\ProductTypes\MissingProductType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/../Api/ApiTestHelpers.php';

uses( RefreshDatabase::class );

function orphanedSubscriptionProduct(): Product
{
    $product = Product::factory()->create( [ 'type' => 'subscription' ] );
    ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => 1_000 ] );

    return $product;
}

it( 'flags a product whose type is missing as read-only', function (): void {
    $orphan = orphanedSubscriptionProduct();
    $simple = Product::factory()->create();

    expect( $orphan->typeIsMissing() )->toBeTrue()
        ->and( $orphan->isEditable() )->toBeFalse()
        ->and( $orphan->typeWarning() )->toContain( '"subscription"' )
        ->and( $simple->typeIsMissing() )->toBeFalse()
        ->and( $simple->isEditable() )->toBeTrue()
        ->and( $simple->typeWarning() )->toBeNull();
} );

it( 'keeps missing-type products in the catalog with a warning', function (): void {
    $orphan = orphanedSubscriptionProduct();

    $this->getJson( '/api/ecommerce/v1/products' )
        ->assertOk()
        ->assertJsonPath( 'data.0.id', $orphan->id )
        ->assertJsonPath( 'data.0.type_missing', true );

    $this->getJson( "/api/ecommerce/v1/products/{$orphan->id}" )
        ->assertOk()
        ->assertJsonPath( 'data.product_type', 'subscription' )
        ->assertJsonPath( 'data.type_missing', true )
        ->assertJsonMissingPath( 'data.type_warning' );

    // Admin surfaces (requests EnsureEcommerceAbility marked as admin) get
    // the explanatory warning; the public storefront never does.
    $adminRequest = Illuminate\Http\Request::create( '/' );
    $adminRequest->attributes->set( ArtisanPackUI\Ecommerce\Http\Middleware\EnsureEcommerceAbility::ADMIN_ATTRIBUTE, true );

    expect( ( new ArtisanPackUI\Ecommerce\Http\Resources\ProductResource( $orphan ) )->resolve( $adminRequest )['type_warning'] )
        ->toBe( $orphan->typeWarning() );
} );

it( 'denies updating a missing-type product even to admins', function (): void {
    $admin = ecommerceAdmin();

    expect( Gate::forUser( $admin )->allows( 'update', orphanedSubscriptionProduct() ) )->toBeFalse()
        ->and( Gate::forUser( $admin )->allows( 'update', Product::factory()->create() ) )->toBeTrue()
        ->and( Gate::forUser( $admin )->allows( 'view', orphanedSubscriptionProduct() ) )->toBeTrue();
} );

it( 'rejects adding a missing-type product to a cart as a user error', function (): void {
    $orphan = orphanedSubscriptionProduct();
    $token  = $this->postJson( '/api/ecommerce/v1/carts', [ 'currency' => 'usd' ], idem() )->assertCreated()->json( 'data.token' );

    $this->postJson( "/api/ecommerce/v1/carts/{$token}/items", [ 'product_id' => $orphan->id, 'quantity' => 1 ], idem() )
        ->assertStatus( 422 )
        ->assertHeader( 'Content-Type', 'application/problem+json' )
        ->assertJsonPath( 'errors.0.code', 'product-type-missing' );
} );

it( 'throws a cart error with context from the placeholder type', function (): void {
    $product = Product::factory()->create();

    try {
        ( new MissingProductType( 'gone' ) )->validateCartOptions( $product, [] );
        $this->fail( 'Expected a CartOperationException.' );
    } catch ( CartOperationException $e ) {
        expect( $e->errorCode )->toBe( 'product-type-missing' )
            ->and( $e->context() )->toMatchArray( [ 'product_type' => 'gone', 'product_id' => $product->id ] );
    }
} );
