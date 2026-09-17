<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\ValueObjects\FraudDecision;

it( 'constructs an approve verdict', function (): void {
    $d = FraudDecision::approve( 12, [ 'clean' ], 'ref_1' );

    expect( $d->isApprove() )->toBeTrue();
    expect( $d->isChallenge() )->toBeFalse();
    expect( $d->isBlock() )->toBeFalse();
    expect( $d->verdict )->toBe( 'approve' );
    expect( $d->score )->toBe( 12 );
    expect( $d->reasons )->toBe( [ 'clean' ] );
    expect( $d->providerReference )->toBe( 'ref_1' );
} );

it( 'constructs a challenge verdict', function (): void {
    $d = FraudDecision::challenge( 55 );

    expect( $d->isChallenge() )->toBeTrue();
    expect( $d->verdict )->toBe( 'challenge' );
} );

it( 'constructs a block verdict', function (): void {
    $d = FraudDecision::block( 92, [ 'ip_blocklist' ] );

    expect( $d->isBlock() )->toBeTrue();
    expect( $d->reasons )->toBe( [ 'ip_blocklist' ] );
} );

it( 'rejects an unknown verdict', function (): void {
    new FraudDecision( 'maybe' );
} )->throws( InvalidArgumentException::class, 'verdict must be one of' );

it( 'rejects an out-of-range score', function (): void {
    new FraudDecision( 'approve', 150 );
} )->throws( InvalidArgumentException::class, 'score must be between' );

it( 'serialises to array under snake_case keys', function (): void {
    $d = FraudDecision::block( 80, [ 'a', 'b' ], 'x' );

    expect( $d->toArray() )->toBe( [
        'verdict'            => 'block',
        'score'              => 80,
        'reasons'            => [ 'a', 'b' ],
        'provider_reference' => 'x',
    ] );
} );
