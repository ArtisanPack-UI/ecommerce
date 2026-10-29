<?php

/**
 * KanbanCardQuery.
 *
 * The cards on a board, narrowed by {@see KanbanCardFilters}
 * (ArtisanPack-UI/ecommerce#199). The REST cards endpoint and in-process
 * kanban UIs share it, so what a filter bar shows and the column counts
 * beside it always agree.
 *
 * ```php
 * $query = KanbanCardQuery::for( $board )->filter( KanbanCardFilters::fromArray( $input ) );
 *
 * $page   = $query->column( $column )->cursorPaginate( 25 );
 * $counts = $query->countsByColumn(); // [ column id => [ 'count' => filtered, 'total' => all ] ]
 * ```
 *
 * Cards come in their manual order (position, then when they joined the
 * board) with what the core widgets read already loaded, so rendering a
 * column takes a fixed number of queries. Satellites add filters through
 * `ap.ecommerce.kanban.cardQuery`, which receives the assignment builder,
 * the filters (their own values are in {@see KanbanCardFilters::$extra}),
 * and the board.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Kanban;

use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
final class KanbanCardQuery
{
    /**
     * Filter hook satellites add their own filters through.
     *
     * @since 1.2.0
     *
     * @var string
     */
    public const HOOK = 'ap.ecommerce.kanban.cardQuery';

    /**
     * Most whitespace-separated words a search matches on.
     *
     * @since 1.2.0
     *
     * @var int
     */
    public const MAX_SEARCH_TERMS = 5;

    /**
     * @since 1.2.0
     *
     * @var KanbanCardFilters
     */
    private KanbanCardFilters $filters;

    /**
     * @since 1.2.0
     *
     * @var KanbanColumn|null
     */
    private ?KanbanColumn $column = null;

    /**
     * @since 1.2.0
     *
     * @param  KanbanBoard  $board  The board.
     */
    private function __construct( private readonly KanbanBoard $board )
    {
        $this->filters = KanbanCardFilters::none();
    }

    /**
     * Cards on `$board`.
     *
     * @since 1.2.0
     *
     * @param  KanbanBoard  $board  The board.
     *
     * @return self
     */
    public static function for( KanbanBoard $board ): self
    {
        return new self( $board );
    }

    /**
     * A copy of this query narrowed by `$filters`. This query is unchanged,
     * so it can be reused.
     *
     * @since 1.2.0
     *
     * @param  KanbanCardFilters  $filters  Filters.
     *
     * @return self
     */
    public function filter( KanbanCardFilters $filters ): self
    {
        $copy          = clone $this;
        $copy->filters = $filters;

        return $copy;
    }

    /**
     * A copy of this query narrowed to `$column` (null for the whole board).
     * This query is unchanged, so it can be reused.
     *
     * @since 1.2.0
     *
     * @param  KanbanColumn|null  $column  A column on the board.
     *
     * @return self
     */
    public function column( ?KanbanColumn $column ): self
    {
        $copy         = clone $this;
        $copy->column = $column;

        return $copy;
    }

    /**
     * The filtered active cards, with eager loads and no ordering.
     *
     * @since 1.2.0
     *
     * @return Builder<OrderBoardAssignment>
     */
    public function builder(): Builder
    {
        $query = $this->base()->with( array_map( static fn ( string $relation ): string => 'order.' . $relation, Order::kanbanCardRelations() ) );

        if ( null !== $this->column ) {
            $query->where( $query->qualifyColumn( 'substatus_id' ), $this->column->substatus_id );
        }

        return $query;
    }

    /**
     * {@see self::builder()} in display order: position, then when the card
     * joined the board, then id.
     *
     * @since 1.2.0
     *
     * @return Builder<OrderBoardAssignment>
     */
    public function ordered(): Builder
    {
        $query = $this->builder();

        return $query
            ->orderBy( $query->qualifyColumn( 'position' ) )
            ->orderBy( $query->qualifyColumn( 'assigned_at' ) )
            ->orderBy( $query->qualifyColumn( 'id' ) );
    }

    /**
     * The first `$limit` cards.
     *
     * @since 1.2.0
     *
     * @param  int  $limit  How many.
     *
     * @return Collection<int, OrderBoardAssignment>
     */
    public function get( int $limit ): Collection
    {
        return $this->prepare( $this->ordered()->limit( max( 1, $limit ) )->get() );
    }

    /**
     * A cursor page of cards.
     *
     * @since 1.2.0
     *
     * @param  int          $perPage  Page size.
     * @param  string|null  $cursor   Cursor; null reads it from the request.
     *
     * @return CursorPaginator<int, OrderBoardAssignment>
     */
    public function cursorPaginate( int $perPage, ?string $cursor = null ): CursorPaginator
    {
        $paginator = $this->ordered()->cursorPaginate( max( 1, $perPage ), [ '*' ], 'cursor', $cursor );

        $this->prepare( new Collection( $paginator->items() ) );

        return $paginator;
    }

    /**
     * Card counts per column on the board, keyed by column id: `count` is
     * what the filters leave, `total` every active card (what a WIP limit
     * is checked against). Ignores {@see self::column()}.
     *
     * @since 1.2.0
     *
     * @param  BaseCollection<int, KanbanColumn>|null  $columns  The board's columns; loaded when null.
     *
     * @return array<int, array{count: int, total: int}>
     */
    public function countsByColumn( ?BaseCollection $columns = null ): array
    {
        $columns ??= $this->board->columns()->get();
        $totals    = $this->bySubstatus( OrderBoardAssignment::query()->active()->where( 'board_id', $this->board->id ) );
        $filtered  = $this->filters->isEmpty() ? $totals : $this->bySubstatus( $this->base() );
        $counts    = [];

        foreach ( $columns as $column ) {
            $counts[ (int) $column->id ] = [
                'count' => (int) ( $filtered[ $column->substatus_id ] ?? 0 ),
                'total' => (int) ( $totals[ $column->substatus_id ] ?? 0 ),
            ];
        }

        return $counts;
    }

    /**
     * The filtered active cards on the board.
     *
     * @since 1.2.0
     *
     * @return Builder<OrderBoardAssignment>
     */
    private function base(): Builder
    {
        $query   = OrderBoardAssignment::query()->active()->where( 'board_id', $this->board->id );
        $filters = $this->filters;

        if ( null !== $filters->movedBefore ) {
            $before = $filters->movedBefore;

            $query->where( static function ( Builder $stale ) use ( $before ): void {
                $stale->where( 'moved_at', '<', $before )
                    ->orWhere( static fn ( Builder $never ): Builder => $never->whereNull( 'moved_at' )->where( 'assigned_at', '<', $before ) );
            } );
        }

        if ( $this->filtersOrders() ) {
            $query->whereHas( 'order', fn ( Builder $orders ) => $this->filterOrders( $orders ) );
        }

        applyFilters( self::HOOK, $query, $filters, $this->board );

        return $query;
    }

    /**
     * Whether any order-level filter is set.
     *
     * @since 1.2.0
     *
     * @return bool
     */
    private function filtersOrders(): bool
    {
        $filters = $this->filters;

        return null !== $filters->search
            || null !== $filters->customerId
            || [] !== $filters->tags
            || [] !== $filters->productIds
            || null !== $filters->placedFrom
            || null !== $filters->placedTo
            || [] !== $filters->paymentStatus
            || [] !== $filters->fulfillmentStatus;
    }

    /**
     * Applies the order-level filters.
     *
     * @since 1.2.0
     *
     * @param  Builder<Order>  $orders  Order query.
     *
     * @return void
     */
    private function filterOrders( Builder $orders ): void
    {
        $filters = $this->filters;

        if ( null !== $filters->search ) {
            $this->search( $orders, $filters->search );
        }

        if ( null !== $filters->customerId ) {
            $orders->where( 'customer_id', $filters->customerId );
        }

        if ( [] !== $filters->tags ) {
            $tags = $filters->tags;

            if ( 'all' === $filters->tagMatch ) {
                foreach ( $tags as $tag ) {
                    $orders->whereJsonContains( 'meta->tags', $tag );
                }
            } else {
                $orders->where( static function ( Builder $any ) use ( $tags ): void {
                    foreach ( $tags as $tag ) {
                        $any->orWhereJsonContains( 'meta->tags', $tag );
                    }
                } );
            }
        }

        if ( [] !== $filters->productIds ) {
            $products = $filters->productIds;

            $orders->whereHas( 'items', static function ( Builder $items ) use ( $products ): void {
                $items->where( static function ( Builder $match ) use ( $products ): void {
                    $match->whereIn( 'product_id', $products )
                        ->orWhereIn( 'product_variant_id', ProductVariant::query()->whereIn( 'product_id', $products )->select( 'id' ) );
                } );
            } );
        }

        if ( null !== $filters->placedFrom ) {
            $orders->where( 'placed_at', '>=', $filters->placedFrom );
        }

        if ( null !== $filters->placedTo ) {
            $orders->where( 'placed_at', '<=', $filters->placedTo );
        }

        if ( [] !== $filters->paymentStatus ) {
            $orders->whereIn( 'payment_status', $filters->paymentStatus );
        }

        if ( [] !== $filters->fulfillmentStatus ) {
            $orders->whereIn( 'fulfillment_status', $filters->fulfillmentStatus );
        }
    }

    /**
     * Every word of `$search` must appear (case-insensitively) in the order
     * number, the email, a billing or shipping first / last name, or the
     * customer's name or email. Raw expressions wrap their columns with the
     * connection grammar, so a table prefix applies.
     *
     * @since 1.2.0
     *
     * @param  Builder<Order>  $orders  Order query.
     * @param  string          $search  Search text.
     *
     * @return void
     */
    private function search( Builder $orders, string $search ): void
    {
        $grammar = $orders->getQuery()->getGrammar();
        $model   = $orders->getModel();
        $columns = [ $model->qualifyColumn( 'order_number' ), $model->qualifyColumn( 'email' ) ];

        foreach ( [ 'billing_address', 'shipping_address' ] as $address ) {
            foreach ( [ 'first_name', 'last_name' ] as $key ) {
                $columns[] = $model->qualifyColumn( $address ) . '->' . $key;
            }
        }

        $terms = array_slice( preg_split( '/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY ) ?: [], 0, self::MAX_SEARCH_TERMS );

        foreach ( $terms as $term ) {
            $like = '%' . str_replace( [ '!', '%', '_' ], [ '!!', '!%', '!_' ], mb_strtolower( $term ) ) . '%';

            $orders->where( static function ( Builder $match ) use ( $columns, $grammar, $like ): void {
                foreach ( $columns as $column ) {
                    $match->orWhereRaw( 'LOWER(' . $grammar->wrap( $column ) . ") LIKE ? ESCAPE '!'", [ $like ] );
                }

                $match->orWhereHas( 'customer', static function ( Builder $customers ) use ( $grammar, $like ): void {
                    $customers->where( static function ( Builder $names ) use ( $grammar, $like ): void {
                        foreach ( [ 'first_name', 'last_name', 'email' ] as $column ) {
                            $names->orWhereRaw( 'LOWER(' . $grammar->wrap( $names->qualifyColumn( $column ) ) . ") LIKE ? ESCAPE '!'", [ $like ] );
                        }
                    } );
                } );
            } );
        }
    }

    /**
     * Active card counts per sub-status for `$query`.
     *
     * @since 1.2.0
     *
     * @param  Builder<OrderBoardAssignment>  $query  Card query.
     *
     * @return BaseCollection<int|string, mixed>
     */
    private function bySubstatus( Builder $query ): BaseCollection
    {
        return $query->reorder()
            ->select( $query->qualifyColumn( 'substatus_id' ) )
            ->selectRaw( 'COUNT(*) as aggregate' )
            ->groupBy( $query->qualifyColumn( 'substatus_id' ) )
            ->toBase()
            ->pluck( 'aggregate', 'substatus_id' );
    }

    /**
     * Drops cards whose order is gone and hands each order its own card, so
     * widgets that read the card (days in column) don't query for it.
     *
     * @since 1.2.0
     *
     * @param  Collection<int, OrderBoardAssignment>  $cards  Cards.
     *
     * @return Collection<int, OrderBoardAssignment>
     */
    private function prepare( Collection $cards ): Collection
    {
        $cards = $cards->filter( static fn ( OrderBoardAssignment $card ): bool => null !== $card->order )->values();

        foreach ( $cards as $card ) {
            $card->order->setRelation( 'boardAssignments', new Collection( [ $card ] ) );
        }

        return $cards;
    }
}
