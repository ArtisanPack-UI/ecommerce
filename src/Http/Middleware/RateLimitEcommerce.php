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

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use InvalidArgumentException;
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
     * Constructs the middleware.
     *
     * @since 1.0.0
     *
     * @param  RateLimiter  $limiter  Laravel's rate-limiter service.
     */
    public function __construct( protected RateLimiter $limiter )
    {
    }

    /**
     * Handles the incoming request.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     * @param  Closure  $next  The rest of the pipeline.
     * @param  string  $policy  Name of the registered policy (e.g.
     *                          `ecommerce.catalog.read`).
     *
     * @throws InvalidArgumentException When the policy is not registered.
     *
     * @return Response The response, possibly a 429.
     */
    public function handle( Request $request, Closure $next, string $policy ): Response
    {
        $limits = $this->resolveLimits( $policy, $request );

        // Two passes: check every bucket first, then only hit them once we
        // know none refuses. Doing check+hit in one loop would spend a slot
        // from bucket A even when bucket B refuses the same request, giving
        // a caller who is already over the compound cap the ability to
        // erode a second bucket by hitting the endpoint they cannot use.
        // The two passes are not atomic across concurrent requests — a
        // small overshoot under load is possible and accepted — but any
        // "fix" that combines them reintroduces the partial-spend bug.
        foreach ( $limits as $limit ) {
            $key = $this->keyFor( $policy, $limit );

            if ( $this->limiter->tooManyAttempts( $key, $limit->maxAttempts ) ) {
                return $this->refuse( $policy, $key, $limit, $request );
            }
        }

        foreach ( $limits as $limit ) {
            $this->limiter->hit( $this->keyFor( $policy, $limit ), $limit->decaySeconds );
        }

        return $next( $request );
    }

    /**
     * Resolves the registered policy callback to the concrete list of limits
     * that apply to this request.
     *
     * @since 1.0.0
     *
     * @param  string  $policy  The policy name.
     * @param  Request  $request  The incoming request.
     *
     * @throws InvalidArgumentException When the policy has no registered
     *                                  resolver.
     *
     * @return array<int, Limit> The limits to enforce, in policy order.
     */
    protected function resolveLimits( string $policy, Request $request ): array
    {
        $resolver = RateLimiterFacade::limiter( $policy );

        if ( null === $resolver ) {
            throw new InvalidArgumentException( sprintf(
                'Ecommerce rate-limit policy "%s" is not registered.',
                $policy,
            ) );
        }

        $result = $resolver( $request );

        if ( $result instanceof Limit ) {
            return [ $result ];
        }

        if ( is_array( $result ) ) {
            return array_values( array_filter(
                $result,
                static fn ( mixed $item ): bool => $item instanceof Limit,
            ) );
        }

        return [];
    }

    /**
     * Builds the cache key for one limit within a policy.
     *
     * Namespaces the key by policy name so limits with overlapping `by()`
     * subjects (e.g. two policies both keyed on the caller's IP) cannot
     * poison each other's counters.
     *
     * @since 1.0.0
     *
     * @param  string  $policy  The policy name.
     * @param  Limit  $limit  One of the policy's limits.
     *
     * @return string The cache key.
     */
    protected function keyFor( string $policy, Limit $limit ): string
    {
        return $policy . '|' . $limit->key;
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
