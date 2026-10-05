<?php

/**
 * Rate-limit policy registrar.
 *
 * Registers the named `RateLimiter` policies enumerated in the engine spec
 * §11.3 (parent plan §16.1) against Laravel's `RateLimiter` facade. Each
 * policy resolves to one or more {@see Limit} objects — compound policies
 * (e.g. `ecommerce.checkout.finalize`) return an array so both the per-IP
 * and per-subject buckets throttle independently.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Wires the ecommerce rate-limit policies into Laravel's rate limiter.
 *
 * **IP-keyed buckets rely on `Request::ip()`.** Behind a reverse proxy or
 * CDN, an application that has not configured Laravel's `TrustedProxies`
 * middleware sees every request as coming from the proxy — every customer
 * shares one bucket and legitimate traffic locks itself out. Configure
 * trusted proxies before putting these policies in front of real traffic.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RateLimitPolicyRegistrar
{
    /**
     * Every policy this package ships with, in the order the plan lists them.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const POLICIES = [
        'ecommerce.catalog.read',
        'ecommerce.cart.mutate',
        'ecommerce.checkout.finalize',
        'ecommerce.coupon.attempt',
        'ecommerce.login',
        'ecommerce.review.submit',
        'ecommerce.claim.attempt',
        'ecommerce.license.validate',
        'ecommerce.webhook.inbound',
        'ecommerce.admin.mutate',
    ];

    /**
     * Registers every policy in {@see self::POLICIES} with the rate limiter.
     *
     * Called from `EcommerceServiceProvider::boot()`. Safe to call more than
     * once — later registrations replace earlier ones for the same name.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function register(): void
    {
        RateLimiter::for( 'ecommerce.catalog.read', function ( Request $request ): array {
            return [
                Limit::perMinute( self::limit( 'catalog.read.per_ip', 300 ) )
                    ->by( 'ecommerce:catalog:ip:' . sha1( (string) $request->ip() ) ),
            ];
        } );

        RateLimiter::for( 'ecommerce.cart.mutate', function ( Request $request ): array {
            // The per-IP bucket bounds callers who mint a fresh cart (or
            // send a fresh token) per request to dodge the per-cart bucket.
            return [
                Limit::perMinute( self::limit( 'cart.mutate.per_cart', 60 ) )
                    ->by( 'ecommerce:cart:token:' . self::cartSubject( $request ) ),
                Limit::perMinute( self::limit( 'cart.mutate.per_ip', 300 ) )
                    ->by( 'ecommerce:cart:ip:' . sha1( (string) $request->ip() ) ),
            ];
        } );

        RateLimiter::for( 'ecommerce.checkout.finalize', function ( Request $request ): array {
            return [
                Limit::perMinute( self::limit( 'checkout.finalize.per_ip', 6 ) )
                    ->by( 'ecommerce:checkout:ip:' . sha1( (string) $request->ip() ) ),
                Limit::perHour( self::limit( 'checkout.finalize.per_cart', 12 ) )
                    ->by( 'ecommerce:checkout:cart:' . self::cartSubject( $request ) ),
            ];
        } );

        RateLimiter::for( 'ecommerce.coupon.attempt', function ( Request $request ): array {
            return [
                Limit::perHour( self::limit( 'coupon.attempt.per_cart', 10 ) )
                    ->by( 'ecommerce:coupon:cart:' . self::cartSubject( $request ) ),
                Limit::perHour( self::limit( 'coupon.attempt.per_ip', 30 ) )
                    ->by( 'ecommerce:coupon:ip:' . sha1( (string) $request->ip() ) ),
            ];
        } );

        RateLimiter::for( 'ecommerce.login', function ( Request $request ): array {
            $email  = strtolower( trim( (string) ( $request->input( 'email' ) ?? '' ) ) );
            $limits = [
                Limit::perMinute( self::limit( 'login.per_ip', 5 ) )
                    ->by( 'ecommerce:login:ip:' . sha1( (string) $request->ip() ) ),
            ];

            // Emit the per-email bucket only for requests that actually carry
            // one — an empty value would sha1 to a constant, collapsing every
            // email-less caller into a single global bucket that an attacker
            // could exhaust to lock legitimate submissions out.
            if ( '' !== $email ) {
                $limits[] = Limit::perHour( self::limit( 'login.per_email', 20 ) )
                    ->by( 'ecommerce:login:email:' . sha1( $email ) );
            }

            return $limits;
        } );

        RateLimiter::for( 'ecommerce.review.submit', function ( Request $request ): array {
            $user = $request->user();

            return [
                Limit::perHour( self::limit( 'review.submit.per_customer', 3 ) )
                    ->by( 'ecommerce:review:customer:' . ( null !== $user ? (string) $user->getAuthIdentifier() : 'guest:' . sha1( (string) $request->ip() ) ) ),
                Limit::perHour( self::limit( 'review.submit.per_ip', 10 ) )
                    ->by( 'ecommerce:review:ip:' . sha1( (string) $request->ip() ) ),
            ];
        } );

        // Guest-order claims (#173): a claim checks an order number and postal
        // code, so it is throttled per shopper and per IP. The per-shopper
        // default follows `customers.claim_rate_limit` / `_window_minutes`.
        RateLimiter::for( 'ecommerce.claim.attempt', function ( Request $request ): array {
            $user    = $request->user();
            $minutes = max( 1, (int) config( 'artisanpack.ecommerce.customers.claim_rate_window_minutes', 60 ) );

            return [
                Limit::perMinutes( $minutes, self::limit( 'claim.attempt.per_customer', max( 1, (int) config( 'artisanpack.ecommerce.customers.claim_rate_limit', 5 ) ) ) )
                    ->by( 'ecommerce:claim:customer:' . ( null !== $user ? (string) $user->getAuthIdentifier() : 'guest:' . sha1( (string) $request->ip() ) ) ),
                Limit::perHour( self::limit( 'claim.attempt.per_ip', 30 ) )
                    ->by( 'ecommerce:claim:ip:' . sha1( (string) $request->ip() ) ),
            ];
        } );

        RateLimiter::for( 'ecommerce.license.validate', function ( Request $request ): array {
            $licenseKey = $request->route( 'license_key' ) ?? $request->input( 'key' ) ?? $request->input( 'license_key' ) ?? '';
            $licenseKey = is_string( $licenseKey ) ? strtoupper( trim( $licenseKey ) ) : '';
            $limits     = [
                Limit::perMinute( self::limit( 'license.validate.per_ip', 600 ) )
                    ->by( 'ecommerce:license:ip:' . sha1( (string) $request->ip() ) ),
            ];

            // Same empty-subject guard as `login`: `sha1( '' )` is fixed, so
            // every keyless call would share one bucket.
            if ( '' !== $licenseKey ) {
                $limits[] = Limit::perMinute( self::limit( 'license.validate.per_license', 60 ) )
                    ->by( 'ecommerce:license:key:' . sha1( $licenseKey ) );
            }

            return $limits;
        } );

        // Every inbound webhook request counts against its caller's IP
        // (G1): junk for made-up providers or with bad signatures is bounded
        // per source instead of filling a shared bucket.
        RateLimiter::for( 'ecommerce.webhook.inbound', function ( Request $request ): array {
            return [
                Limit::perMinute( self::limit( 'webhook.inbound.per_ip', 120 ) )
                    ->by( 'ecommerce:webhook:ip:' . sha1( (string) $request->ip() ) ),
            ];
        } );

        // Signature-verified deliveries per registered provider, counted by
        // the webhook controller after verification so unverified traffic
        // can't use up a real provider's allowance.
        RateLimiter::for( 'ecommerce.webhook.verified', function ( Request $request ): array {
            $provider = $request->route( 'provider' );

            return [
                Limit::perMinute( self::limit( 'webhook.inbound.per_provider', 1_000 ) )
                    ->by( 'ecommerce:webhook:provider:' . sha1( is_string( $provider ) ? $provider : '' ) ),
            ];
        } );

        RateLimiter::for( 'ecommerce.admin.mutate', function ( Request $request ): array {
            $user = $request->user();

            $subject = null !== $user
                ? 'user:' . $user->getAuthIdentifier()
                : 'ip:' . sha1( (string) $request->ip() );

            return [
                Limit::perMinute( self::limit( 'admin.mutate.per_user', 120 ) )
                    ->by( 'ecommerce:admin:' . $subject ),
            ];
        } );
    }

    /**
     * Reads a per-policy limit from configuration, falling back to the
     * shipped default when the value is missing or non-positive.
     *
     * A blank environment variable is a likelier way to read as zero than
     * a decision to leave an endpoint unbounded, so a non-positive value
     * intentionally falls through to the default rather than being taken
     * at face value.
     *
     * @since 1.0.0
     *
     * @param  string  $path  Dot path under `artisanpack.ecommerce.rate_limits`.
     * @param  int  $default  The shipped default.
     *
     * @return int The effective per-window request cap.
     */
    protected static function limit( string $path, int $default ): int
    {
        $configured = config( 'artisanpack.ecommerce.rate_limits.' . $path ) ?? self::fromPolicyArray( $path );

        if ( is_numeric( $configured ) && (int) $configured > 0 ) {
            return (int) $configured;
        }

        return $default;
    }

    /**
     * Reads `$path` (`checkout.finalize.per_ip`) from the shipped config
     * shape, where the policy name is a single dotted array key
     * (`'checkout.finalize' => [ 'per_ip' => … ]`). Laravel's dot-notation
     * lookup can't reach through such a key, so without this a published
     * config or `ECOMMERCE_RATE_*` override would silently never apply.
     *
     * @since 1.0.0
     *
     * @param  string  $path  Policy name plus bucket, dot-separated.
     *
     * @return mixed
     */
    protected static function fromPolicyArray( string $path ): mixed
    {
        $separator = strrpos( $path, '.' );

        if ( false === $separator ) {
            return null;
        }

        $limits = config( 'artisanpack.ecommerce.rate_limits' );

        return is_array( $limits ) ? ( $limits[ substr( $path, 0, $separator ) ][ substr( $path, $separator + 1 ) ] ?? null ) : null;
    }

    /**
     * Resolves the cart-scoped subject used for cart-keyed policies.
     *
     * Prefers the cart the request actually operates on — the `cart` route
     * parameter, then a `cart_token` input — and only then an
     * `X-Cart-Token` header, falling back to the caller's IP. The header
     * comes last because it is client-controlled: preferring it would let
     * a caller pick a fresh bucket per request for a cart named elsewhere.
     * The value is hashed so the raw token never lands in a cache key.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return string The hashed cart subject.
     */
    protected static function cartSubject( Request $request ): string
    {
        $token = $request->route( 'cart' )
            ?? $request->input( 'cart_token' )
            ?? $request->header( 'X-Cart-Token' );

        if ( is_string( $token ) && '' !== $token ) {
            return sha1( 'token:' . $token );
        }

        return sha1( 'ip:' . (string) $request->ip() );
    }
}
