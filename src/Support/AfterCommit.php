<?php

/**
 * AfterCommit.
 *
 * Runs side effects (lifecycle actions, notifications) once the surrounding
 * database transaction commits, and immediately when there is none. A
 * change that rolls back therefore never announces itself, and a listener
 * that throws after the change committed is reported and logged instead of
 * failing a write that already happened.
 *
 * Engine domain events implement `ShouldDispatchAfterCommit` for the same
 * reason; this covers the `ap.ecommerce.*` actions fired next to them.
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

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class AfterCommit
{
    /**
     * Fires an `ap.ecommerce.*` action after the transaction commits.
     *
     * @since 1.0.0
     *
     * @param  string  $hook     Action name.
     * @param  mixed   ...$args  Action arguments.
     *
     * @return void
     */
    public static function action( string $hook, mixed ...$args ): void
    {
        self::run( $hook, static fn () => doAction( $hook, ...$args ) );
    }

    /**
     * Runs `$callback` after the transaction commits, logging (not
     * rethrowing) anything it throws.
     *
     * @since 1.0.0
     *
     * @param  string   $label     What runs, for the log.
     * @param  Closure  $callback  Side effect.
     *
     * @return void
     */
    public static function run( string $label, Closure $callback ): void
    {
        DB::afterCommit( static function () use ( $label, $callback ): void {
            try {
                $callback();
            } catch ( Throwable $exception ) {
                report( $exception );

                Log::channel( 'ecommerce' )->error( 'A listener failed after the change committed; the change stands.', [
                    'hook'      => $label,
                    'exception' => $exception::class,
                    'message'   => $exception->getMessage(),
                ] );
            }
        } );
    }
}
