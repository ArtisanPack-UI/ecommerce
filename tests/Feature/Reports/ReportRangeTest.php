<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Reports\ReportRange;

beforeEach( function (): void {
    config()->set( 'app.timezone', 'UTC' );
    config()->set( 'artisanpack.ecommerce.timezone', 'America/New_York' );
} );

it( 'defaults to the last 30 days ending today in the store time zone', function (): void {
    $this->travelTo( Illuminate\Support\Carbon::parse( '2026-03-03 02:00:00', 'UTC' ) );

    $range = ReportRange::make();

    // 02:00 UTC on the 3rd is still the 2nd in New York.
    expect( $range->toArray() )->toMatchArray( [ 'from' => '2026-02-01', 'to' => '2026-03-02', 'timezone' => 'America/New_York' ] )
        ->and( $range->days() )->toBe( 30 );
} );

it( 'converts the range to application-time-zone bounds', function (): void {
    expect( ReportRange::make( '2026-03-01', '2026-03-01' )->queryBounds() )->toBe( [ '2026-03-01 05:00:00', '2026-03-02 04:59:59' ] );
} );

it( 'builds the previous period of the same length', function (): void {
    expect( ReportRange::make( '2026-03-01', '2026-03-31' )->previous()->toArray() )->toMatchArray( [ 'from' => '2026-01-29', 'to' => '2026-02-28' ] );
} );

it( 'falls back to the application time zone', function (): void {
    config()->set( 'artisanpack.ecommerce.timezone', null );

    expect( ReportRange::timezone() )->toBe( 'UTC' );
} );

it( 'rejects bad input', function ( ?string $from, ?string $to, string $interval ): void {
    ReportRange::make( $from, $to, $interval );
} )->with( [
    'reversed'      => [ '2026-03-05', '2026-03-01', 'day' ],
    'too long'      => [ '2020-01-01', '2026-01-01', 'day' ],
    'bad interval'  => [ '2026-03-01', '2026-03-05', 'hour' ],
    'not a date'    => [ '2026-02-30', '2026-03-05', 'day' ],
    'wrong format'  => [ '03/01/2026', '2026-03-05', 'day' ],
] )->throws( InvalidArgumentException::class );
