<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Support\RequestContext;

afterEach( function (): void {
    RequestContext::reset();
} );

it( 'starts with a null request id', function (): void {
    expect( RequestContext::requestId() )->toBeNull();
} );

it( 'stores and returns the request id it is given', function (): void {
    RequestContext::setRequestId( 'req-123' );

    expect( RequestContext::requestId() )->toBe( 'req-123' );
} );

it( 'clears the request id on reset()', function (): void {
    RequestContext::setRequestId( 'req-123' );
    RequestContext::reset();

    expect( RequestContext::requestId() )->toBeNull();
} );

it( 'generates and holds a UUID when requestIdOrGenerate() is called with no active id', function (): void {
    $generated = RequestContext::requestIdOrGenerate();

    expect( $generated )->toMatch( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/' );
    expect( RequestContext::requestId() )->toBe( $generated );
} );

it( 'returns the existing id (not a new UUID) when requestIdOrGenerate() finds one held', function (): void {
    RequestContext::setRequestId( 'existing-id' );

    expect( RequestContext::requestIdOrGenerate() )->toBe( 'existing-id' );
    expect( RequestContext::requestId() )->toBe( 'existing-id' );
} );
