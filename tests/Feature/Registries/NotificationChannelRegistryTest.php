<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Registries\NotificationChannelRegistry;

it( 'surfaces the core mail and database channels', function (): void {
    $channels = app( NotificationChannelRegistry::class );

    expect( $channels->keys() )->toBe( [ 'mail', 'database' ] )
        ->and( $channels->get( 'mail' ) )->toBe( [ 'key' => 'mail', 'label' => 'Email', 'driver' => 'mail', 'provided_by' => 'ecommerce' ] )
        ->and( $channels->get( 'database' )['driver'] )->toBe( 'database' );
} );

it( 'lets a satellite add a channel', function (): void {
    $channels = app( NotificationChannelRegistry::class );
    $channels->register( 'sms', 'App\\Channels\\SmsChannel', [ 'label' => fn (): string => 'Text message', 'provided_by' => 'acme/sms' ] );

    expect( $channels->has( 'sms' ) )->toBeTrue()
        ->and( array_keys( $channels->all() ) )->toBe( [ 'mail', 'database', 'sms' ] )
        ->and( $channels->get( 'sms' ) )->toBe( [ 'key' => 'sms', 'label' => 'Text message', 'driver' => 'App\\Channels\\SmsChannel', 'provided_by' => 'acme/sms' ] );
} );

it( 'defaults the driver to the key and refuses duplicates', function (): void {
    $channels = app( NotificationChannelRegistry::class );
    $channels->register( 'slack' );

    expect( $channels->get( 'slack' )['driver'] )->toBe( 'slack' )
        ->and( fn () => $channels->register( 'mail' ) )->toThrow( InvalidArgumentException::class )
        ->and( fn () => $channels->get( 'pigeon' ) )->toThrow( RuntimeException::class );
} );
