<?php

/**
 * Ecommerce Sentry integration provider.
 *
 * Attaches every {@see EcommerceException::context()} payload to the Sentry
 * event it is reported with, under the `ecommerce` context key. Parent plan
 * §16.3.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Providers;

use ArtisanPackUI\Ecommerce\Exceptions\EcommerceException;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;
use Sentry\State\Scope;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Optional provider that wires engine exception context into Sentry.
 *
 * {@see EcommerceServiceProvider} only registers this provider when
 * `sentry/sentry-laravel` is installed (runtime detection via
 * {@see self::sentryInstalled()}), so the engine carries no hard
 * dependency on Sentry. The context is attached through a global event
 * processor rather than a `reportable()` callback, so it lands on the
 * event no matter which order the host app wires Sentry's exception
 * handling in.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class EcommerceSentryIntegration extends ServiceProvider
{
    /**
     * Sentry context key the engine's payload is stored under.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CONTEXT_KEY = 'ecommerce';

    /**
     * Whether the global event processor has already been added. Sentry's
     * processor list is process-global, so re-booting the application (as
     * tests and Octane workers do) must not stack duplicate processors.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected static bool $processorRegistered = false;

    /**
     * Adds the event processor that copies engine exception context onto
     * the Sentry event.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function boot(): void
    {
        if ( static::$processorRegistered || ! static::sentryInstalled() ) {
            return;
        }

        static::$processorRegistered = true;

        Scope::addGlobalEventProcessor( static function ( Event $event, EventHint $hint ): Event {
            $context = static::contextFor( $hint->exception );

            if ( null !== $context ) {
                $event->setContext( static::CONTEXT_KEY, $context );
            }

            return $event;
        } );
    }

    /**
     * Whether `sentry/sentry-laravel` (and the Sentry PHP SDK it pulls in)
     * is installed.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public static function sentryInstalled(): bool
    {
        return class_exists( SentryServiceProvider::class ) && class_exists( Scope::class );
    }

    /**
     * Returns the Sentry-safe context for the first {@see EcommerceException}
     * in `$exception`'s previous-chain, or null when there is none. Engine
     * exceptions are often wrapped (queue jobs, HTTP exceptions), so the
     * whole chain is searched rather than just the outermost throwable.
     *
     * @since 1.0.0
     *
     * @param  Throwable|null  $exception  Exception attached to the Sentry event.
     *
     * @return array<string, mixed>|null
     */
    public static function contextFor( ?Throwable $exception ): ?array
    {
        for ( $current = $exception; null !== $current; $current = $current->getPrevious() ) {
            if ( $current instanceof EcommerceException ) {
                return array_merge(
                    [ 'exception' => $current::class ],
                    static::normalize( $current->context() ),
                );
            }
        }

        return null;
    }

    /**
     * Resets the processor guard. Test-only.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function flushState(): void
    {
        static::$processorRegistered = false;
    }

    /**
     * Normalizes a context array into scalars and nested arrays so Sentry's
     * serializer never has to walk arbitrary objects (closures, services).
     * Models collapse to `Class#key` — their `__toString()` would otherwise
     * ship every attribute (customer PII included) to Sentry.
     *
     * @since 1.0.0
     *
     * @param  array<array-key, mixed>  $context  Raw exception context.
     *
     * @return array<array-key, mixed>
     */
    protected static function normalize( array $context ): array
    {
        $normalized = [];

        foreach ( $context as $key => $value ) {
            $normalized[ $key ] = match ( true ) {
                null === $value, is_scalar( $value ) => $value,
                is_array( $value )                   => static::normalize( $value ),
                $value instanceof BackedEnum         => $value->value,
                $value instanceof UnitEnum           => $value->name,
                $value instanceof DateTimeInterface  => $value->format( DATE_ATOM ),
                $value instanceof Model              => $value::class . '#' . $value->getKey(),
                $value instanceof Stringable         => (string) $value,
                is_object( $value )                  => $value::class,
                default                              => get_debug_type( $value ),
            };
        }

        return $normalized;
    }
}
