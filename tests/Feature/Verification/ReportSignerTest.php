<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Testing\Verification\ReportSigner;

it( 'round-trips a signature', function (): void {
    $keys      = ReportSigner::generateKeyPair();
    $signer    = new ReportSigner();
    $signature = $signer->sign( "{\"verified\":true}\n", $keys['secret_key'] );

    expect( $signature['algorithm'] )->toBe( 'ed25519' )
        ->and( $signature['report_sha256'] )->toBe( hash( 'sha256', "{\"verified\":true}\n" ) )
        ->and( $signature['public_key'] )->toBe( $keys['public_key'] )
        ->and( $signature['key_id'] )->toBe( ReportSigner::keyId( base64_decode( $keys['public_key'] ) ) )
        ->and( $signer->verify( "{\"verified\":true}\n", $signature, $keys['public_key'] ) )->toBeTrue();
} );

it( 'detects tampering with the report, the signature, or the key', function (): void {
    $keys      = ReportSigner::generateKeyPair();
    $other     = ReportSigner::generateKeyPair();
    $signer    = new ReportSigner();
    $report    = "{\"verified\":false}\n";
    $signature = $signer->sign( $report, $keys['secret_key'] );

    $forged              = $signature;
    $forged['signature'] = base64_encode( str_repeat( "\0", SODIUM_CRYPTO_SIGN_BYTES ) );

    $rehashed                  = $signature;
    $rehashed['report_sha256'] = hash( 'sha256', "{\"verified\":true}\n" );

    expect( $signer->verify( "{\"verified\":true}\n", $signature, $keys['public_key'] ) )->toBeFalse()
        ->and( $signer->verify( "{\"verified\":true}\n", $rehashed, $keys['public_key'] ) )->toBeFalse()
        ->and( $signer->verify( $report, $forged, $keys['public_key'] ) )->toBeFalse()
        ->and( $signer->verify( $report, $signature, $other['public_key'] ) )->toBeFalse()
        ->and( $signer->verify( $report, [ 'algorithm' => 'rsa' ] + $signature, $keys['public_key'] ) )->toBeFalse()
        ->and( $signer->verify( $report, $signature, 'not-base64!' ) )->toBeFalse();
} );

it( 'accepts a 32-byte seed as the signing key', function (): void {
    $seed      = random_bytes( SODIUM_CRYPTO_SIGN_SEEDBYTES );
    $public    = base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( $seed ) ) );
    $signer    = new ReportSigner();
    $signature = $signer->sign( 'report', base64_encode( $seed ) );

    expect( $signer->verify( 'report', $signature, $public ) )->toBeTrue();
} );

it( 'rejects a malformed signing key', function (): void {
    ( new ReportSigner() )->sign( 'report', base64_encode( 'short' ) );
} )->throws( InvalidArgumentException::class );
