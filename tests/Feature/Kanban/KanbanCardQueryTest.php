<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Kanban\KanbanCardFilters;
use ArtisanPackUI\Ecommerce\Kanban\KanbanCardQuery;
use ArtisanPackUI\Ecommerce\Kanban\KanbanCardTags;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/KanbanTestHelpers.php';

uses( RefreshDatabase::class );

afterEach( function (): void {
    removeAllFilters( KanbanCardQuery::HOOK );
    removeAllFilters( 'ap.ecommerce.kanban.orderTags' );
    Carbon::setTestNow();
} );

/**
 * A Printing / Packing board.
 */
function queryBoard(): KanbanBoard
{
    return kanbanBoard( [], [ [ 'processing', 'Printing' ], [ 'processing', 'Packing' ] ] );
}

/**
 * Places a new processing order on `$column` at `$position`.
 *
 * @param  array<string, mixed>  $attributes  Order attributes.
 */
function queryCard( KanbanColumn $column, array $attributes = [], int $position = 0 ): Order
{
    $order = kanbanOrder( $attributes + [ 'system_status' => 'processing' ] );
    putOnBoard( $order, $column )->forceFill( [ 'position' => $position ] )->save();

    return $order;
}

/**
 * Order numbers the query returns for `$filters` on `$board`.
 *
 * @param  array<string, mixed>  $filters  Raw filters.
 *
 * @return array<int, string>
 */
function matching( KanbanBoard $board, array $filters ): array
{
    return KanbanCardQuery::for( $board )
        ->filter( KanbanCardFilters::fromArray( $filters ) )
        ->get( 100 )
        ->map( fn ( OrderBoardAssignment $card ): string => $card->order->order_number )
        ->sort()
        ->values()
        ->all();
}

it( 'returns a column in position order, then by when cards joined, with removed cards left out', function (): void {
    $board    = queryBoard();
    $printing = kanbanColumn( $board, 'Printing' );

    queryCard( $printing, [ 'order_number' => 'THIRD' ], 2 );
    queryCard( $printing, [ 'order_number' => 'FIRST' ], -1 );
    queryCard( $printing, [ 'order_number' => 'SECOND' ], 0 );
    queryCard( kanbanColumn( $board, 'Packing' ), [ 'order_number' => 'ELSEWHERE' ] );
    OrderBoardAssignment::query()->where( 'order_id', queryCard( $printing, [ 'order_number' => 'GONE' ] )->id )->update( [ 'removed_at' => now() ] );

    $cards = KanbanCardQuery::for( $board )->column( $printing )->get( 10 );

    expect( $cards->map( fn ( $card ): string => $card->order->order_number )->all() )->toBe( [ 'FIRST', 'SECOND', 'THIRD' ] )
        ->and( $cards->first()->order->relationLoaded( 'items' ) )->toBeTrue()
        ->and( $cards->first()->order->boardAssignments->first()->is( $cards->first() ) )->toBeTrue();
} );

it( 'cursor-paginates a column', function (): void {
    $board    = queryBoard();
    $printing = kanbanColumn( $board, 'Printing' );

    foreach ( range( 1, 5 ) as $i ) {
        queryCard( $printing, [ 'order_number' => 'N' . $i ], $i );
    }

    $first  = KanbanCardQuery::for( $board )->column( $printing )->cursorPaginate( 2 );
    $second = KanbanCardQuery::for( $board )->column( $printing )->cursorPaginate( 2, $first->nextCursor()?->encode() );

    expect( collect( $first->items() )->map( fn ( $card ): string => $card->order->order_number )->all() )->toBe( [ 'N1', 'N2' ] )
        ->and( collect( $second->items() )->map( fn ( $card ): string => $card->order->order_number )->all() )->toBe( [ 'N3', 'N4' ] );
} );

it( 'searches order numbers, emails, address names, and customer names case-insensitively', function (): void {
    $board    = queryBoard();
    $printing = kanbanColumn( $board, 'Printing' );
    $customer = Customer::factory()->create( [ 'first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.test' ] );

    queryCard( $printing, [ 'order_number' => 'A-1042', 'email' => 'one@example.test' ] );
    queryCard( $printing, [ 'order_number' => 'B-2000', 'email' => 'buyer@shop.test' ] );
    queryCard( $printing, [ 'order_number' => 'C-3000', 'billing_address' => [ 'first_name' => 'Ada', 'last_name' => 'Lovelace' ] ] );
    queryCard( $printing, [ 'order_number' => 'D-4000', 'customer_id' => $customer->id ] );
    queryCard( $printing, [ 'order_number' => 'E-5000', 'email' => 'percent%@example.test' ] );

    expect( matching( $board, [ 'search' => '1042' ] ) )->toBe( [ 'A-1042' ] )
        ->and( matching( $board, [ 'search' => 'SHOP.test' ] ) )->toBe( [ 'B-2000' ] )
        ->and( matching( $board, [ 'search' => 'ada lovelace' ] ) )->toBe( [ 'C-3000' ] )
        ->and( matching( $board, [ 'search' => 'ada hopper' ] ) )->toBe( [] )
        ->and( matching( $board, [ 'search' => 'hopper' ] ) )->toBe( [ 'D-4000' ] )
        ->and( matching( $board, [ 'search' => '%' ] ) )->toBe( [ 'E-5000' ] );
} );

it( 'filters by customer, tags (any or all), and products including their variants', function (): void {
    $board    = queryBoard();
    $printing = kanbanColumn( $board, 'Printing' );
    $customer = Customer::factory()->create();
    $shirt    = Product::factory()->create();
    $variant  = ProductVariant::factory()->create( [ 'product_id' => $shirt->id ] );

    queryCard( $printing, [ 'order_number' => 'MINE', 'customer_id' => $customer->id ] );
    queryCard( $printing, [ 'order_number' => 'RUSH', 'meta' => [ 'tags' => [ 'rush', 'wholesale' ] ] ] );
    queryCard( $printing, [ 'order_number' => 'WHOLE', 'meta' => [ 'tags' => [ 'wholesale' ] ] ] );
    OrderItem::factory()->create( [ 'order_id' => queryCard( $printing, [ 'order_number' => 'SHIRT' ] )->id, 'product_id' => $shirt->id ] );
    OrderItem::factory()->create( [ 'order_id' => queryCard( $printing, [ 'order_number' => 'VARIANT' ] )->id, 'product_id' => null, 'product_variant_id' => $variant->id ] );

    expect( matching( $board, [ 'customer_id' => $customer->id ] ) )->toBe( [ 'MINE' ] )
        ->and( matching( $board, [ 'tags' => 'rush,wholesale' ] ) )->toBe( [ 'RUSH', 'WHOLE' ] )
        ->and( matching( $board, [ 'tags' => [ 'rush', 'wholesale' ], 'tag_match' => 'all' ] ) )->toBe( [ 'RUSH' ] )
        ->and( matching( $board, [ 'product_ids' => (string) $shirt->id ] ) )->toBe( [ 'SHIRT', 'VARIANT' ] );
} );

it( 'filters by placed date range, stale cards, and payment and fulfillment status', function (): void {
    Carbon::setTestNow( '2026-10-20 12:00:00' );
    $board    = queryBoard();
    $printing = kanbanColumn( $board, 'Printing' );

    queryCard( $printing, [ 'order_number' => 'OLD', 'placed_at' => '2026-10-01 10:00:00', 'payment_status' => 'paid' ] );
    queryCard( $printing, [ 'order_number' => 'WEEK', 'placed_at' => '2026-10-15 23:30:00', 'fulfillment_status' => 'partial' ] );
    $stale = queryCard( $printing, [ 'order_number' => 'STALE', 'placed_at' => '2026-10-19 08:00:00', 'payment_status' => 'paid' ] );
    OrderBoardAssignment::query()->where( 'order_id', $stale->id )->update( [ 'moved_at' => '2026-10-10 00:00:00' ] );

    expect( matching( $board, [ 'placed_from' => '2026-10-14', 'placed_to' => '2026-10-15' ] ) )->toBe( [ 'WEEK' ] )
        ->and( matching( $board, [ 'stale' => '1' ] ) )->toBe( [ 'STALE' ] )
        ->and( matching( $board, [ 'moved_before' => '2026-10-21' ] ) )->toBe( [ 'OLD', 'STALE', 'WEEK' ] )
        ->and( matching( $board, [ 'payment_status' => 'paid' ] ) )->toBe( [ 'OLD', 'STALE' ] )
        ->and( matching( $board, [ 'payment_status' => 'paid', 'placed_from' => '2026-10-18' ] ) )->toBe( [ 'STALE' ] )
        ->and( matching( $board, [ 'fulfillment_status' => [ 'partial', 'fulfilled' ] ] ) )->toBe( [ 'WEEK' ] );
} );

it( 'counts filtered and total cards per column', function (): void {
    $board    = queryBoard();
    $printing = kanbanColumn( $board, 'Printing' );
    $packing  = kanbanColumn( $board, 'Packing' );

    queryCard( $printing, [ 'meta' => [ 'tags' => [ 'rush' ] ] ] );
    queryCard( $printing );
    queryCard( $packing );

    expect( KanbanCardQuery::for( $board )->countsByColumn() )->toBe( [
        $printing->id => [ 'count' => 2, 'total' => 2 ],
        $packing->id  => [ 'count' => 1, 'total' => 1 ],
    ] )->and( KanbanCardQuery::for( $board )->filter( KanbanCardFilters::fromArray( [ 'tags' => 'rush' ] ) )->countsByColumn() )->toBe( [
        $printing->id => [ 'count' => 1, 'total' => 2 ],
        $packing->id  => [ 'count' => 0, 'total' => 1 ],
    ] );
} );

it( 'lets satellites add filters through the cardQuery hook', function (): void {
    $board    = queryBoard();
    $printing = kanbanColumn( $board, 'Printing' );

    queryCard( $printing, [ 'order_number' => 'GIFT', 'meta' => [ 'gift' => true ] ] );
    queryCard( $printing, [ 'order_number' => 'PLAIN' ] );

    addFilter( KanbanCardQuery::HOOK, function ( Builder $query, KanbanCardFilters $filters, KanbanBoard $b ): Builder {
        if ( true === filter_var( $filters->extra['gift'] ?? null, FILTER_VALIDATE_BOOLEAN ) ) {
            $query->whereHas( 'order', fn ( Builder $orders ) => $orders->where( 'meta->gift', true ) );
        }

        return $query;
    } );

    $filters = KanbanCardFilters::fromArray( [ 'gift' => '1' ] );

    expect( $filters->isEmpty() )->toBeFalse()
        ->and( matching( $board, [ 'gift' => '1' ] ) )->toBe( [ 'GIFT' ] )
        ->and( KanbanCardQuery::for( $board )->filter( $filters )->countsByColumn()[ $printing->id ] )->toBe( [ 'count' => 1, 'total' => 2 ] );
} );

it( 'reads loose filter input and drops invalid values', function (): void {
    $filters = KanbanCardFilters::fromArray( [
        'search'       => '   ',
        'customer_id'  => 'abc',
        'tags'         => [ ' rush ', '', [ 'x' ], 'rush' ],
        'product_ids'  => '3,abc,-1,4',
        'placed_from'  => 'not a date',
        'placed_to'    => '2026-10-15',
        'tag_match'    => 'sideways',
    ] );

    expect( $filters->search )->toBeNull()
        ->and( $filters->customerId )->toBeNull()
        ->and( $filters->tags )->toBe( [ 'rush' ] )
        ->and( $filters->tagMatch )->toBe( 'any' )
        ->and( $filters->productIds )->toBe( [ 3, 4 ] )
        ->and( $filters->placedFrom )->toBeNull()
        ->and( $filters->placedTo?->toDateTimeString() )->toBe( '2026-10-15 23:59:59' )
        ->and( KanbanCardFilters::none()->isEmpty() )->toBeTrue()
        ->and( KanbanCardFilters::fromArray( [ 'unknown' => '' ] )->isEmpty() )->toBeTrue();
} );

it( 'lists the distinct tags on a board through the orderTags filter, once per request', function (): void {
    $board    = queryBoard();
    $printing = kanbanColumn( $board, 'Printing' );

    queryCard( $printing, [ 'meta' => [ 'tags' => [ 'wholesale', 'Rush' ] ] ] );
    queryCard( $printing, [ 'meta' => [ 'tags' => [ 'rush', 'wholesale' ] ] ] );
    queryCard( kanbanColumn( queryBoard(), 'Printing' ), [ 'meta' => [ 'tags' => [ 'other-board' ] ] ] );

    addFilter( 'ap.ecommerce.kanban.orderTags', fn ( array $tags ): array => [ ...$tags, 'from-satellite' ] );

    $tags = app( KanbanCardTags::class );

    expect( $tags->forBoard( $board ) )->toBe( [ 'from-satellite', 'rush', 'Rush', 'wholesale' ] );

    DB::enableQueryLog();
    $tags->forBoard( $board );

    expect( DB::getQueryLog() )->toBe( [] );
} );

it( 'renders a column of 50 cards in the same number of queries as a column of 5', function (): void {
    $queries = function ( int $count ): int {
        $board    = queryBoard();
        $printing = kanbanColumn( $board, 'Printing' );

        foreach ( range( 1, $count ) as $i ) {
            queryCard( $printing, [], $i );
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ( KanbanCardQuery::for( $board )->column( $printing )->get( 100 ) as $card ) {
            app( ArtisanPackUI\Ecommerce\Kanban\KanbanCardRenderer::class )->render( $card->order, $printing->setRelation( 'board', $board ) );
        }

        $count = count( DB::getQueryLog() );
        DB::disableQueryLog();

        return $count;
    };

    $queries( 1 );

    expect( $queries( 50 ) )->toBe( $queries( 5 ) );
} );
