<?php

/**
 * RequestIdMiddleware.
 *
 * Threads a correlation ID through the request lifecycle so every log line,
 * outbound webhook and payment-gateway idempotency key can be tied back to
 * a single inbound HTTP call.
 *
 * Behaviour:
 *
 *  - Reads the incoming `X-Request-Id` header. If present and syntactically
 *    valid, it is used as-is. If missing or invalid, a fresh UUID v4 is
 *    generated.
 *  - Publishes the ID onto {@see RequestContext} so downstream code
 *    (webhook dispatchers, gateway adapters, queued jobs) can retrieve it
 *    without having to accept it as a constructor argument.
 *  - Pushes `{'request_id': <id>}` into the shared Log context so every
 *    subsequent `Log::info()` / `Log::debug()` / `Log::channel('ecommerce')`
 *    call within this request automatically carries it.
 *  - Copies the ID back onto the outbound HTTP response as `X-Request-Id`
 *    so clients can echo it in bug reports.
 *  - Clears the {@see RequestContext} holder on the way out so a long-lived
 *    process (Octane, RoadRunner) does not leak the previous request's ID
 *    into the next request.
 *
 * Engine plan §16.3.
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

use ArtisanPackUI\Ecommerce\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RequestIdMiddleware
{
    /**
     * @since 1.0.0
     */
    public const HEADER = 'X-Request-Id';

    /**
     * Maximum accepted length of an inbound `X-Request-Id`. Values above
     * this bound are treated as invalid and replaced with a generated UUID
     * so a hostile client cannot bloat log lines or downstream webhook
     * headers.
     *
     * @since 1.0.0
     */
    public const MAX_LENGTH = 128;

    /**
     * Runs the request through the correlation-ID pipeline.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The inbound request.
     * @param  Closure  $next     The next middleware in the pipeline.
     *
     * @return Response
     */
    public function handle( Request $request, Closure $next ): Response
    {
        $requestId = $this->resolveRequestId( $request );

        RequestContext::setRequestId( $requestId );
        Log::withContext( [ 'request_id' => $requestId ] );

        $request->headers->set( self::HEADER, $requestId );

        try {
            /** @var Response $response */
            $response = $next( $request );

            $response->headers->set( self::HEADER, $requestId );

            return $response;
        } finally {
            RequestContext::reset();
        }
    }

    /**
     * Returns the correlation ID for this request: the inbound header when
     * present and valid, otherwise a fresh UUID v4.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The inbound request.
     *
     * @return string
     */
    protected function resolveRequestId( Request $request ): string
    {
        $incoming = $request->headers->get( self::HEADER );

        if ( is_string( $incoming ) ) {
            $trimmed = trim( $incoming );

            if ( $this->isAcceptable( $trimmed ) ) {
                return $trimmed;
            }
        }

        return (string) Str::uuid();
    }

    /**
     * Returns true when the supplied value is a syntactically acceptable
     * correlation ID: non-empty, within the length bound, and containing
     * only printable ASCII (letters, digits, and the small set of
     * separators commonly seen in trace/request IDs).
     *
     * @since 1.0.0
     *
     * @param  string  $value  The candidate ID.
     *
     * @return bool
     */
    protected function isAcceptable( string $value ): bool
    {
        if ( '' === $value ) {
            return false;
        }

        if ( strlen( $value ) > self::MAX_LENGTH ) {
            return false;
        }

        return 1 === preg_match( '/^[A-Za-z0-9._\-:]+$/', $value );
    }
}
