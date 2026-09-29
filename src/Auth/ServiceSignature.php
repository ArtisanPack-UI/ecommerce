<?php

/**
 * ServiceSignature.
 *
 * Signs and parses the service-to-service `Authorization` header from
 * engine spec §11.4:
 *
 * ```
 * Authorization: Signature keyId="{service}",algorithm="hmac-sha256",
 *     headers="(request-target) host date digest",signature="{base64}"
 * ```
 *
 * The signature is `base64( hmac_sha256( signingString, secret ) )` where
 * the signing string joins each listed header as `name: value` with `\n`.
 * `(request-target)` is the lowercase method, a space, and the request URI
 * (path + query). `digest` is `SHA-256=base64( sha256( body ) )`.
 *
 * {@see self::sign()} is the client half, used by satellites and tests;
 * {@see \ArtisanPackUI\Ecommerce\Http\Middleware\ServiceSignatureMiddleware}
 * is the verifying half.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Auth;

use Illuminate\Support\Carbon;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ServiceSignature
{
    /**
     * The only supported algorithm.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ALGORITHM = 'hmac-sha256';

    /**
     * Headers every signature must cover.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const REQUIRED_HEADERS = [ '(request-target)', 'host', 'date', 'digest' ];

    /**
     * Builds the headers a service sends for a signed request.
     *
     * @since 1.0.0
     *
     * @param  string       $keyId   Service name configured on the engine.
     * @param  string       $secret  Shared secret for that service.
     * @param  string       $method  HTTP method.
     * @param  string       $uri     Request URI (path + optional query string).
     * @param  string       $host    Host header value.
     * @param  string       $body    Raw request body.
     * @param  Carbon|null  $date    Signing time (defaults to now).
     *
     * @return array{Authorization: string, Date: string, Digest: string, Host: string}
     */
    public static function sign( string $keyId, string $secret, string $method, string $uri, string $host, string $body = '', ?Carbon $date = null ): array
    {
        $headers = [
            'host'   => $host,
            'date'   => ( $date ?? Carbon::now() )->toRfc7231String(),
            'digest' => self::digest( $body ),
        ];

        $signature = self::compute(
            self::signingString( self::REQUIRED_HEADERS, $method, $uri, $headers ),
            $secret,
        );

        return [
            'Authorization' => sprintf(
                'Signature keyId="%s",algorithm="%s",headers="%s",signature="%s"',
                $keyId,
                self::ALGORITHM,
                implode( ' ', self::REQUIRED_HEADERS ),
                $signature,
            ),
            'Date'          => $headers['date'],
            'Digest'        => $headers['digest'],
            'Host'          => $host,
        ];
    }

    /**
     * Parses a `Signature ...` authorization header into its parameters, or
     * returns `null` when the header is not a signature header.
     *
     * @since 1.0.0
     *
     * @param  string|null  $header  Raw `Authorization` header.
     *
     * @return array<string, string>|null
     */
    public static function parse( ?string $header ): ?array
    {
        if ( null === $header || 0 !== stripos( ltrim( $header ), 'Signature ' ) ) {
            return null;
        }

        preg_match_all( '/(\w+)="([^"]*)"/', $header, $matches, PREG_SET_ORDER );

        $params = [];

        foreach ( $matches as [ , $name, $value ] ) {
            $params[ $name ] = $value;
        }

        return $params;
    }

    /**
     * The `Digest` header value for a body.
     *
     * @since 1.0.0
     *
     * @param  string  $body  Raw body.
     *
     * @return string
     */
    public static function digest( string $body ): string
    {
        return 'SHA-256=' . base64_encode( hash( 'sha256', $body, true ) );
    }

    /**
     * Builds the signing string for the listed headers.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>     $names    Header names, in signing order.
     * @param  string                 $method   HTTP method.
     * @param  string                 $uri      Request URI.
     * @param  array<string, string>  $headers  Lowercase header name → value.
     *
     * @return string
     */
    public static function signingString( array $names, string $method, string $uri, array $headers ): string
    {
        $lines = [];

        foreach ( $names as $name ) {
            $lines[] = '(request-target)' === $name
                ? '(request-target): ' . strtolower( $method ) . ' ' . $uri
                : $name . ': ' . ( $headers[ $name ] ?? '' );
        }

        return implode( "\n", $lines );
    }

    /**
     * `base64( hmac_sha256( $signingString, $secret ) )`.
     *
     * @since 1.0.0
     *
     * @param  string  $signingString  Signing string.
     * @param  string  $secret         Shared secret.
     *
     * @return string
     */
    public static function compute( string $signingString, string $secret ): string
    {
        return base64_encode( hash_hmac( 'sha256', $signingString, $secret, true ) );
    }
}
