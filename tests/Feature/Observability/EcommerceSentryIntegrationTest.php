<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Exceptions\EcommerceException;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Providers\EcommerceSentryIntegration;

it( 'does not register the integration when sentry-laravel is not installed', function (): void {
    expect( EcommerceSentryIntegration::sentryInstalled() )->toBeFalse()
        ->and( app()->getProvider( EcommerceSentryIntegration::class ) )->toBeNull();
} );

it( 'returns null context for exceptions outside the engine', function (): void {
    expect( EcommerceSentryIntegration::contextFor( new RuntimeException( 'boom' ) ) )->toBeNull()
        ->and( EcommerceSentryIntegration::contextFor( null ) )->toBeNull();
} );

it( 'returns the engine exception context tagged with the exception class', function (): void {
    $exception = new EcommerceException( 'Order failed', [ 'order_id' => 42, 'cart_id' => 'abc' ] );

    expect( EcommerceSentryIntegration::contextFor( $exception ) )->toBe( [
        'exception' => EcommerceException::class,
        'order_id'  => 42,
        'cart_id'   => 'abc',
    ] );
} );

it( 'finds an engine exception wrapped in a previous chain', function (): void {
    $inner   = new EcommerceException( 'inner', [ 'order_id' => 7 ] );
    $wrapper = new RuntimeException( 'outer', 0, new LogicException( 'middle', 0, $inner ) );

    expect( EcommerceSentryIntegration::contextFor( $wrapper ) )
        ->toMatchArray( [ 'exception' => EcommerceException::class, 'order_id' => 7 ] );
} );

it( 'reports the concrete subclass for subclassed engine exceptions', function (): void {
    $exception = new CartOperationException( 'product_id', 'unknown-product', 'Unknown product.' );

    expect( EcommerceSentryIntegration::contextFor( $exception )['exception'] )->toBe( CartOperationException::class );
} );

it( 'normalizes non-scalar context values without leaking model attributes', function (): void {
    $product = Product::factory()->make( [ 'name' => 'Secret name' ] );
    $product->setAttribute( 'id', 9 );

    $exception = new EcommerceException( 'x', [
        'product' => $product,
        'at'      => new DateTimeImmutable( '2026-01-02T03:04:05+00:00' ),
        'nested'  => [ 'object' => new stdClass(), 'flag' => true ],
        'closure' => static fn (): null => null,
    ] );

    $context = EcommerceSentryIntegration::contextFor( $exception );

    expect( $context['product'] )->toBe( Product::class . '#9' )
        ->and( $context['at'] )->toBe( '2026-01-02T03:04:05+00:00' )
        ->and( $context['nested'] )->toBe( [ 'object' => stdClass::class, 'flag' => true ] )
        ->and( $context['closure'] )->toBe( Closure::class );
} );
