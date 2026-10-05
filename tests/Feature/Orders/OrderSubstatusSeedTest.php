<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'seeds one default sub-status per core system status', function (): void {
    $systemStatuses = [ 'pending', 'processing', 'complete', 'cancelled', 'refunded', 'failed' ];

    foreach ( $systemStatuses as $systemStatus ) {
        $row = OrderSubstatus::query()
            ->where( 'system_status', $systemStatus )
            ->first();

        expect( $row )->not->toBeNull( "expected a seeded sub-status for {$systemStatus}" );
        expect( $row->key )->not->toBeEmpty();
        expect( $row->label )->not->toBeEmpty();
    }

    expect( OrderSubstatus::query()->count() )->toBe( count( $systemStatuses ) );
} );

it( 'marks terminal system statuses as terminal', function (): void {
    foreach ( [ 'complete', 'cancelled', 'refunded', 'failed' ] as $systemStatus ) {
        $row = OrderSubstatus::query()->where( 'system_status', $systemStatus )->first();

        expect( $row->is_terminal )->toBeTrue( "{$systemStatus} default sub-status should be terminal" );
    }

    foreach ( [ 'pending', 'processing' ] as $systemStatus ) {
        $row = OrderSubstatus::query()->where( 'system_status', $systemStatus )->first();

        expect( $row->is_terminal )->toBeFalse( "{$systemStatus} default sub-status should not be terminal" );
    }
} );

it( 'enforces the composite unique key on (system_status, key)', function (): void {
    $existing = OrderSubstatus::query()->where( 'system_status', 'pending' )->first();

    expect( fn () => OrderSubstatus::factory()->create( [
        'system_status' => 'pending',
        'key'           => $existing->key,
    ] ) )->toThrow( Illuminate\Database\QueryException::class );
} );
