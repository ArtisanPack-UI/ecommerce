<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;

it( 'builds a verified webhook result via the verified() convenience constructor', function (): void {
    $result = WebhookResult::verified( 'payment.captured', 'evt_123', [ 'id' => 'evt_123' ] );

    expect( $result->verified )->toBeTrue();
    expect( $result->eventType )->toBe( 'payment.captured' );
    expect( $result->eventId )->toBe( 'evt_123' );
    expect( $result->payload )->toBe( [ 'id' => 'evt_123' ] );
    expect( $result->errorCode )->toBeNull();
} );

it( 'builds an unverified webhook result and defaults the error code to signature_mismatch', function (): void {
    $result = WebhookResult::unverified();

    expect( $result->verified )->toBeFalse();
    expect( $result->eventType )->toBeNull();
    expect( $result->eventId )->toBeNull();
    expect( $result->payload )->toBe( [] );
    expect( $result->errorCode )->toBe( 'signature_mismatch' );
} );

it( 'preserves a caller-supplied error code and message on an unverified result', function (): void {
    $result = WebhookResult::unverified( 'stale_timestamp', 'Timestamp older than tolerance.' );

    expect( $result->errorCode )->toBe( 'stale_timestamp' );
    expect( $result->errorMessage )->toBe( 'Timestamp older than tolerance.' );
} );
