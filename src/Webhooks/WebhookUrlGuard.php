<?php

/**
 * WebhookUrlGuard.
 *
 * Keeps outbound webhooks from being pointed at the store's own network
 * (SSRF). A webhook URL is only acceptable when its host resolves, and
 * every address it resolves to is a public one — not loopback, private
 * (RFC 1918 / IPv6 ULA), link-local (incl. cloud metadata at
 * 169.254.169.254), CGNAT, or otherwise reserved.
 *
 * The check runs twice: when a subscription is saved (validation) and
 * again right before each delivery, where the vetted address is returned
 * so the request can be pinned to it — a DNS answer that changes between
 * the check and the connection (rebinding) can't redirect the delivery.
 *
 * Set `artisanpack.ecommerce.webhooks.allow_private_hosts` to allow
 * private targets (local development, or receivers on a private network).
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

use Closure;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class WebhookUrlGuard
{
    /**
     * IPv4 ranges PHP's FILTER_FLAG_NO_RES_RANGE does not cover on every
     * supported version.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected const EXTRA_BLOCKED_V4 = [
        '100.64.0.0/10',
        '169.254.0.0/16',
        '192.0.0.0/24',
        '198.18.0.0/15',
    ];

    /**
     * Test seam: replaces DNS resolution.
     *
     * @since 1.0.0
     *
     * @var (Closure(string): array<int, string>)|null
     */
    protected static ?Closure $resolver = null;

    /**
     * Overrides host resolution (tests). Pass null to restore DNS.
     *
     * @since 1.0.0
     *
     * @param  (Closure(string): array<int, string>)|null  $resolver  Host → IPs.
     *
     * @return void
     */
    public static function resolveUsing( ?Closure $resolver ): void
    {
        static::$resolver = $resolver;
    }

    /**
     * The address to connect to for `$url`, or null when the URL must not
     * be called.
     *
     * @since 1.0.0
     *
     * @param  string  $url  Webhook URL.
     *
     * @return string|null
     */
    public function vettedAddress( string $url ): ?string
    {
        $host = parse_url( $url, PHP_URL_HOST );

        if ( ! is_string( $host ) || '' === $host ) {
            return null;
        }

        $host      = trim( $host, '[]' );
        $addresses = filter_var( $host, FILTER_VALIDATE_IP ) ? [ $host ] : $this->resolve( $host );

        if ( [] === $addresses ) {
            return null;
        }

        if ( ! (bool) config( 'artisanpack.ecommerce.webhooks.allow_private_hosts', false ) ) {
            foreach ( $addresses as $address ) {
                if ( ! $this->isPublic( $address ) ) {
                    return null;
                }
            }
        }

        return $addresses[0];
    }

    /**
     * Guzzle options that pin the connection for `$url` to `$address`, so a
     * DNS change between vetting and sending can't redirect the request.
     *
     * @since 1.0.0
     *
     * @param  string  $url      Endpoint URL.
     * @param  string  $address  Vetted IP address (from {@see self::vettedAddress()}).
     *
     * @return array<string, mixed>
     */
    public static function pinOptions( string $url, string $address ): array
    {
        $host = (string) parse_url( $url, PHP_URL_HOST );

        if ( ! defined( 'CURLOPT_RESOLVE' ) || false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
            return [];
        }

        $port = parse_url( $url, PHP_URL_PORT ) ?? ( 'http' === parse_url( $url, PHP_URL_SCHEME ) ? 80 : 443 );
        $ip   = false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? '[' . $address . ']' : $address;

        return [ 'curl' => [ CURLOPT_RESOLVE => [ sprintf( '%s:%d:%s', $host, $port, $ip ) ] ] ];
    }

    /**
     * Whether `$url` may be used as a webhook endpoint.
     *
     * @since 1.0.0
     *
     * @param  string  $url  Webhook URL.
     *
     * @return bool
     */
    public function allows( string $url ): bool
    {
        return null !== $this->vettedAddress( $url );
    }

    /**
     * Whether an IP address is publicly routable.
     *
     * @since 1.0.0
     *
     * @param  string  $address  IPv4 or IPv6 address.
     *
     * @return bool
     */
    public function isPublic( string $address ): bool
    {
        // IPv4-mapped IPv6 (::ffff:10.0.0.1) — judge the embedded IPv4.
        if ( 1 === preg_match( '/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $mapped ) ) {
            return $this->isPublic( $mapped[1] );
        }

        if ( false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            return false;
        }

        if ( false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            // Link-local fe80::/10 and ULA fc00::/7 (in case the PHP build's
            // range tables miss them), and the IPv4-translating prefixes —
            // NAT64 64:ff9b::/96 + 64:ff9b:1::/48 and 6to4 2002::/16 — which
            // can reach private IPv4 hosts through a translator.
            return 1 !== preg_match( '/^(fe[89ab]|f[cd]|64:ff9b:|2002:)/i', $address );
        }

        foreach ( self::EXTRA_BLOCKED_V4 as $cidr ) {
            if ( $this->inCidr( $address, $cidr ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolves a host to its A and AAAA addresses.
     *
     * @since 1.0.0
     *
     * @param  string  $host  Hostname.
     *
     * @return array<int, string>
     */
    protected function resolve( string $host ): array
    {
        if ( null !== static::$resolver ) {
            return array_values( ( static::$resolver )( $host ) );
        }

        $addresses = [];
        $records   = @dns_get_record( $host, DNS_A | DNS_AAAA ) ?: [];

        foreach ( $records as $record ) {
            $addresses[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }

        if ( [] === array_filter( $addresses ) ) {
            $addresses = gethostbynamel( $host ) ?: [];
        }

        return array_values( array_unique( array_filter( $addresses ) ) );
    }

    /**
     * Whether an IPv4 address falls inside a CIDR block.
     *
     * @since 1.0.0
     *
     * @param  string  $address  IPv4 address.
     * @param  string  $cidr     e.g. `100.64.0.0/10`.
     *
     * @return bool
     */
    protected function inCidr( string $address, string $cidr ): bool
    {
        [ $subnet, $bits ] = explode( '/', $cidr );
        $mask              = -1 << ( 32 - (int) $bits );

        return ( ip2long( $address ) & $mask ) === ( ip2long( $subnet ) & $mask );
    }
}
