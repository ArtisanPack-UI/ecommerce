<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Support\MorphType;
use Illuminate\Database\Eloquent\Relations\Relation;

beforeEach( fn () => MorphType::register() );

// The morph map is static: put back the engine-only map for later tests.
afterEach( fn () => Relation::morphMap( MorphType::MAP, false ) );

it( 'maps aliases to models and back', function (): void {
    expect( ( new Product() )->getMorphClass() )->toBe( 'ecommerce.product' )
        ->and( MorphType::classOf( 'ecommerce.variant' ) )->toBe( ProductVariant::class )
        ->and( MorphType::basename( 'ecommerce.variant' ) )->toBe( 'ProductVariant' )
        ->and( MorphType::basename( null ) )->toBeNull();
} );

it( 'recognises an alias or a stored class name', function (): void {
    expect( MorphType::is( 'ecommerce.variant', ProductVariant::class ) )->toBeTrue()
        ->and( MorphType::is( ProductVariant::class, ProductVariant::class ) )->toBeTrue()
        ->and( MorphType::is( 'ecommerce.product', ProductVariant::class ) )->toBeFalse()
        ->and( MorphType::is( null, Product::class ) )->toBeFalse();
} );

it( 'keeps a host\'s own aliases', function (): void {
    Relation::morphMap( [ 'host.thing' => stdClass::class ] );
    MorphType::register();

    expect( Relation::getMorphedModel( 'host.thing' ) )->toBe( stdClass::class )
        ->and( Relation::getMorphedModel( 'ecommerce.order' ) )->not->toBeNull();
} );
