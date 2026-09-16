<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\CartCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Exceptions\ClaimRateLimitedException;
use ArtisanPackUI\Ecommerce\Exceptions\ClaimVerificationFailedException;
use ArtisanPackUI\Ecommerce\Exceptions\EcommerceException;
use ArtisanPackUI\Ecommerce\Exceptions\IncompatibleBoardSubstatusException;
use ArtisanPackUI\Ecommerce\Exceptions\InsufficientStockException;
use ArtisanPackUI\Ecommerce\Exceptions\InvalidOrderStatusTransitionException;
use ArtisanPackUI\Ecommerce\Exceptions\OrderNotEditableException;
use ArtisanPackUI\Ecommerce\Exceptions\RefundNotAllowedException;

it( 'defaults context() to an empty array', function (): void {
    $exception = new EcommerceException( 'boom' );

    expect( $exception->getMessage() )->toBe( 'boom' );
    expect( $exception->context() )->toBe( [] );
} );

it( 'stores the context passed to the constructor', function (): void {
    $exception = new EcommerceException( 'boom', [ 'order_id' => 42, 'reason' => 'x' ] );

    expect( $exception->context() )->toBe( [ 'order_id' => 42, 'reason' => 'x' ] );
} );

it( 'merges additional values into the context via withContext()', function (): void {
    $exception = ( new EcommerceException( 'boom', [ 'order_id' => 1 ] ) )
        ->withContext( [ 'actor_scope' => 'admin' ] );

    expect( $exception->context() )->toBe( [ 'order_id' => 1, 'actor_scope' => 'admin' ] );
} );

it( 'lets withContext() overwrite a previously set key', function (): void {
    $exception = ( new EcommerceException( 'boom', [ 'order_id' => 1 ] ) )
        ->withContext( [ 'order_id' => 2 ] );

    expect( $exception->context() )->toBe( [ 'order_id' => 2 ] );
} );

it( 'preserves the previous exception passed to the constructor', function (): void {
    $previous  = new RuntimeException( 'root cause' );
    $exception = new EcommerceException( 'boom', [], 0, $previous );

    expect( $exception->getPrevious() )->toBe( $previous );
} );

it( 'is thrown as a RuntimeException so existing catch blocks still fire', function (): void {
    $exception = new EcommerceException( 'boom' );

    expect( $exception )->toBeInstanceOf( RuntimeException::class );
} );

it( 'is the base class for every shipped engine exception', function ( string $exceptionClass ): void {
    expect( is_subclass_of( $exceptionClass, EcommerceException::class ) )->toBeTrue();
} )->with( [
    CartCurrencyMismatchException::class,
    ClaimRateLimitedException::class,
    ClaimVerificationFailedException::class,
    IncompatibleBoardSubstatusException::class,
    InsufficientStockException::class,
    InvalidOrderStatusTransitionException::class,
    OrderNotEditableException::class,
    RefundNotAllowedException::class,
] );
