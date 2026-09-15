<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'exits successfully when no drift is present', function (): void {
    Order::factory()->create( [
        'system_status' => 'processing',
        'substatus_id'  => OrderSubstatus::query()->where( 'system_status', 'processing' )->value( 'id' ),
    ] );

    $this->artisan( 'ecommerce:audit-order-status' )
        ->expectsOutputToContain( 'No order-status drift detected.' )
        ->assertExitCode( 0 );
} );

it( 'reports and fails when an orders.substatus_id disagrees with system_status', function (): void {
    $completeSub = OrderSubstatus::query()->where( 'system_status', 'complete' )->first();

    // Bypass the machine to simulate corrupted state.
    $order = Order::factory()->create( [ 'system_status' => 'processing' ] );
    Order::withoutEvents( function () use ( $order, $completeSub ): void {
        Order::query()->where( 'id', $order->id )->update( [ 'substatus_id' => $completeSub->id ] );
    } );

    $captured = [];
    addAction( 'ap.ecommerce.order.statusAuditDrift', function ( array $row ) use ( &$captured ): void {
        $captured[] = $row;
    } );

    $this->artisan( 'ecommerce:audit-order-status' )
        ->expectsOutputToContain( 'Detected 1 drifted assignment(s).' )
        ->assertExitCode( 1 );

    expect( $captured )->toHaveCount( 1 );
    expect( $captured[0]['order_id'] )->toBe( $order->id );
    expect( $captured[0]['scope'] )->toBe( 'global_default' );
    expect( $captured[0]['order_system_status'] )->toBe( 'processing' );
    expect( $captured[0]['substatus_system_status'] )->toBe( 'complete' );

    removeAllActions( 'ap.ecommerce.order.statusAuditDrift' );
} );

it( 'reports every drifted row and fires the audit-drift hook once per row', function (): void {
    $completeSub  = OrderSubstatus::query()->where( 'system_status', 'complete' )->first();
    $cancelledSub = OrderSubstatus::query()->where( 'system_status', 'cancelled' )->first();

    $one = Order::factory()->create( [ 'system_status' => 'processing' ] );
    $two = Order::factory()->create( [ 'system_status' => 'processing' ] );

    Order::withoutEvents( function () use ( $one, $two, $completeSub, $cancelledSub ): void {
        Order::query()->where( 'id', $one->id )->update( [ 'substatus_id' => $completeSub->id ] );
        Order::query()->where( 'id', $two->id )->update( [ 'substatus_id' => $cancelledSub->id ] );
    } );

    $captured = [];
    addAction( 'ap.ecommerce.order.statusAuditDrift', function ( array $row ) use ( &$captured ): void {
        $captured[] = $row;
    } );

    $this->artisan( 'ecommerce:audit-order-status' )
        ->expectsOutputToContain( 'Detected 2 drifted assignment(s).' )
        ->assertExitCode( 1 );

    expect( $captured )->toHaveCount( 2 );

    $orderIds = array_map( fn ( array $row ): int => $row['order_id'], $captured );
    expect( $orderIds )->toContain( $one->id, $two->id );

    removeAllActions( 'ap.ecommerce.order.statusAuditDrift' );
} );

it( 'skips the board-assignment check when order_board_assignments does not exist', function (): void {
    Order::factory()->create( [
        'system_status' => 'processing',
        'substatus_id'  => OrderSubstatus::query()->where( 'system_status', 'processing' )->value( 'id' ),
    ] );

    // Nothing else to assert beyond a clean run; the test lives to catch the day
    // this command starts requiring the boards satellite's tables to exist.
    $this->artisan( 'ecommerce:audit-order-status' )->assertExitCode( 0 );
} );
