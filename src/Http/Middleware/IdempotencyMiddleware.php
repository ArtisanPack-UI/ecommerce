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

use ArtisanPackUI\Ecommerce\Auth\ServiceActor;
use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
     * Maximum allowed length of the `Idempotency-Key` header value. Matches
     * the `idempotency_records.idempotency_key` VARCHAR(255) column so a
     * too-long key is rejected before it can trigger a strict-mode insert
     * error.
     *
     * @since 1.0.0
     */
    public const MAX_KEY_LENGTH = 255;

    /**
     * Mode for endpoints that serve reads and writes alike (GraphQL): the
     * header is honoured when sent but not required.
     *
     * @since 1.0.0
     */
    public const MODE_OPTIONAL = 'optional';

    /**
     * Request attribute listing JSON keys to strip from the stored copy of
     * the response (at any depth), for responses that reveal a secret
     * exactly once — e.g. a new webhook subscription's signing secret. A
     * replay then returns the response without those keys.
     *
     * @since 1.0.0
     */
    public const REDACT_ATTRIBUTE = 'ecommerce.idempotency.redact';

    /**
     * {@see self::REDACT_ATTRIBUTE} value that withholds the whole body —
     * for responses whose keys the client controls (GraphQL aliases), where
     * stripping by key name can't be relied on.
     *
     * @since 1.0.0
     */
    public const REDACT_ALL = '*';

    /**
     * Runs the incoming request through the idempotency machinery.
     *
     * @since 1.0.0
     *
     * @param  Request      $request
     * @param  Closure      $next
     * @param  string|null  $mode     `optional` to let requests without the header through
     *                                (`->middleware('ecommerce.idempotency:optional')`).
     *
     * @return Response
     */
    public function handle( Request $request, Closure $next, ?string $mode = null ): Response
    {
        $key = trim( (string) $request->header( self::HEADER, '' ) );
        if ( '' === $key ) {
            return self::MODE_OPTIONAL === $mode ? $next( $request ) : $this->missingHeaderResponse( $request );
        }
        if ( strlen( $key ) > self::MAX_KEY_LENGTH ) {
            return $this->oversizedKeyResponse( $request );
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

        $this->persistResponse( $record, $response, $request );

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
        if ( $user instanceof ServiceActor ) {
            return 'service:' . $user->name;
        }

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
     * Canonical sha256 hash of the request payload (body + query string +
     * route parameters).
     *
     * Route parameters are part of the hash because the endpoint key is the
     * route *name*: without them `PATCH orders/1` and `PATCH orders/2`
     * sharing an Idempotency-Key would replay order 1's response for
     * order 2 and silently drop the second write. With them, the reuse is
     * a payload divergence and returns 409.
     *
     * @since 1.0.0
     *
     * @param  Request  $request
     *
     * @return string
     */
    protected function hashRequest( Request $request ): string
    {
        // Split scalar/array input from uploaded files so files hash by
        // content, not by their empty json_encode() serialization.
        $files = $this->canonicalizeFiles( $request->allFiles() );
        $body  = $this->canonicalize( $this->stripUploadedFiles( $request->all() ) );

        // For content types Laravel doesn't parse (text/plain,
        // application/octet-stream, ...) `all()` returns []. Fall back
        // to the raw body hash so two distinct raw payloads never
        // collide. When parsed body is present, keep it out of the
        // payload — including it would defeat key-order canonicalization
        // for equivalent JSON documents that happen to serialize
        // differently.
        $route   = $request->route();
        $payload = [
            'body'   => $body,
            'query'  => $this->canonicalize( $request->query() ),
            'files'  => $files,
            'params' => is_object( $route ) && method_exists( $route, 'originalParameters' )
                ? $this->canonicalize( $route->originalParameters() )
                : [],
        ];
        if ( [] === $body && [] === $files ) {
            $payload['raw'] = hash( 'sha256', (string) $request->getContent() );
        }

        try {
            $encoded = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch ( JsonException $e ) {
            // Canonicalized payload still contained unencodable bytes;
            // fall back to the raw-content hash so the request is at
            // least distinguishable from other raw payloads.
            $encoded = 'raw:' . hash( 'sha256', (string) $request->getContent() );
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
     * Recursively drops UploadedFile instances from the parsed input array
     * so the body branch of the hash payload contains only scalar data.
     *
     * @since 1.0.0
     *
     * @param  array<mixed>  $input
     *
     * @return array<mixed>
     */
    protected function stripUploadedFiles( array $input ): array
    {
        $clean = [];
        foreach ( $input as $k => $v ) {
            if ( $v instanceof UploadedFile ) {
                continue;
            }
            if ( is_array( $v ) ) {
                $stripped = $this->stripUploadedFiles( $v );
                if ( [] === $stripped && $this->arrayContainsOnlyFiles( $v ) ) {
                    continue;
                }
                $clean[ $k ] = $stripped;
                continue;
            }
            $clean[ $k ] = $v;
        }
        return $clean;
    }

    /**
     * Determines whether every leaf inside a nested array is an
     * UploadedFile — used to drop `photos => [file, file]` cleanly from
     * the body branch.
     *
     * @since 1.0.0
     *
     * @param  array<mixed>  $input
     *
     * @return bool
     */
    protected function arrayContainsOnlyFiles( array $input ): bool
    {
        foreach ( $input as $v ) {
            if ( $v instanceof UploadedFile ) {
                continue;
            }
            if ( is_array( $v ) && $this->arrayContainsOnlyFiles( $v ) ) {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * Turns an `allFiles()` tree into a stable, content-hashed structure.
     * Each UploadedFile is replaced with `{name, mime, size, sha256}` so
     * two different uploads never hash the same.
     *
     * @since 1.0.0
     *
     * @param  array<mixed>  $files
     *
     * @return array<mixed>
     */
    protected function canonicalizeFiles( array $files ): array
    {
        $out = [];
        foreach ( $files as $k => $v ) {
            if ( $v instanceof UploadedFile ) {
                $path      = $v->getRealPath();
                $out[ $k ] = [
                    'name'   => $v->getClientOriginalName(),
                    'mime'   => $v->getClientMimeType(),
                    'size'   => $v->getSize(),
                    'sha256' => ( false !== $path && is_readable( $path ) )
                        ? hash_file( 'sha256', $path )
                        : null,
                ];
                continue;
            }
            if ( is_array( $v ) ) {
                $out[ $k ] = $this->canonicalizeFiles( $v );
                continue;
            }
            $out[ $k ] = $v;
        }
        ksort( $out );
        return $out;
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
    protected function persistResponse( IdempotencyRecord $record, Response $response, ?Request $request = null ): void
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
                'response_body'    => $this->redact( (string) $response->getContent(), (array) ( $request?->attributes->get( self::REDACT_ATTRIBUTE ) ?? [] ) ),
                'locked_at'        => null,
                'updated_at'       => Carbon::now(),
            ] );
    }

    /**
     * Removes `$keys` (at any depth) from a JSON body before it is stored.
     * Non-JSON bodies are stored unchanged.
     *
     * @since 1.0.0
     *
     * @param  string              $body  Response body.
     * @param  array<int, string>  $keys  Keys to strip.
     *
     * @return string
     */
    protected function redact( string $body, array $keys ): string
    {
        if ( [] === $keys ) {
            return $body;
        }

        if ( in_array( self::REDACT_ALL, $keys, true ) ) {
            return (string) json_encode( [
                'errors' => [ [
                    'message'    => 'This request already completed. Its response contained a one-time secret, so it is not replayed.',
                    'extensions' => [ 'code' => 'IDEMPOTENT_REPLAY_WITHHELD' ],
                ] ],
            ] );
        }

        $decoded = json_decode( $body, true );

        if ( ! is_array( $decoded ) ) {
            return $body;
        }

        $strip = static function ( array $value ) use ( &$strip, $keys ): array {
            foreach ( $value as $key => $item ) {
                if ( in_array( $key, $keys, true ) ) {
                    unset( $value[ $key ] );
                } elseif ( is_array( $item ) ) {
                    $value[ $key ] = $strip( $item );
                }
            }

            return $value;
        };

        return (string) json_encode( $strip( $decoded ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
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
     * Builds the 400 `problem+json` for an Idempotency-Key value that
     * exceeds the storage column length.
     *
     * @since 1.0.0
     *
     * @param  Request  $request
     *
     * @return JsonResponse
     */
    protected function oversizedKeyResponse( Request $request ): JsonResponse
    {
        return $this->problem(
            400,
            'oversized-idempotency-key',
            'Idempotency-Key is too long',
            sprintf(
                'Idempotency-Key must be %d characters or fewer.',
                self::MAX_KEY_LENGTH,
            ),
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
