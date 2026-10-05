<?php

/**
 * ServiceSignatureMiddleware.
 *
 * Verifies the service-to-service HMAC signature (engine spec §11.4) and,
 * on success, authenticates the request as a {@see ServiceActor} carrying
 * the abilities configured for that service. Requests without a
 * `Signature` authorization header pass straight through so the regular
 * Sanctum token / cookie-session auth that follows can run.
 *
 * Services are configured under `artisanpack.ecommerce.api.services`:
 *
 * ```php
 * 'services' => [
 *     'erp-sync' => [
 *         'secret'    => env( 'ECOMMERCE_SERVICE_ERP_SECRET' ),
 *         'abilities' => [ 'ecommerce:orders.read' ],
 *     ],
 * ],
 * ```
 *
 * A request is rejected (401 problem+json) when the service is unknown,
 * the algorithm or covered headers are wrong, the `Date` is not an RFC 7231
 * date within `api.signature_tolerance_seconds`, the `Digest` does not
 * match the body, the signature does not match, or the same signature was
 * already used. Replay protection uses the cache store named by
 * `api.signature_cache_store` (default: the default store): in a
 * multi-server deployment it must be a shared store (Redis, database,
 * Memcached) so a request replayed to another node is caught. The service
 * provider logs a warning when that store is `array` in production.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Middleware;

use ArtisanPackUI\Ecommerce\Auth\ServiceActor;
use ArtisanPackUI\Ecommerce\Auth\ServiceSignature;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ServiceSignatureMiddleware
{
    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  Closure  $next     Next middleware.
     *
     * @return Response
     */
    public function handle( Request $request, Closure $next ): Response
    {
        $params = ServiceSignature::parse( $request->header( 'Authorization' ) );

        if ( null === $params ) {
            return $next( $request );
        }

        $actor = $this->verify( $request, $params );

        if ( null === $actor ) {
            return Problem::make( 401, 'invalid-service-signature', __( 'Invalid service signature' ), __( 'The service signature could not be verified.' ), $request );
        }

        $this->authenticate( $request, $actor );

        return $next( $request );
    }

    /**
     * The cache store that remembers used signatures.
     *
     * @since 1.0.0
     *
     * @return Repository
     */
    public static function replayStore(): Repository
    {
        $store = config( 'artisanpack.ecommerce.api.signature_cache_store' );

        return Cache::store( is_string( $store ) && '' !== $store ? $store : null );
    }

    /**
     * The driver of {@see self::replayStore()}, if it can be told.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public static function replayStoreDriver(): ?string
    {
        $store  = config( 'artisanpack.ecommerce.api.signature_cache_store' );
        $name   = is_string( $store ) && '' !== $store ? $store : (string) config( 'cache.default' );
        $driver = config( 'cache.stores.' . $name . '.driver' );

        return is_string( $driver ) ? $driver : null;
    }

    /**
     * Verifies the signature, returning the actor on success.
     *
     * @since 1.0.0
     *
     * @param  Request                $request  Request.
     * @param  array<string, string>  $params   Parsed signature parameters.
     *
     * @return ServiceActor|null
     */
    protected function verify( Request $request, array $params ): ?ServiceActor
    {
        $keyId = $params['keyId'] ?? '';

        // The keyId is used in a config path — accept plain names only.
        if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $keyId ) ) {
            return null;
        }

        $service = config( 'artisanpack.ecommerce.api.services.' . $keyId );
        $secret  = is_array( $service ) ? (string) ( $service['secret'] ?? '' ) : '';

        if ( '' === $secret || ServiceSignature::ALGORITHM !== ( $params['algorithm'] ?? '' ) ) {
            return null;
        }

        $covered = preg_split( '/\s+/', strtolower( trim( $params['headers'] ?? '' ) ) ) ?: [];

        if ( [] !== array_diff( ServiceSignature::REQUIRED_HEADERS, $covered ) ) {
            return null;
        }

        if ( ! $this->dateIsFresh( (string) $request->header( 'Date', '' ) ) ) {
            return null;
        }

        if ( ! hash_equals( ServiceSignature::digest( $request->getContent() ), (string) $request->header( 'Digest', '' ) ) ) {
            return null;
        }

        $headers = [];

        foreach ( $covered as $name ) {
            $headers[ $name ] = (string) $request->header( $name, '' );
        }

        $expected = ServiceSignature::compute(
            ServiceSignature::signingString( $covered, $request->getMethod(), $request->getRequestUri(), $headers ),
            $secret,
        );

        if ( ! hash_equals( $expected, $params['signature'] ?? '' ) ) {
            return null;
        }

        // A captured request can't be replayed inside the freshness window.
        $ttl = 2 * $this->tolerance();

        if ( ! self::replayStore()->add( 'ecommerce:service-signature:' . hash( 'sha256', $expected ), true, $ttl ) ) {
            return null;
        }

        return new ServiceActor( $keyId, array_values( array_map( 'strval', (array) ( $service['abilities'] ?? [] ) ) ) );
    }

    /**
     * Whether the `Date` header is within the configured tolerance of now.
     *
     * @since 1.0.0
     *
     * @param  string  $date  Raw `Date` header.
     *
     * @return bool
     */
    protected function dateIsFresh( string $date ): bool
    {
        if ( '' === $date ) {
            return false;
        }

        // Strict RFC 7231 IMF-fixdate only: a lenient parser would accept
        // relative values like "now", which never go stale.
        try {
            $signedAt = Carbon::createFromFormat( '!D, d M Y H:i:s \G\M\T', $date, 'UTC' );
        } catch ( Throwable ) {
            return false;
        }

        if ( ! $signedAt instanceof Carbon || $signedAt->toRfc7231String() !== $date ) {
            return false;
        }

        return abs( Carbon::now()->getTimestamp() - $signedAt->getTimestamp() ) <= $this->tolerance();
    }

    /**
     * Allowed clock skew in seconds.
     *
     * @since 1.0.0
     *
     * @return int
     */
    protected function tolerance(): int
    {
        return max( 1, (int) config( 'artisanpack.ecommerce.api.signature_tolerance_seconds', 300 ) );
    }

    /**
     * Makes the actor the authenticated user for the rest of the request,
     * including the Sanctum guard so a following `auth:sanctum` passes.
     *
     * @since 1.0.0
     *
     * @param  Request       $request  Request.
     * @param  ServiceActor  $actor    Verified actor.
     *
     * @return void
     */
    protected function authenticate( Request $request, ServiceActor $actor ): void
    {
        if ( null !== config( 'auth.guards.sanctum' ) ) {
            Auth::guard( 'sanctum' )->setUser( $actor );
            Auth::shouldUse( 'sanctum' );
        }

        $request->setUserResolver( static fn (): ServiceActor => $actor );
    }
}
