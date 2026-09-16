<?php

declare( strict_types=1 );

use Illuminate\Support\Facades\Log;

beforeEach( function (): void {
    $this->logPath = storage_path( 'logs/ecommerce.log' );

    if ( file_exists( $this->logPath ) ) {
        unlink( $this->logPath );
    }
} );

afterEach( function (): void {
    if ( file_exists( $this->logPath ) ) {
        unlink( $this->logPath );
    }
} );

it( 'auto-registers the `ecommerce` log channel', function (): void {
    $channel = config( 'logging.channels.ecommerce' );

    expect( $channel )->toBeArray();
    expect( $channel['driver'] )->toBe( 'single' );
    expect( $channel['tap'] )->toContain( ArtisanPackUI\Ecommerce\Logging\EcommerceLogFormatter::class );
} );

it( 'emits structured JSON when Log::channel(ecommerce)->info() is called', function (): void {
    Log::channel( 'ecommerce' )->info( 'order placed', [
        'event'    => 'order.placed',
        'order_id' => 42,
    ] );

    expect( file_exists( $this->logPath ) )->toBeTrue();

    $line    = trim( file_get_contents( $this->logPath ) );
    $payload = json_decode( $line, true, flags: JSON_THROW_ON_ERROR );

    expect( $payload['message'] )->toBe( 'order placed' );
    expect( $payload['level'] )->toBe( 'info' );
    expect( $payload['event'] )->toBe( 'order.placed' );
    expect( $payload['order_id'] )->toBe( 42 );
    expect( $payload['channel'] )->toBeString();
} );

it( 'does not overwrite a pre-existing `ecommerce` channel defined by the host application', function (): void {
    config()->set( 'logging.channels.ecommerce', [
        'driver' => 'null',
    ] );

    // Re-boot to give the service provider a chance to observe the override.
    $provider = new ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider( $this->app );
    $provider->register();

    expect( config( 'logging.channels.ecommerce.driver' ) )->toBe( 'null' );
} );
