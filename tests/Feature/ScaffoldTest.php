<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Ecommerce;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use Illuminate\Support\ServiceProvider;

it( 'boots the service provider cleanly', function (): void {
    expect( $this->app->getProvider( EcommerceServiceProvider::class ) )
        ->not->toBeNull();
} );

it( 'binds the ecommerce singleton', function (): void {
    expect( $this->app->make( 'ecommerce' ) )->toBeInstanceOf( Ecommerce::class );
    expect( ecommerce() )->toBeInstanceOf( Ecommerce::class );
} );

it( 'merges the package config under artisanpack.ecommerce', function (): void {
    expect( config( 'artisanpack.ecommerce.base_currency' ) )->toBe( 'USD' );
    expect( config( 'artisanpack.ecommerce.features.rest' ) )->toBeTrue();
} );

it( 'publishes the config under the ecommerce-config tag', function (): void {
    $paths = ServiceProvider::pathsToPublish( EcommerceServiceProvider::class, 'ecommerce-config' );

    expect( $paths )->not->toBeEmpty();
    expect( array_values( $paths )[ 0 ] )->toBe( config_path( 'artisanpack/ecommerce.php' ) );
} );
