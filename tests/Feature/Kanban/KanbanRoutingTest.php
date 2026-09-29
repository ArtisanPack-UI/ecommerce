<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Events\KanbanBoardAssignmentAdded;
use ArtisanPackUI\Ecommerce\Events\KanbanBoardAssignmentRemoved;
use ArtisanPackUI\Ecommerce\Exceptions\KanbanOperationException;
use ArtisanPackUI\Ecommerce\Kanban\OrderConditionEvaluator;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Services\KanbanRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/KanbanTestHelpers.php';

uses( RefreshDatabase::class );

afterEach( function (): void {
    removeAllFilters( 'ap.ecommerce.kanban.routingBoards' );
    removeAllActions( 'ap.ecommerce.kanban.boardReassigned' );
} );

/**
 * A photography store: prints go to Production and Shipping, digital-only
 * orders go to Downloads.
 *
 * @return array{production: ArtisanPackUI\Ecommerce\Models\KanbanBoard, shipping: ArtisanPackUI\Ecommerce\Models\KanbanBoard, downloads: ArtisanPackUI\Ecommerce\Models\KanbanBoard}
 */
function photographyBoards(): array
{
    $physical = [ 'type' => 'cart-contains-product-type', 'config' => [ 'types' => [ 'simple' ] ] ];

    return [
        'production' => kanbanBoard( [ 'key' => 'production', 'position' => 1, 'routing_rules' => $physical ], [ [ 'processing', 'Design' ], [ 'processing', 'Printing' ], [ 'complete', 'Printed', true ] ] ),
        'shipping'   => kanbanBoard( [ 'key' => 'shipping', 'position' => 2, 'routing_rules' => $physical ], [ [ 'pending', 'Awaiting payment' ], [ 'processing', 'Awaiting label' ], [ 'complete', 'Shipped', true ] ] ),
        'downloads'  => kanbanBoard( [ 'key' => 'downloads', 'position' => 3, 'routing_rules' => [ 'type' => 'cart-contains-product-type', 'config' => [ 'types' => [ 'digital' ], 'match' => 'only' ] ] ], [ [ 'pending', 'Queued' ], [ 'complete', 'Delivered', true ] ] ),
    ];
}

it( 'routes a print order onto the production and shipping boards at once', function (): void {
    $boards = photographyBoards();
    $order  = kanbanOrder( [ 'system_status' => 'pending' ], [ 'simple', 'digital' ] );

    Event::fake( [ KanbanBoardAssignmentAdded::class ] );

    $cards = app( KanbanRoutingService::class )->route( $order );

    expect( collect( $cards )->pluck( 'board_id' )->all() )->toBe( [ $boards['production']->id, $boards['shipping']->id ] );

    // Each board picks its own entry column: Shipping has a pending column,
    // Production only processing ones (the card leads the order there).
    $byBoard = OrderBoardAssignment::query()->with( 'substatus' )->get()->keyBy( 'board_id' );

    expect( $byBoard[ $boards['shipping']->id ]->substatus->label )->toBe( 'Awaiting payment' )
        ->and( $byBoard[ $boards['production']->id ]->substatus->label )->toBe( 'Design' );

    Event::assertDispatchedTimes( KanbanBoardAssignmentAdded::class, 2 );
    expect( OrderTimelineEntry::query()->where( 'event_type', 'kanban.assignment_added' )->count() )->toBe( 2 );
} );

it( 'routes a digital-only order onto the downloads board only', function (): void {
    $boards = photographyBoards();
    $order  = kanbanOrder( [ 'system_status' => 'pending' ], [ 'digital', 'digital' ] );

    expect( app( KanbanRoutingService::class )->matchingBoardIds( $order ) )->toBe( [ $boards['downloads']->id ] );
} );

it( 'sends every order to boards without rules, and to the default board only as a fallback', function (): void {
    $everything = kanbanBoard( [ 'key' => 'everything', 'position' => 2 ] );
    $fallback   = kanbanBoard( [ 'key' => 'inbox', 'is_default' => true, 'position' => 1 ] );
    $never      = kanbanBoard( [ 'key' => 'big', 'position' => 0, 'routing_rules' => [ 'type' => 'min-subtotal', 'config' => [ 'amount' => 10_000_000 ] ] ] );
    $order      = kanbanOrder();
    $routing    = app( KanbanRoutingService::class );

    expect( $routing->matchingBoardIds( $order ) )->toBe( [ $everything->id ] );

    $everything->update( [ 'is_active' => false ] );

    expect( $routing->matchingBoardIds( $order ) )->toBe( [ $fallback->id ] )
        ->and( $never->id )->not->toBeIn( $routing->matchingBoardIds( $order ) );
} );

it( 'walks boards in position order and lets the routingBoards filter change the result', function (): void {
    $late  = kanbanBoard( [ 'key' => 'late', 'position' => 9 ] );
    $early = kanbanBoard( [ 'key' => 'early', 'position' => 1 ] );
    $order = kanbanOrder();

    expect( app( KanbanRoutingService::class )->matchingBoardIds( $order ) )->toBe( [ $early->id, $late->id ] );

    addFilter( 'ap.ecommerce.kanban.routingBoards', fn ( array $ids, Order $o ): array => array_values( array_diff( $ids, [ $early->id ] ) ) );

    expect( app( KanbanRoutingService::class )->matchingBoardIds( $order ) )->toBe( [ $late->id ] );

    // Unknown / inactive ids from a filter are dropped.
    addFilter( 'ap.ecommerce.kanban.routingBoards', fn ( array $ids ): array => [ ...$ids, 999_999, 'nope' ], 20 );

    expect( app( KanbanRoutingService::class )->matchingBoardIds( $order ) )->toBe( [ $late->id ] );
} );

it( 'skips a board that has no column able to hold the order', function (): void {
    kanbanBoard( [ 'key' => 'refunds' ], [ [ 'refunded', 'Refunded', true ] ] );
    $ok    = kanbanBoard( [ 'key' => 'ok' ] );
    $order = kanbanOrder( [ 'system_status' => 'processing' ] );

    $cards = app( KanbanRoutingService::class )->route( $order );

    expect( collect( $cards )->pluck( 'board_id' )->all() )->toBe( [ $ok->id ] )
        ->and( $cards[0]->substatus->label )->toBe( 'Working' );
} );

it( 'routes on the order.placed hook and re-routes on order.edited', function (): void {
    $boards = photographyBoards();
    $order  = kanbanOrder( [ 'system_status' => 'pending' ], [ 'digital' ] );

    doAction( 'ap.ecommerce.order.placed', $order );

    expect( $order->boardAssignments()->active()->pluck( 'board_id' )->all() )->toBe( [ $boards['downloads']->id ] );

    // The customer adds a print: it now belongs on Production + Shipping and
    // no longer on the digital-only Downloads board.
    OrderItem::factory()->create( [ 'order_id' => $order->id, 'product_id' => Product::factory()->create( [ 'type' => 'simple' ] )->id ] );

    Event::fake( [ KanbanBoardAssignmentRemoved::class ] );
    $reassigned = [];
    addAction( 'ap.ecommerce.kanban.boardReassigned', function ( Order $o, array $added, array $removed ) use ( &$reassigned ): void {
        $reassigned = [ $added, $removed ];
    } );

    doAction( 'ap.ecommerce.order.edited', $order->fresh(), [], null );

    expect( $order->boardAssignments()->active()->pluck( 'board_id' )->sort()->values()->all() )
        ->toBe( [ $boards['production']->id, $boards['shipping']->id ] )
        ->and( $reassigned )->toBe( [ [ $boards['production']->id, $boards['shipping']->id ], [ $boards['downloads']->id ] ] );

    Event::assertDispatched( KanbanBoardAssignmentRemoved::class, fn ( KanbanBoardAssignmentRemoved $e ): bool => $e->assignment->board_id === $boards['downloads']->id );
} );

it( 'keeps cards on rule-less boards when re-routing', function (): void {
    $manual  = kanbanBoard( [ 'key' => 'manual' ] );
    $order   = kanbanOrder();
    $routing = app( KanbanRoutingService::class );

    $routing->route( $order );
    $manual->update( [ 'is_active' => false ] );

    expect( $routing->reroute( $order ) )->toBe( [ 'added' => [], 'removed' => [] ] )
        ->and( $order->boardAssignments()->active()->count() )->toBe( 1 );
} );

it( 'does not route when auto_route is off', function (): void {
    config()->set( 'artisanpack.ecommerce.kanban.auto_route', false );
    removeAllActions( 'ap.ecommerce.order.placed' );
    ( fn () => $this->registerKanbanListeners() )->call( new ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider( app() ) );

    kanbanBoard();
    $order = kanbanOrder();

    doAction( 'ap.ecommerce.order.placed', $order );

    expect( $order->boardAssignments()->count() )->toBe( 0 );
} );

it( 'assigns manually into a chosen column, is idempotent, and re-activates removed cards', function (): void {
    $board   = kanbanBoard();
    $order   = kanbanOrder( [ 'system_status' => 'processing' ] );
    $routing = app( KanbanRoutingService::class );

    Event::fake( [ KanbanBoardAssignmentAdded::class, KanbanBoardAssignmentRemoved::class ] );

    $card = $routing->assign( $order, $board, kanbanColumn( $board, 'Working' ) );
    $same = $routing->assign( $order, $board );

    expect( $same->is( $card ) )->toBeTrue();
    Event::assertDispatchedTimes( KanbanBoardAssignmentAdded::class, 1 );

    $routing->remove( $order, $board );

    expect( $card->fresh()->removed_at )->not->toBeNull();

    $again = $routing->assign( $order, $board );

    expect( $again->id )->toBe( $card->id )
        ->and( $again->fresh()->removed_at )->toBeNull();
    Event::assertDispatchedTimes( KanbanBoardAssignmentAdded::class, 2 );
    Event::assertDispatchedTimes( KanbanBoardAssignmentRemoved::class, 1 );
} );

it( 'refuses assignments into another board\'s column or an incompatible column, and removals from boards the order is not on', function (): void {
    $board   = kanbanBoard();
    $other   = kanbanBoard( [], [ [ 'processing', 'Elsewhere' ] ] );
    $order   = kanbanOrder( [ 'system_status' => 'cancelled' ] );
    $routing = app( KanbanRoutingService::class );

    expect( fn () => $routing->assign( $order, $board, kanbanColumn( $other, 'Elsewhere' ) ) )->toThrow( KanbanOperationException::class )
        ->and( fn () => $routing->assign( $order, $board ) )->toThrow( KanbanOperationException::class )
        ->and( fn () => $routing->remove( $order, $board ) )->toThrow( KanbanOperationException::class );
} );

it( 'evaluates any / all / not condition trees and fails closed on bad nodes', function (): void {
    $evaluator = app( OrderConditionEvaluator::class );
    $order     = kanbanOrder( [], [ 'simple' ] );
    $simple    = [ 'type' => 'cart-contains-product-type', 'config' => [ 'types' => [ 'simple' ] ] ];
    $digital   = [ 'type' => 'cart-contains-product-type', 'config' => [ 'types' => [ 'digital' ] ] ];

    expect( $evaluator->passes( [], $order ) )->toBeTrue()
        ->and( $evaluator->passes( $simple, $order ) )->toBeTrue()
        ->and( $evaluator->passes( [ 'all' => [ $simple, $digital ] ], $order ) )->toBeFalse()
        ->and( $evaluator->passes( [ 'any' => [ $digital, $simple ] ], $order ) )->toBeTrue()
        ->and( $evaluator->passes( [ 'any' => [] ], $order ) )->toBeFalse()
        ->and( $evaluator->passes( [ 'not' => $digital ], $order ) )->toBeTrue()
        ->and( $evaluator->passes( [ $simple, [ 'not' => $simple ] ], $order ) )->toBeFalse()
        ->and( $evaluator->passes( [ 'type' => 'no-such-condition' ], $order ) )->toBeFalse()
        ->and( $evaluator->passes( [ 'all' => 'nope' ], $order ) )->toBeFalse();

    $deep = $simple;

    for ( $i = 0; $i < OrderConditionEvaluator::MAX_DEPTH + 1; $i++ ) {
        $deep = [ 'all' => [ $deep ] ];
    }

    expect( $evaluator->passes( $deep, $order ) )->toBeFalse()
        ->and( $evaluator->validate( $deep ) )->toBeString()
        ->and( $evaluator->validate( [ 'any' => [ $simple, [ 'not' => $digital ] ] ] ) )->toBeNull()
        ->and( $evaluator->validate( [ 'type' => 'no-such-condition' ] ) )->toContain( 'no-such-condition' )
        ->and( $evaluator->validate( [ 'type' => 'min-subtotal', 'config' => 'x' ] ) )->toBeString();
} );

it( 'evaluates cart-shaped promotion conditions against the order lines', function (): void {
    $order = kanbanOrder( [], [ 'simple', 'simple' ] );

    expect( app( OrderConditionEvaluator::class )->passes( [ 'type' => 'min-subtotal', 'config' => [ 'amount' => 2_000 ] ], $order ) )->toBeTrue()
        ->and( app( OrderConditionEvaluator::class )->passes( [ 'type' => 'min-subtotal', 'config' => [ 'amount' => 2_001 ] ], $order ) )->toBeFalse();
} );

it( 'does not count the routed order when checking customer-first-order', function (): void {
    $customer = Customer::factory()->create();
    $first    = kanbanOrder( [ 'customer_id' => $customer->id, 'email' => $customer->email ] );
    $rule     = [ 'type' => 'customer-first-order' ];

    expect( app( OrderConditionEvaluator::class )->passes( $rule, $first ) )->toBeTrue();

    $second = kanbanOrder( [ 'customer_id' => $customer->id, 'email' => $customer->email ] );

    expect( app( OrderConditionEvaluator::class )->passes( $rule, $second ) )->toBeFalse();
} );
