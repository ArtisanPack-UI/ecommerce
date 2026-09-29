<?php

/**
 * WebhookSigner.
 *
 * Builds and verifies the outbound webhook signature header (engine spec
 * §8.1):
 *
 * ```
 * X-ArtisanPack-Signature: t=<unix_ts>, v1=<hex(hmac_sha256(t + '.' + payload, secret))>
 * ```
 *
 * Receivers verify with {@see self::verify()} (or an equivalent in their
 * language): compare `v1` in constant time against the HMAC of the raw
 * request body, and reject timestamps older than the tolerance so a
 * captured delivery can't be replayed later.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Webhooks;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class WebhookSigner
{
    /**
     * Signature header name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const HEADER = 'X-ArtisanPack-Signature';

    /**
     * Default replay tolerance, in seconds.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Builds the signature header value for `$payload`.
     *
     * @since 1.0.0
     *
     * @param  string  $payload    Raw JSON body, exactly as sent.
     * @param  string  $secret     Subscription secret.
     * @param  int     $timestamp  Unix timestamp of the attempt.
     *
     * @return string
     */
    public static function header( string $payload, string $secret, int $timestamp ): string
    {
        return sprintf( 't=%d, v1=%s', $timestamp, self::hmac( $payload, $secret, $timestamp ) );
    }

    /**
     * Verifies a signature header against the raw body.
     *
     * @since 1.0.0
     *
     * @param  string    $header     Received `X-ArtisanPack-Signature` value.
     * @param  string    $payload    Raw request body.
     * @param  string    $secret     Subscription secret.
     * @param  int       $tolerance  Maximum age in seconds.
     * @param  int|null  $now        Reference time (defaults to `time()`).
     *
     * @return bool
     */
    public static function verify( string $header, string $payload, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null ): bool
    {
        $parts = [];

        foreach ( explode( ',', $header ) as $segment ) {
            [ $key, $value ] = array_pad( explode( '=', trim( $segment ), 2 ), 2, '' );
            $parts[ $key ]   = $value;
        }

        if ( ! isset( $parts['t'], $parts['v1'] ) || ! ctype_digit( $parts['t'] ) ) {
            return false;
        }

        $timestamp = (int) $parts['t'];

        if ( abs( ( $now ?? time() ) - $timestamp ) > $tolerance ) {
            return false;
        }

        return hash_equals( self::hmac( $payload, $secret, $timestamp ), $parts['v1'] );
    }

    /**
     * `hex( hmac_sha256( t + '.' + payload, secret ) )`.
     *
     * @since 1.0.0
     *
     * @param  string  $payload    Raw body.
     * @param  string  $secret     Secret.
     * @param  int     $timestamp  Unix timestamp.
     *
     * @return string
     */
    private static function hmac( string $payload, string $secret, int $timestamp ): string
    {
        return hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
    }
}
