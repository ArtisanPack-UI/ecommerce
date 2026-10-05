<?php

/**
 * EngineSchedule.
 *
 * The engine's scheduled commands (audit G5). Each runs on its cron
 * expression from `schedule.tasks` (falling back to {@see self::DEFAULTS},
 * so a host's older published config still schedules newer tasks), on one
 * server, without overlapping. `schedule.enabled = false` schedules
 * nothing, for hosts that run the commands themselves; a task set to
 * `null` or `false` is skipped.
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

use Illuminate\Console\Scheduling\Schedule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class EngineSchedule
{
    /**
     * Command → default cron expression.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'ecommerce:release-expired-reservations' => '* * * * *',
        'ecommerce:retry-webhook-deliveries'     => '* * * * *',
        'ecommerce:flag-abandoned-carts'         => '*/5 * * * *',
        'ecommerce:reconcile-payments'           => '*/15 * * * *',
        'ecommerce:prune-idempotency-records'    => '0 * * * *',
        'ecommerce:audit-order-status'           => '15 2 * * *',
        'ecommerce:prune-carts'                  => '30 3 * * *',
        'ecommerce:prune-ledgers'                => '45 3 * * *',
        'ecommerce:refresh-fx-rates'             => '30 5 * * *',
    ];

    /**
     * Adds the engine's tasks to `$schedule`.
     *
     * @since 1.0.0
     *
     * @param  Schedule  $schedule  Host schedule.
     *
     * @return void
     */
    public static function register( Schedule $schedule ): void
    {
        if ( ! (bool) config( 'artisanpack.ecommerce.schedule.enabled', true ) ) {
            return;
        }

        foreach ( self::tasks() as $command => $expression ) {
            $schedule->command( $command )
                ->cron( $expression )
                ->onOneServer()
                ->withoutOverlapping()
                ->runInBackground();
        }
    }

    /**
     * The tasks to schedule: the defaults overridden by `schedule.tasks`,
     * without the disabled ones.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public static function tasks(): array
    {
        $configured = config( 'artisanpack.ecommerce.schedule.tasks', [] );
        $tasks      = array_merge( self::DEFAULTS, is_array( $configured ) ? $configured : [] );

        return array_filter(
            array_map( static fn ( mixed $expression ): string => is_string( $expression ) ? trim( $expression ) : '', $tasks ),
            static fn ( string $expression ): bool => '' !== $expression,
        );
    }
}
