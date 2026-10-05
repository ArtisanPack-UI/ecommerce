<?php

/**
 * Ecommerce rate-limit middleware.
 *
 * Applies one of the named `RateLimiter` policies registered by
 * {@see \ArtisanPackUI\Ecommerce\Support\RateLimitPolicyRegistrar} to the
 * request. On rejection the response is problem+json per engine spec §11.3
 * with `retry_after` in the body and a `Retry-After` header, and a
 * structured warning is emitted so operators can spot abuse patterns
 * without decoding each 429 by hand.
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

use ArtisanPackUI\Ecommerce\RateLimiting\EcommerceRateLimiter;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies a named ecommerce rate-limit policy.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RateLimitEcommerce
{
    /**
     * Problem+json content type used on 429 responses.
     *
     * @since 1.0.0
     */
    public const PROBLEM_CONTENT_TYPE = 'application/problem+json';

    /**
     * @since 1.0.0
     *
     * @param  RateLimiter           $limiter   Laravel's rate limiter.
     * @param  EcommerceRateLimiter  $policies  Policy evaluation shared with in-process callers.
     */
    public function __construct(
        protected RateLimiter $limiter,
        protected EcommerceRateLimiter $policies,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  Closure  $next     Next middleware.
     * @param  string   $policy   Policy name.
     *
     * @return Response
     */
    public function handle( Request $request, Closure $next, string $policy ): Response
    {
        $exceeded = $this->policies->exceeded( $policy, $request );

        if ( null !== $exceeded ) {
            return $this->refuse( $policy, $exceeded['key'], $exceeded['limit'], $request );
        }

        $this->policies->hit( $policy, $request );

        return $next( $request );
    }

    /**
     * Builds the 429 response and logs a structured entry.
     *
     * @since 1.0.0
     *
     * @param  string  $policy  The policy that refused the request.
     * @param  string  $key  The cache key for the exceeded limit.
     * @param  Limit  $limit  The exceeded limit.
     * @param  Request  $request  The incoming request.
     *
     * @return JsonResponse The 429 problem+json response.
     */
    protected function refuse( string $policy, string $key, Limit $limit, Request $request ): JsonResponse
    {
        $retryAfter = $this->limiter->availableIn( $key );

        // The raw request path can carry secrets — `ecommerce.license.validate`
        // takes the license key as a route segment, and any future
        // token-bearing route would too — so the log line records the route
        // *name* (never PII) plus the URI *template* (`{license_key}` stays
        // a placeholder) instead of the concrete path.
        $route = $request->route();

        Log::warning( 'ecommerce.rate_limit.exceeded', [
            'policy'      => $policy,
            'limit'       => $limit->maxAttempts,
            'decay'       => $limit->decaySeconds,
            'retry_after' => $retryAfter,
            'method'      => $request->getMethod(),
            'route'       => null !== $route ? $route->getName() : null,
            'uri'         => null !== $route ? '/' . ltrim( $route->uri(), '/' ) : null,
            'ip_hash'     => sha1( (string) $request->ip() ),
        ] );

        $base = rtrim(
            (string) config(
                'artisanpack.ecommerce.rate_limits.problem_base_url',
                'https://docs.artisanpack-ui.dev/ecommerce/problems',
            ),
            '/',
        );

        return new JsonResponse(
            [
                'type'        => $base . '/rate-limited',
                'title'       => __( 'Too many requests' ),
                'status'      => JsonResponse::HTTP_TOO_MANY_REQUESTS,
                'detail'      => __( 'You have exceeded the ":policy" rate limit. Retry after :seconds seconds.', [
                    'policy'  => $policy,
                    'seconds' => (string) $retryAfter,
                ] ),
                'instance'    => '/' . ltrim( $request->path(), '/' ),
                'policy'      => $policy,
                'retry_after' => $retryAfter,
            ],
            JsonResponse::HTTP_TOO_MANY_REQUESTS,
            [
                'Content-Type'          => self::PROBLEM_CONTENT_TYPE,
                'Retry-After'           => (string) $retryAfter,
                'X-RateLimit-Limit'     => (string) $limit->maxAttempts,
                'X-RateLimit-Remaining' => '0',
            ],
        );
    }
}
