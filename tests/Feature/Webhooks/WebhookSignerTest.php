<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Webhooks\WebhookSigner;

it( 'builds the t=, v1= header over timestamp.payload', function (): void {
    $header = WebhookSigner::header( '{"a":1}', 'secret', 1_700_000_000 );

    expect( $header )->toBe( 't=1700000000, v1=' . hash_hmac( 'sha256', '1700000000.{"a":1}', 'secret' ) );
} );

it( 'verifies a fresh, untampered signature', function (): void {
    $header = WebhookSigner::header( 'body', 'secret', 1_000 );

    expect( WebhookSigner::verify( $header, 'body', 'secret', 300, 1_100 ) )->toBeTrue();
} );

it( 'rejects tampering, the wrong secret, stale timestamps, and malformed headers', function ( string $header, string $body, string $secret, int $now ): void {
    expect( WebhookSigner::verify( $header, $body, $secret, 300, $now ) )->toBeFalse();
} )->with( [
    'tampered body'  => [ WebhookSigner::header( 'body', 'secret', 1_000 ), 'b0dy', 'secret', 1_000 ],
    'wrong secret'   => [ WebhookSigner::header( 'body', 'secret', 1_000 ), 'body', 'other', 1_000 ],
    'stale'          => [ WebhookSigner::header( 'body', 'secret', 1_000 ), 'body', 'secret', 1_301 ],
    'from future'    => [ WebhookSigner::header( 'body', 'secret', 2_000 ), 'body', 'secret', 1_000 ],
    'missing v1'     => [ 't=1000', 'body', 'secret', 1_000 ],
    'non-numeric t'  => [ 't=abc, v1=deadbeef', 'body', 'secret', 1_000 ],
] );
