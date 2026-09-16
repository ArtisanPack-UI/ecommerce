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
use Illuminate\Support\Facades\Log;
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
            return [
                Limit::perMinute( self::limit( 'cart.mutate.per_cart', 60 ) )
                    ->by( 'ecommerce:cart:token:' . self::cartSubject( $request ) ),
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

        RateLimiter::for( 'ecommerce.license.validate', function ( Request $request ): array {
            $licenseKey = (string) ( $request->route( 'license_key' ) ?? $request->input( 'license_key' ) ?? '' );
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

        RateLimiter::for( 'ecommerce.webhook.inbound', function ( Request $request ): array {
            $provider = $request->route( 'provider' ) ?? $request->route( 'gateway' );

            if ( ! is_string( $provider ) || '' === $provider ) {
                // A webhook route wired without a `{provider}`/`{gateway}`
                // segment would silently pool every inbound provider into
                // one shared 1000/min bucket. Fall back to the caller IP so
                // the misconfiguration bounds itself while surfacing loudly
                // in the log so operators notice.
                Log::warning( 'ecommerce.rate_limit.webhook.missing_provider', [
                    'route' => optional( $request->route() )->getName(),
                ] );

                $provider = 'ip:' . sha1( (string) $request->ip() );
            }

            return [
                Limit::perMinute( self::limit( 'webhook.inbound.per_provider', 1_000 ) )
                    ->by( 'ecommerce:webhook:provider:' . sha1( $provider ) ),
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
        $configured = config( 'artisanpack.ecommerce.rate_limits.' . $path );

        if ( is_numeric( $configured ) && (int) $configured > 0 ) {
            return (int) $configured;
        }

        return $default;
    }

    /**
     * Resolves the cart-scoped subject used for cart-keyed policies.
     *
     * Prefers an explicit `X-Cart-Token` header, falling back to a `cart`
     * route parameter, then the caller's IP. The value is hashed by the
     * caller so the raw token never lands in a cache key.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return string The hashed cart subject.
     */
    protected static function cartSubject( Request $request ): string
    {
        $token = $request->header( 'X-Cart-Token' )
            ?? $request->route( 'cart' )
            ?? $request->input( 'cart_token' );

        if ( is_string( $token ) && '' !== $token ) {
            return sha1( 'token:' . $token );
        }

        return sha1( 'ip:' . (string) $request->ip() );
    }
}
