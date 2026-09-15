<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\OrderStatusChanged;
use ArtisanPackUI\Ecommerce\Events\OrderSubstatusChanged;
use ArtisanPackUI\Ecommerce\Exceptions\IncompatibleBoardSubstatusException;
use ArtisanPackUI\Ecommerce\Exceptions\InvalidOrderStatusTransitionException;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Services\OrderStatusMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses( RefreshDatabase::class );

it( 'transitions system_status when the move is declared allowed', function (): void {
    $order = Order::factory()->create( [ 'system_status' => 'pending' ] );
    Event::fake( [ OrderStatusChanged::class ] );

    $result = ( new OrderStatusMachine() )->transition( $order, 'processing', 42, 'payment captured' );

    expect( $result->system_status )->toBe( 'processing' );
    expect( $order->fresh()->system_status )->toBe( 'processing' );

    $timeline = OrderTimelineEntry::query()->where( 'order_id', $order->id )->first();
    expect( $timeline->event_type )->toBe( 'order.status_changed' );
    expect( $timeline->payload )->toMatchArray( [
        'from'   => 'pending',
        'to'     => 'processing',
        'reason' => 'payment captured',
    ] );
    expect( $timeline->actor_user_id )->toBe( 42 );

    Event::assertDispatched(
        OrderStatusChanged::class,
        fn ( OrderStatusChanged $e ) => 'pending' === $e->from && 'processing' === $e->to,
    );
} );

it( 'rejects illegal system_status transitions', function (): void {
    $order = Order::factory()->create( [ 'system_status' => 'refunded' ] );

    expect( fn () => ( new OrderStatusMachine() )->transition( $order, 'processing' ) )
        ->toThrow( InvalidOrderStatusTransitionException::class );

    expect( $order->fresh()->system_status )->toBe( 'refunded' );
    expect( OrderTimelineEntry::query()->count() )->toBe( 0 );
} );

it( 'treats same-status calls as no-ops', function (): void {
    $order = Order::factory()->create( [ 'system_status' => 'processing' ] );
    Event::fake( [ OrderStatusChanged::class ] );

    ( new OrderStatusMachine() )->transition( $order, 'processing' );

    expect( OrderTimelineEntry::query()->count() )->toBe( 0 );
    Event::assertNotDispatched( OrderStatusChanged::class );
} );

it( 'sets the global default sub-status when compatible with system_status', function (): void {
    $order     = Order::factory()->create( [ 'system_status' => 'processing' ] );
    $substatus = OrderSubstatus::query()->where( 'system_status', 'processing' )->first();
    Event::fake( [ OrderSubstatusChanged::class ] );

    ( new OrderStatusMachine() )->setSubstatus( $order, $substatus, 7, 'moved on kanban' );

    expect( $order->fresh()->substatus_id )->toBe( $substatus->id );

    $timeline = OrderTimelineEntry::query()->where( 'order_id', $order->id )->first();
    expect( $timeline->event_type )->toBe( 'order.substatus_changed' );
    expect( $timeline->payload['to_id'] )->toBe( $substatus->id );
    expect( $timeline->payload['reason'] )->toBe( 'moved on kanban' );
    expect( $timeline->payload['board_id'] )->toBeNull();

    Event::assertDispatched( OrderSubstatusChanged::class );
} );

it( 'rejects a sub-status whose system_status disagrees with the order', function (): void {
    $order     = Order::factory()->create( [ 'system_status' => 'processing' ] );
    $substatus = OrderSubstatus::query()->where( 'system_status', 'complete' )->first();

    expect( fn () => ( new OrderStatusMachine() )->setSubstatus( $order, $substatus ) )
        ->toThrow( IncompatibleBoardSubstatusException::class );

    expect( $order->fresh()->substatus_id )->toBeNull();
    expect( OrderTimelineEntry::query()->count() )->toBe( 0 );
} );

it( 'lets the canTransitionSubstatus filter block a sub-status change', function (): void {
    $order     = Order::factory()->create( [ 'system_status' => 'processing' ] );
    $substatus = OrderSubstatus::query()->where( 'system_status', 'processing' )->first();

    addFilter( 'ap.ecommerce.order.canTransitionSubstatus', fn () => false );

    expect( fn () => ( new OrderStatusMachine() )->setSubstatus( $order, $substatus ) )
        ->toThrow( RuntimeException::class );

    expect( $order->fresh()->substatus_id )->toBeNull();

    removeAllFilters( 'ap.ecommerce.order.canTransitionSubstatus' );
} );

it( 'does not touch orders.substatus_id when the change comes from a board', function (): void {
    $order            = Order::factory()->create( [ 'system_status' => 'processing' ] );
    $globalSubstatus  = OrderSubstatus::query()->where( 'system_status', 'processing' )->first();
    $boardSubstatus   = OrderSubstatus::factory()->forSystemStatus( 'processing' )->create();

    $order->substatus_id = $globalSubstatus->id;
    $order->save();

    ( new OrderStatusMachine() )->setSubstatus( $order, $boardSubstatus, null, 'moved on board', 99 );

    expect( $order->fresh()->substatus_id )->toBe( $globalSubstatus->id );

    $timeline = OrderTimelineEntry::query()->where( 'order_id', $order->id )->first();
    expect( $timeline->payload['board_id'] )->toBe( 99 );
    expect( $timeline->payload['to_id'] )->toBe( $boardSubstatus->id );
} );

it( 'exposes assertBoardSubstatusCompatible for board-assignment services', function (): void {
    $order      = Order::factory()->create( [ 'system_status' => 'processing' ] );
    $compatible = OrderSubstatus::query()->where( 'system_status', 'processing' )->first();
    $bad        = OrderSubstatus::query()->where( 'system_status', 'complete' )->first();

    $machine = new OrderStatusMachine();
    $machine->assertBoardSubstatusCompatible( $order, $compatible );

    expect( fn () => $machine->assertBoardSubstatusCompatible( $order, $bad ) )
        ->toThrow( IncompatibleBoardSubstatusException::class );
} );

it( 'reports the transition graph via isTransitionAllowed', function (): void {
    $machine = new OrderStatusMachine();

    expect( $machine->isTransitionAllowed( 'pending', 'processing' ) )->toBeTrue();
    expect( $machine->isTransitionAllowed( 'processing', 'complete' ) )->toBeTrue();
    expect( $machine->isTransitionAllowed( 'complete', 'refunded' ) )->toBeTrue();
    expect( $machine->isTransitionAllowed( 'refunded', 'pending' ) )->toBeFalse();
    expect( $machine->isTransitionAllowed( 'nonsense', 'processing' ) )->toBeFalse();
} );

it( 'accepts every declared transition edge and rejects every other pair', function (): void {
    $machine  = new OrderStatusMachine();
    $statuses = array_keys( OrderStatusMachine::ALLOWED_TRANSITIONS );

    foreach ( $statuses as $from ) {
        $allowedTargets = OrderStatusMachine::ALLOWED_TRANSITIONS[ $from ];

        foreach ( $statuses as $to ) {
            if ( $from === $to ) {
                continue;
            }

            $shouldPass = in_array( $to, $allowedTargets, true );
            $order      = Order::factory()->create( [ 'system_status' => $from ] );

            if ( $shouldPass ) {
                $machine->transition( $order, $to );
                expect( $order->fresh()->system_status )
                    ->toBe( $to, "{$from} → {$to} should be allowed" );
            } else {
                expect( fn () => $machine->transition( $order, $to ) )
                    ->toThrow(
                        InvalidOrderStatusTransitionException::class,
                        '',
                        "{$from} → {$to} should be rejected",
                    );
                expect( $order->fresh()->system_status )
                    ->toBe( $from, "{$from} → {$to} left the order on {$from}" );
            }
        }
    }
} );
