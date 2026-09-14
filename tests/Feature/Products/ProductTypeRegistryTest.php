<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\ProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\DigitalProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\MissingProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;

it( 'boots with simple and digital registered', function (): void {
    $registry = $this->app->make( ProductTypeRegistry::class );

    expect( $registry->has( 'simple' ) )->toBeTrue();
    expect( $registry->has( 'digital' ) )->toBeTrue();

    expect( $registry->get( 'simple' ) )->toBeInstanceOf( SimpleProductType::class );
    expect( $registry->get( 'digital' ) )->toBeInstanceOf( DigitalProductType::class );
} );

it( 'resolves an unknown key to MissingProductType carrying the key', function (): void {
    $registry = $this->app->make( ProductTypeRegistry::class );

    $type = $registry->get( 'nope-not-real' );

    expect( $type )->toBeInstanceOf( MissingProductType::class );
    expect( $type->key() )->toBe( 'nope-not-real' );
    expect( $type->isMissing() )->toBeTrue();
} );

it( 'registers a fresh key with a class name and lazily resolves it', function (): void {
    $registry = $this->app->make( ProductTypeRegistry::class );

    $stub = new class extends SimpleProductType {
        public function key(): string
        {
            return 'stub';
        }

        public function label(): string
        {
            return 'Stub';
        }
    };

    $registry->register( 'stub', $stub::class, [ 'label' => 'Stub' ] );

    expect( $registry->has( 'stub' ) )->toBeTrue();
    expect( $registry->get( 'stub' ) )->toBeInstanceOf( ProductType::class );
    expect( $registry->meta( 'stub' ) )->toBe( [ 'label' => 'Stub' ] );

    $registry->forget( 'stub' );
    expect( $registry->has( 'stub' ) )->toBeFalse();
} );

it( 'accepts a pre-built instance and returns it verbatim', function (): void {
    $registry = $this->app->make( ProductTypeRegistry::class );

    $instance = new SimpleProductType();
    $registry->register( 'preset', $instance );

    expect( $registry->get( 'preset' ) )->toBe( $instance );
} );

it( 'throws on duplicate registration in the testing environment', function (): void {
    $registry = $this->app->make( ProductTypeRegistry::class );

    expect( fn () => $registry->register( 'simple', SimpleProductType::class ) )
        ->toThrow( InvalidArgumentException::class, 'already registered' );
} );

it( 'rejects an empty key', function (): void {
    $registry = $this->app->make( ProductTypeRegistry::class );

    expect( fn () => $registry->register( '   ', SimpleProductType::class ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'rejects a class that does not implement ProductType', function (): void {
    $registry = $this->app->make( ProductTypeRegistry::class );

    expect( fn () => $registry->register( 'bogus', stdClass::class ) )
        ->toThrow( InvalidArgumentException::class );
} );

it( 'lists all registered types and their keys', function (): void {
    $registry = $this->app->make( ProductTypeRegistry::class );

    expect( $registry->keys() )->toContain( 'simple', 'digital' );
    expect( $registry->all() )->toHaveKeys( [ 'simple', 'digital' ] );
} );
