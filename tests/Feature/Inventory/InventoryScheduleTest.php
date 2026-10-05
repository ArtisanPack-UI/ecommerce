<?php

declare( strict_types=1 );

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it( 'registers the release-expired-reservations command on the every-minute schedule', function (): void {
    /** @var Schedule $schedule */
    $schedule = app( Schedule::class );

    $match = collect( $schedule->events() )
        ->first( fn ( Event $event ) => str_contains( $event->command ?? '', 'ecommerce:release-expired-reservations' ) );

    expect( $match )->not->toBeNull();
    expect( $match->expression )->toBe( '* * * * *' );
} );
