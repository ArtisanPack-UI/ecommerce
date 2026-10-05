<?php

/**
 * IdempotentAction.
 *
 * Idempotency for in-process, money-moving calls (engine issue #180) — the
 * counterpart of {@see \ArtisanPackUI\Ecommerce\Http\Middleware\IdempotencyMiddleware}
 * for callers that never go through a route, such as a Livewire checkout:
 *
 * ```php
 * $order = app( IdempotentAction::class )->run(
 *     'checkout.finalize:cart:' . $cart->id,
 *     $actionToken,                       // stable per click / attempt
 *     fn () => $checkout->finalize( $cart, $reference ),
 * );
 * ```
 *
 * The first call with a scope and key runs the callback and stores its
 * result in `idempotency_records`; a repeat returns the stored result
 * without running it again. A repeat while the first is still running waits
 * (`idempotency.wait_ms`) and then replays, or throws
 * {@see IdempotencyConflictException}. A call that throws is not stored, so
 * it can be retried with the same key. Passing a `$fingerprint` (a hash of
 * the call's inputs) makes reusing a key for different inputs a conflict.
 *
 * Results may be null, scalars, arrays, Eloquent models or collections, or a
 * {@see ReplayableResult}. Models are stored as references and re-fetched on
 * replay, so a replay returns current data.
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

use ArtisanPackUI\Ecommerce\Contracts\ReplayableResult;
use ArtisanPackUI\Ecommerce\Exceptions\IdempotencyConflictException;
use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use JsonSerializable;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class IdempotentAction
{
    /**
     * `actor_scope` of in-process records.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ACTOR_SCOPE = 'in-process';

    /**
     * Runs `$callback` once per `$scope` + `$key`.
     *
     * @since 1.0.0
     *
     * @template T
     *
     * @param  string         $scope        What the call is (and whose), e.g. `checkout.finalize:cart:12`.
     * @param  string         $key          Idempotency key, stable across retries of one attempt.
     * @param  callable(): T  $callback     The call.
     * @param  string|null    $fingerprint  Hash of the call's inputs.
     *
     * @throws IdempotencyConflictException When the key was used for other inputs or is still in flight.
     * @throws InvalidArgumentException     When the scope or key is empty or too long.
     *
     * @return T
     */
    public function run( string $scope, string $key, callable $callback, ?string $fingerprint = null ): mixed
    {
        $scope = trim( $scope );
        $key   = trim( $key );

        if ( '' === $scope || '' === $key || strlen( $scope ) > 191 || strlen( $key ) > 255 ) {
            throw new InvalidArgumentException( 'An idempotent action needs a scope (at most 191 characters) and a key (at most 255).' );
        }

        $hash     = hash( 'sha256', $fingerprint ?? '' );
        $existing = $this->find( $scope, $key );

        if ( null !== $existing ) {
            return $this->replay( $existing, $hash );
        }

        try {
            $record = IdempotencyRecord::query()->create( [
                'actor_scope'     => self::ACTOR_SCOPE,
                'endpoint_key'    => $scope,
                'idempotency_key' => $key,
                'request_hash'    => $hash,
                'locked_at'       => Carbon::now(),
                'expires_at'      => Carbon::now()->addHours( max( 1, (int) config( 'artisanpack.ecommerce.idempotency.default_ttl_hours', 24 ) ) ),
            ] );
        } catch ( QueryException $exception ) {
            // Lost the race to a concurrent call with the same key.
            $existing = $this->find( $scope, $key ) ?? throw $exception;

            return $this->replay( $existing, $hash );
        }

        try {
            $result = $callback();
        } catch ( Throwable $exception ) {
            IdempotencyRecord::query()->whereKey( $record->getKey() )->delete();

            throw $exception;
        }

        IdempotencyRecord::query()->whereKey( $record->getKey() )->update( [
            'response_status' => 200,
            'response_body'   => json_encode( $this->encode( $result ), JSON_THROW_ON_ERROR ),
            'locked_at'       => null,
            'updated_at'      => Carbon::now(),
        ] );

        return $result;
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $scope  Scope.
     * @param  string  $key    Key.
     *
     * @return IdempotencyRecord|null
     */
    protected function find( string $scope, string $key ): ?IdempotencyRecord
    {
        return IdempotencyRecord::query()
            ->where( 'actor_scope', self::ACTOR_SCOPE )
            ->where( 'endpoint_key', $scope )
            ->where( 'idempotency_key', $key )
            ->first();
    }

    /**
     * Returns a stored result, waiting for an in-flight call to finish.
     *
     * @since 1.0.0
     *
     * @param  IdempotencyRecord  $record  Record.
     * @param  string             $hash    Fingerprint hash of this call.
     *
     * @throws IdempotencyConflictException When the inputs differ or the first call doesn't finish in time.
     *
     * @return mixed
     */
    protected function replay( IdempotencyRecord $record, string $hash ): mixed
    {
        if ( ! hash_equals( (string) $record->request_hash, $hash ) ) {
            throw new IdempotencyConflictException( __( 'This idempotency key was already used for a different request.' ) );
        }

        $waitMs = max( 0, (int) config( 'artisanpack.ecommerce.idempotency.wait_ms', 8_000 ) );
        $pollMs = max( 10, (int) config( 'artisanpack.ecommerce.idempotency.poll_ms', 100 ) );
        $waited = 0;

        while ( null === $record->response_status ) {
            if ( $waited >= $waitMs ) {
                throw new IdempotencyConflictException( __( 'A request with this idempotency key is still being processed.' ) );
            }

            usleep( $pollMs * 1_000 );
            $waited += $pollMs;

            $record = IdempotencyRecord::query()->find( $record->getKey() )
                ?? throw new IdempotencyConflictException( __( 'A request with this idempotency key is still being processed.' ) );
        }

        return $this->decode( json_decode( (string) $record->response_body, true, 512, JSON_THROW_ON_ERROR ) );
    }

    /**
     * Encodes a result for storage.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Result.
     *
     * @throws InvalidArgumentException When the value can't be stored.
     *
     * @return mixed
     */
    protected function encode( mixed $value ): mixed
    {
        return match ( true ) {
            null === $value || is_scalar( $value ) => $value,
            $value instanceof Model                => [ '__model' => $value::class, 'id' => $value->getKey() ],
            $value instanceof EloquentCollection   => [ '__collection' => array_map( fn ( mixed $item ): mixed => $this->encode( $item ), $value->all() ) ],
            $value instanceof ReplayableResult     => [ '__replayable' => $value::class, 'data' => $this->encode( $value->toReplay() ) ],
            is_array( $value )                     => [ '__array' => array_map( fn ( mixed $item ): mixed => $this->encode( $item ), $value ) ],
            // Dates and other JSON values replay in their serialized form.
            $value instanceof DateTimeInterface    => Carbon::instance( $value )->toIso8601String(),
            $value instanceof JsonSerializable     => $this->encode( $value->jsonSerialize() ),
            default                                => throw new InvalidArgumentException( sprintf( 'An idempotent action cannot store a %s result.', get_debug_type( $value ) ) ),
        };
    }

    /**
     * Decodes a stored result, re-fetching models.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Stored value.
     *
     * @return mixed
     */
    protected function decode( mixed $value ): mixed
    {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        if ( isset( $value['__model'] ) ) {
            $class = (string) $value['__model'];

            return is_subclass_of( $class, Model::class ) ? $class::query()->find( $value['id'] ) : null;
        }

        if ( isset( $value['__collection'] ) ) {
            return new EloquentCollection( array_values( array_filter( array_map( fn ( mixed $item ): mixed => $this->decode( $item ), (array) $value['__collection'] ) ) ) );
        }

        if ( isset( $value['__replayable'] ) ) {
            $class = (string) $value['__replayable'];

            return is_subclass_of( $class, ReplayableResult::class ) ? $class::fromReplay( (array) $this->decode( $value['data'] ) ) : null;
        }

        return array_map( fn ( mixed $item ): mixed => $this->decode( $item ), (array) ( $value['__array'] ?? $value ) );
    }
}
