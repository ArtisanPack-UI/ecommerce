<?php

/**
 * IdempotencyMiddleware.
 *
 * Enforces idempotent replay for every mutating engine endpoint. Reads the
 * client's `Idempotency-Key` header, resolves the composite key from the
 * authenticated actor + route, and either:
 *
 *  - stores an in-flight lock row and lets the request run (first attempt);
 *  - replays the stored terminal response verbatim (same key + same payload);
 *  - returns 409 `problem+json` (same key, different payload hash);
 *  - waits on the in-flight lock and then replays (concurrent duplicate);
 *  - returns 400 `problem+json` (header missing).
 *
 * Engine spec §7.5 / §11.2.
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

use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class IdempotencyMiddleware
{
    /**
     * @since 1.0.0
     */
    public const HEADER = 'Idempotency-Key';

    /**
     * @since 1.0.0
     */
    public const REPLAY_HEADER = 'Idempotent-Replay';

    /**
     * @since 1.0.0
     */
    public const PROBLEM_CONTENT_TYPE = 'application/problem+json';

    /**
     * Runs the incoming request through the idempotency machinery.
     *
     * @since 1.0.0
     *
     * @param  Request  $request
     * @param  Closure  $next
     *
     * @return Response
     */
    public function handle( Request $request, Closure $next ): Response
    {
        $key = trim( (string) $request->header( self::HEADER, '' ) );
        if ( '' === $key ) {
            return $this->missingHeaderResponse( $request );
        }

        $actorScope  = $this->resolveActorScope( $request );
        $endpointKey = $this->resolveEndpointKey( $request );
        $requestHash = $this->hashRequest( $request );
        $ttl         = $this->resolveTtl( $endpointKey );

        $existing = IdempotencyRecord::query()
            ->where( 'actor_scope', $actorScope )
            ->where( 'endpoint_key', $endpointKey )
            ->where( 'idempotency_key', $key )
            ->first();

        if ( null !== $existing ) {
            if ( $existing->request_hash !== $requestHash ) {
                return $this->conflictResponse( $request );
            }

            if ( null === $existing->response_status ) {
                $existing = $this->waitForInFlight( $existing );
                if ( null === $existing || null === $existing->response_status ) {
                    return $this->conflictResponse( $request );
                }
            }

            return $this->replay( $existing );
        }

        try {
            $record = IdempotencyRecord::query()->create( [
                'actor_scope'     => $actorScope,
                'endpoint_key'    => $endpointKey,
                'idempotency_key' => $key,
                'request_hash'    => $requestHash,
                'locked_at'       => Carbon::now(),
                'expires_at'      => Carbon::now()->addHours( $ttl ),
            ] );
        } catch ( QueryException $e ) {
            // Concurrent insert lost the race — treat as an existing record.
            $record = IdempotencyRecord::query()
                ->where( 'actor_scope', $actorScope )
                ->where( 'endpoint_key', $endpointKey )
                ->where( 'idempotency_key', $key )
                ->first();

            if ( null === $record ) {
                throw $e;
            }

            if ( $record->request_hash !== $requestHash ) {
                return $this->conflictResponse( $request );
            }

            if ( null === $record->response_status ) {
                $record = $this->waitForInFlight( $record );
                if ( null === $record || null === $record->response_status ) {
                    return $this->conflictResponse( $request );
                }
            }

            return $this->replay( $record );
        }

        /** @var Response $response */
        $response = $next( $request );

        $this->persistResponse( $record, $response );

        return $response;
    }

    /**
     * Resolves the `actor_scope` composite-key component from the request.
     *
     * @since 1.0.0
     *
     * @param  Request  $request
     *
     * @return string
     */
    protected function resolveActorScope( Request $request ): string
    {
        $user = $request->user();
        if ( null !== $user && method_exists( $user, 'currentAccessToken' ) ) {
            $sanctumToken = $user->currentAccessToken();
            if ( null !== $sanctumToken && isset( $sanctumToken->id ) ) {
                return 'sanctum-token:' . $sanctumToken->id;
            }
        }

        if ( null !== $user ) {
            return 'user:' . $user->getAuthIdentifier();
        }

        if ( $request->hasSession() ) {
            return 'session:' . $request->session()->getId();
        }

        return 'ip:' . $request->ip();
    }

    /**
     * Resolves the `endpoint_key` composite-key component from the request.
     *
     * @since 1.0.0
     *
     * @param  Request  $request
     *
     * @return string
     */
    protected function resolveEndpointKey( Request $request ): string
    {
        $route = $request->route();
        $name  = is_object( $route ) && method_exists( $route, 'getName' ) ? $route->getName() : null;

        if ( is_string( $name ) && '' !== $name ) {
            return $name;
        }

        $path = '/' . ltrim( $request->path(), '/' );
        if ( is_object( $route ) && method_exists( $route, 'parameterNames' ) ) {
            foreach ( $route->parameterNames() as $param ) {
                $value = $route->parameter( $param );
                if ( is_scalar( $value ) ) {
                    $path = str_replace( (string) $value, '{' . $param . '}', $path );
                }
            }
        }

        return strtoupper( $request->method() ) . ':' . $path;
    }

    /**
     * Canonical sha256 hash of the request payload (body + query string).
     *
     * @since 1.0.0
     *
     * @param  Request  $request
     *
     * @return string
     */
    protected function hashRequest( Request $request ): string
    {
        $payload = [
            'body'  => $this->canonicalize( $request->all() ),
            'query' => $this->canonicalize( $request->query() ),
        ];

        try {
            $encoded = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch ( JsonException $e ) {
            // Payload contains bytes that can't be canonicalized; hash the
            // raw request body so encode-failing requests still get a
            // distinct, content-derived key.
            $encoded = 'raw:' . $request->getContent();
        }

        return hash( 'sha256', $encoded );
    }

    /**
     * Recursively ksorts arrays so key order does not affect the hash.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value
     *
     * @return mixed
     */
    protected function canonicalize( $value )
    {
        if ( is_array( $value ) ) {
            $sorted = [];
            foreach ( $value as $k => $v ) {
                $sorted[ $k ] = $this->canonicalize( $v );
            }
            ksort( $sorted );
            return $sorted;
        }

        return $value;
    }

    /**
     * Resolves the TTL (in hours) for a given endpoint key.
     *
     * @since 1.0.0
     *
     * @param  string  $endpointKey
     *
     * @return int
     */
    protected function resolveTtl( string $endpointKey ): int
    {
        $config  = (array) config( 'artisanpack.ecommerce.idempotency', [] );
        $default = (int) ( $config['default_ttl_hours'] ?? 24 );
        $ttls    = (array) ( $config['ttls'] ?? [] );

        if ( array_key_exists( $endpointKey, $ttls ) ) {
            return (int) $ttls[ $endpointKey ];
        }

        return $default;
    }

    /**
     * Polls the in-flight row until `response_status` is populated or the
     * configured wait window elapses.
     *
     * @since 1.0.0
     *
     * @param  IdempotencyRecord  $record
     *
     * @return IdempotencyRecord|null
     */
    protected function waitForInFlight( IdempotencyRecord $record ): ?IdempotencyRecord
    {
        $config   = (array) config( 'artisanpack.ecommerce.idempotency', [] );
        $waitMs   = (int) ( $config['wait_ms'] ?? 8_000 );
        $pollMs   = max( 10, (int) ( $config['poll_ms'] ?? 100 ) );
        $waitedMs = 0;

        while ( $waitedMs < $waitMs ) {
            $fresh = IdempotencyRecord::query()
                ->where( 'id', $record->id )
                ->first();

            if ( null === $fresh ) {
                return null;
            }

            if ( null !== $fresh->response_status ) {
                return $fresh;
            }

            usleep( $pollMs * 1_000 );
            $waitedMs += $pollMs;
        }

        return null;
    }

    /**
     * Writes the terminal response back onto the idempotency row.
     *
     * @since 1.0.0
     *
     * @param  IdempotencyRecord  $record
     * @param  Response           $response
     *
     * @return void
     */
    protected function persistResponse( IdempotencyRecord $record, Response $response ): void
    {
        $headers = [];
        foreach ( $response->headers->all() as $name => $values ) {
            // Never persist Set-Cookie — session cookies would leak into
            // the record and could be replayed against another actor.
            if ( 'set-cookie' === strtolower( (string) $name ) ) {
                continue;
            }
            $headers[ $name ] = $values;
        }

        // Use raw DB update so append-only-adjacent event guards on other models
        // stay out of the way and no observers rewrite the row.
        DB::table( 'idempotency_records' )
            ->where( 'id', $record->id )
            ->update( [
                'response_status'  => $response->getStatusCode(),
                'response_headers' => json_encode( $headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                'response_body'    => $response->getContent(),
                'locked_at'        => null,
                'updated_at'       => Carbon::now(),
            ] );
    }

    /**
     * Rebuilds the stored terminal response for a replay.
     *
     * @since 1.0.0
     *
     * @param  IdempotencyRecord  $record
     *
     * @return Response
     */
    protected function replay( IdempotencyRecord $record ): Response
    {
        $response = new Response(
            (string) ( $record->response_body ?? '' ),
            (int) ( $record->response_status ?? 200 ),
        );

        $stored = (array) ( $record->response_headers ?? [] );
        foreach ( $stored as $name => $values ) {
            // Skip framework-managed transport headers, and Set-Cookie —
            // replaying a stale session cookie hours later would clobber
            // the client's current cookie jar.
            if ( in_array( strtolower( (string) $name ), [ 'transfer-encoding', 'content-length', 'date', 'set-cookie' ], true ) ) {
                continue;
            }
            $response->headers->set( $name, $values );
        }

        $response->headers->set( self::REPLAY_HEADER, 'true' );

        return $response;
    }

    /**
     * Builds the 400 `problem+json` for a missing header.
     *
     * @since 1.0.0
     *
     * @param  Request  $request
     *
     * @return JsonResponse
     */
    protected function missingHeaderResponse( Request $request ): JsonResponse
    {
        return $this->problem(
            400,
            'missing-idempotency-key',
            'Idempotency-Key header is required',
            'This endpoint requires an Idempotency-Key request header.',
            $request,
        );
    }

    /**
     * Builds the 409 `problem+json` for a key conflict.
     *
     * @since 1.0.0
     *
     * @param  Request  $request
     *
     * @return JsonResponse
     */
    protected function conflictResponse( Request $request ): JsonResponse
    {
        return $this->problem(
            409,
            'idempotency-key-conflict',
            'Idempotency key conflict',
            'The Idempotency-Key was reused with a different request payload.',
            $request,
        );
    }

    /**
     * Assembles a problem+json response.
     *
     * @since 1.0.0
     *
     * @param  int      $status
     * @param  string   $slug
     * @param  string   $title
     * @param  string   $detail
     * @param  Request  $request
     *
     * @return JsonResponse
     */
    protected function problem( int $status, string $slug, string $title, string $detail, Request $request ): JsonResponse
    {
        $base = rtrim(
            (string) config( 'artisanpack.ecommerce.idempotency.problem_base_url', 'https://docs.artisanpack-ui.dev/ecommerce/problems' ),
            '/',
        );

        return new JsonResponse(
            [
                'type'     => $base . '/' . $slug,
                'title'    => $title,
                'status'   => $status,
                'detail'   => $detail,
                'instance' => '/' . ltrim( $request->path(), '/' ),
            ],
            $status,
            [ 'Content-Type' => self::PROBLEM_CONTENT_TYPE ],
        );
    }
}
