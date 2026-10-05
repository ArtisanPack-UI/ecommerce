<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Support\EngineSchedule;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The engine events on a fresh schedule, keyed by command name.
 *
 * @return array<string, Event>
 */
function engineScheduleEvents(): array
{
    $schedule = new Schedule();

    EngineSchedule::register( $schedule );

    return collect( $schedule->events() )
        ->filter( fn ( Event $event ): bool => 1 === preg_match( '/(ecommerce:[a-z-]+)/', (string) $event->command ) )
        ->keyBy( fn ( Event $event ): string => preg_match( '/(ecommerce:[a-z-]+)/', (string) $event->command, $m ) ? $m[1] : '' )
        ->all();
}

it( 'schedules every engine task on one server without overlapping', function (): void {
    $events = engineScheduleEvents();

    expect( array_keys( $events ) )->toEqualCanonicalizing( array_keys( EngineSchedule::DEFAULTS ) );

    foreach ( $events as $command => $event ) {
        expect( $event->expression )->toBe( EngineSchedule::DEFAULTS[ $command ] )
            ->and( $event->onOneServer )->toBeTrue()
            ->and( $event->withoutOverlapping )->toBeTrue();
    }

    expect( array_keys( $events ) )->toContain( 'ecommerce:prune-ledgers', 'ecommerce:flag-abandoned-carts', 'ecommerce:prune-carts', 'ecommerce:refresh-fx-rates' );
} );

it( 'schedules nothing when the schedule is disabled', function (): void {
    config()->set( 'artisanpack.ecommerce.schedule.enabled', false );

    expect( engineScheduleEvents() )->toBe( [] );
} );

it( 'takes cadences from config and skips disabled tasks', function (): void {
    config()->set( 'artisanpack.ecommerce.schedule.tasks', [
        'ecommerce:prune-ledgers'       => '0 4 * * 0',
        'ecommerce:refresh-fx-rates'    => null,
        'ecommerce:reconcile-payments'  => false,
    ] );

    $events = engineScheduleEvents();

    expect( $events['ecommerce:prune-ledgers']->expression )->toBe( '0 4 * * 0' )
        ->and( $events )->not->toHaveKeys( [ 'ecommerce:refresh-fx-rates', 'ecommerce:reconcile-payments' ] )
        // A published config that predates a task still schedules it.
        ->and( $events['ecommerce:prune-carts']->expression )->toBe( '30 3 * * *' );
} );

it( 'ships config matching the defaults', function (): void {
    $shipped = require __DIR__ . '/../../../config/artisanpack/ecommerce.php';

    expect( $shipped['schedule']['tasks'] )->toBe( EngineSchedule::DEFAULTS );
} );
