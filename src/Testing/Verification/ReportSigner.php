<?php

/**
 * ReportSigner.
 *
 * Ed25519 detached signatures (libsodium) over the exact bytes of a
 * verification report. The signature file (`verify-report.sig`) is JSON:
 *
 *     {
 *         "schema": 1,
 *         "algorithm": "ed25519",
 *         "report_sha256": "<hex>",
 *         "key_id": "<first 16 hex chars of sha256(public key)>",
 *         "public_key": "<base64>",
 *         "signature": "<base64>"
 *     }
 *
 * Verification never trusts the embedded public key — callers pass the
 * trusted key; the embedded one is informational (it lets a reader see
 * which key signed a report).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing\Verification;

use InvalidArgumentException;
use SodiumException;

/**
 * Signs and verifies verification reports.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ReportSigner
{
    /**
     * Signature file format version.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const SCHEMA_VERSION = 1;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const ALGORITHM = 'ed25519';

    /**
     * Generates a new keypair.
     *
     * @since 1.0.0
     *
     * @return array{secret_key: string, public_key: string} Base64-encoded keys.
     */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'secret_key' => base64_encode( sodium_crypto_sign_secretkey( $pair ) ),
            'public_key' => base64_encode( sodium_crypto_sign_publickey( $pair ) ),
        ];
    }

    /**
     * Signs `$report` and returns the signature document.
     *
     * @since 1.0.0
     *
     * @param  string  $report     Exact report bytes.
     * @param  string  $secretKey  Base64 Ed25519 secret key (64 bytes) or seed (32 bytes).
     *
     * @throws InvalidArgumentException When the key is malformed.
     *
     * @return array<string, mixed>
     */
    public function sign( string $report, string $secretKey ): array
    {
        $secret    = $this->secretKey( $secretKey );
        $publicKey = sodium_crypto_sign_publickey_from_secretkey( $secret );

        return [
            'schema'        => self::SCHEMA_VERSION,
            'algorithm'     => self::ALGORITHM,
            'report_sha256' => hash( 'sha256', $report ),
            'key_id'        => self::keyId( $publicKey ),
            'public_key'    => base64_encode( $publicKey ),
            'signature'     => base64_encode( sodium_crypto_sign_detached( $report, $secret ) ),
        ];
    }

    /**
     * Whether `$signature` is a valid signature of `$report` by the holder
     * of `$publicKey`.
     *
     * @since 1.0.0
     *
     * @param  string                $report     Exact report bytes.
     * @param  array<string, mixed>  $signature  Signature document from {@see self::sign()}.
     * @param  string                $publicKey  Trusted base64 Ed25519 public key.
     *
     * @return bool
     */
    public function verify( string $report, array $signature, string $publicKey ): bool
    {
        if ( self::ALGORITHM !== ( $signature['algorithm'] ?? null ) ) {
            return false;
        }

        if ( ! hash_equals( hash( 'sha256', $report ), (string) ( $signature['report_sha256'] ?? '' ) ) ) {
            return false;
        }

        $public = base64_decode( $publicKey, true );
        $sig    = base64_decode( (string) ( $signature['signature'] ?? '' ), true );

        if ( false === $public || false === $sig || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) ) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached( $sig, $report, $public );
        } catch ( SodiumException ) {
            return false;
        }
    }

    /**
     * Encodes a signature document as the `verify-report.sig` file body.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $signature  Signature document.
     *
     * @return string
     */
    public function encode( array $signature ): string
    {
        return json_encode( $signature, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
    }

    /**
     * Short identifier for a public key.
     *
     * @since 1.0.0
     *
     * @param  string  $publicKey  Raw (binary) public key.
     *
     * @return string
     */
    public static function keyId( string $publicKey ): string
    {
        return substr( hash( 'sha256', $publicKey ), 0, 16 );
    }

    /**
     * Decodes a base64 secret key, expanding a 32-byte seed to a full key.
     *
     * @since 1.0.0
     *
     * @param  string  $secretKey  Base64 secret key or seed.
     *
     * @throws InvalidArgumentException When the key is malformed.
     *
     * @return string
     */
    protected function secretKey( string $secretKey ): string
    {
        $raw = base64_decode( trim( $secretKey ), true );

        if ( false !== $raw && SODIUM_CRYPTO_SIGN_SEEDBYTES === strlen( $raw ) ) {
            return sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( $raw ) );
        }

        if ( false === $raw || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $raw ) ) {
            throw new InvalidArgumentException( __( 'The signing key must be a base64-encoded Ed25519 secret key (64 bytes) or seed (32 bytes).' ) );
        }

        return $raw;
    }
}
