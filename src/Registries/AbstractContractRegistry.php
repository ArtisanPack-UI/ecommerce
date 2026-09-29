<?php

/**
 * AbstractContractRegistry.
 *
 * Shared base for the keyed registries that hold implementations of a
 * single engine contract (engine spec §5). Subclasses declare which
 * contract they hold via {@see self::contract()}; everything else —
 * validation, double-registration policy, lazy container resolution, and
 * key self-reporting checks — lives here.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Registries;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Keyed registry of contract implementations.
 *
 * Registration accepts a class name (resolved from the container on first
 * lookup) or a pre-built instance. Double-registration throws in `local`
 * + `testing`, warns via `Log::warning` in every other environment so a
 * satellite conflict can't take down production traffic (engine spec §5).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @template TContract of object
 */
abstract class AbstractContractRegistry
{
    /**
     * Registered entries keyed by registry key.
     *
     * @since 1.0.0
     *
     * @var array<string, array{entry: class-string<TContract>|TContract, meta: array<string, mixed>}>
     */
    private array $entries = [];

    /**
     * Resolved instance cache — populated lazily on first {@see self::get()}.
     *
     * @since 1.0.0
     *
     * @var array<string, TContract>
     */
    private array $resolved = [];

    /**
     * @since 1.0.0
     *
     * @param  Application  $container  Application used to resolve class-name entries.
     */
    public function __construct( private readonly Application $container )
    {
    }

    /**
     * Registers an implementation under `$key`.
     *
     * @since 1.0.0
     *
     * @param  string                            $key    Registry key (engine spec §2.5).
     * @param  class-string<TContract>|TContract $entry  Class name or instance.
     * @param  array<string, mixed>              $meta   Display metadata (label, icon, …).
     *
     * @throws InvalidArgumentException When `$key` is empty, `$entry` does not implement the contract, or the key collides in `local`/`testing`.
     *
     * @return void
     */
    public function register( string $key, object|string $entry, array $meta = [] ): void
    {
        $contract = $this->contract();
        $name     = $this->contractName();

        if ( '' === trim( $key ) ) {
            throw new InvalidArgumentException( sprintf( '%s registry key must not be empty.', $name ) );
        }

        $implements = is_string( $entry )
            ? class_exists( $entry ) && is_subclass_of( $entry, $contract )
            : $entry instanceof $contract;

        if ( ! $implements ) {
            throw new InvalidArgumentException( sprintf(
                '%s entry "%s" must implement %s.',
                $name,
                is_string( $entry ) ? $entry : $entry::class,
                $contract,
            ) );
        }

        if ( isset( $this->entries[ $key ] ) ) {
            $message = sprintf( '%s "%s" is already registered; the new entry overwrites the previous one.', $name, $key );

            if ( in_array( $this->container->environment(), [ 'local', 'testing' ], true ) ) {
                throw new InvalidArgumentException( $message );
            }

            Log::warning( $message );
        }

        $this->entries[ $key ] = [ 'entry' => $entry, 'meta' => $meta ];
        unset( $this->resolved[ $key ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $key  Registry key.
     *
     * @return bool
     */
    public function has( string $key ): bool
    {
        return isset( $this->entries[ $key ] );
    }

    /**
     * Resolves the implementation registered under `$key`.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Registry key.
     *
     * @throws RuntimeException When nothing is registered under `$key`, or the resolved implementation reports a different key.
     *
     * @return TContract
     */
    public function get( string $key ): object
    {
        if ( ! isset( $this->entries[ $key ] ) ) {
            throw new RuntimeException( sprintf( 'No %s registered under "%s".', $this->contractName(), $key ) );
        }

        if ( isset( $this->resolved[ $key ] ) ) {
            return $this->resolved[ $key ];
        }

        $entry    = $this->entries[ $key ]['entry'];
        $instance = is_string( $entry ) ? $this->container->make( $entry ) : $entry;

        if ( method_exists( $instance, 'key' ) && $instance->key() !== $key ) {
            throw new RuntimeException( sprintf(
                '%s registered under "%s" reports its own key as "%s"; refusing to resolve it.',
                $this->contractName(),
                $key,
                $instance->key(),
            ) );
        }

        return $this->resolved[ $key ] = $instance;
    }

    /**
     * Resolves every registered implementation, keyed by registry key.
     *
     * @since 1.0.0
     *
     * @return array<string, TContract>
     */
    public function all(): array
    {
        $all = [];

        foreach ( $this->keys() as $key ) {
            $all[ $key ] = $this->get( $key );
        }

        return $all;
    }

    /**
     * @since 1.0.0
     *
     * @param  string  $key  Registry key.
     *
     * @return array<string, mixed>
     */
    public function meta( string $key ): array
    {
        return $this->entries[ $key ]['meta'] ?? [];
    }

    /**
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys( $this->entries );
    }

    /**
     * Fully-qualified interface every entry must implement.
     *
     * @since 1.0.0
     *
     * @return class-string<TContract>
     */
    abstract protected function contract(): string;

    /**
     * Short contract name used in exception messages.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function contractName(): string
    {
        $parts = explode( '\\', $this->contract() );

        return (string) end( $parts );
    }
}
