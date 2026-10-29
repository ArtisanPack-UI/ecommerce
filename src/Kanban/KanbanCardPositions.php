<?php

/**
 * KanbanCardPositions.
 *
 * Writes the manual `position` of cards in a column
 * (ArtisanPack-UI/ecommerce#198). Placing a card at the top or bottom of a
 * column gives it the smallest position minus one or the largest plus one,
 * so no other card changes; placing it before or after another card
 * renumbers the column 0, 1, 2… and writes only the rows that changed.
 *
 * Callers hold a lock on the column's `ecommerce_kanban_columns` row: every
 * position write for a column is serialized on that row, so concurrent
 * placements can't interleave and produce duplicates.
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

use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
final class KanbanCardPositions
{
    /**
     * Places `$card` in the column of its (possibly unsaved) `substatus_id`
     * and saves it.
     *
     * A `before` / `after` card that isn't in the column places the card at
     * the bottom; a card placed before or after itself stays where it is.
     *
     * @since 1.2.0
     *
     * @param  OrderBoardAssignment  $card       The card.
     * @param  KanbanCardPlacement   $placement  Where it goes.
     *
     * @return bool Whether the column's order changed.
     */
    public function place( OrderBoardAssignment $card, KanbanCardPlacement $placement ): bool
    {
        $column   = $this->ordered( $card );
        $before   = $column->pluck( 'id' )->map( static fn ( mixed $id ): int => (int) $id )->all();
        $siblings = $column->reject( static fn ( OrderBoardAssignment $row ): bool => (int) $row->id === (int) $card->id )->values();
        // A card placed next to itself stays where it is.
        $index    = null !== $placement->orderId && $placement->orderId === (int) $card->order_id && in_array( (int) $card->id, $before, true )
            ? array_search( (int) $card->id, $before, true )
            : $this->index( $siblings->all(), $placement );

        $ids = $siblings->pluck( 'id' )->map( static fn ( mixed $id ): int => (int) $id )->all();
        array_splice( $ids, $index, 0, [ (int) $card->id ] );

        if ( $card->exists && $ids === $before ) {
            $card->save();

            return false;
        }

        if ( 0 === $index ) {
            $card->position = null === $siblings->first() ? 0 : (int) $siblings->min( 'position' ) - 1;
        } elseif ( $siblings->count() === $index ) {
            $card->position = (int) $siblings->max( 'position' ) + 1;
        } else {
            $this->renumber( $siblings->all(), $index, $card );
        }

        $card->save();

        return true;
    }

    /**
     * The position a new card takes at the `top` or `bottom` of a column.
     *
     * @since 1.2.0
     *
     * @param  int     $boardId      Board.
     * @param  int     $substatusId  The column's sub-status.
     * @param  string  $end          `top` or `bottom`.
     *
     * @return int
     */
    public function endPosition( int $boardId, int $substatusId, string $end ): int
    {
        $query = $this->column( $boardId, $substatusId );

        if ( ! $query->exists() ) {
            return 0;
        }

        return KanbanCardPlacement::TOP === $end ? (int) $query->min( 'position' ) - 1 : (int) $query->max( 'position' ) + 1;
    }

    /**
     * The active cards in `$card`'s target column, in display order.
     *
     * @since 1.2.0
     *
     * @param  OrderBoardAssignment  $card  The card.
     *
     * @return Collection<int, OrderBoardAssignment>
     */
    private function ordered( OrderBoardAssignment $card ): Collection
    {
        return $this->column( (int) $card->board_id, (int) $card->substatus_id )
            ->orderBy( 'position' )
            ->orderBy( 'assigned_at' )
            ->orderBy( 'id' )
            ->get( [ 'id', 'order_id', 'position' ] );
    }

    /**
     * @since 1.2.0
     *
     * @param  int  $boardId      Board.
     * @param  int  $substatusId  Sub-status.
     *
     * @return Builder<OrderBoardAssignment>
     */
    private function column( int $boardId, int $substatusId ): Builder
    {
        return OrderBoardAssignment::query()
            ->active()
            ->where( 'board_id', $boardId )
            ->where( 'substatus_id', $substatusId );
    }

    /**
     * Where `$placement` puts a card among `$siblings` (the column without it).
     *
     * @since 1.2.0
     *
     * @param  array<int, OrderBoardAssignment>  $siblings   The other cards, in order.
     * @param  KanbanCardPlacement               $placement  Placement.
     *
     * @return int
     */
    private function index( array $siblings, KanbanCardPlacement $placement ): int
    {
        if ( KanbanCardPlacement::TOP === $placement->mode ) {
            return 0;
        }

        foreach ( $siblings as $index => $sibling ) {
            if ( (int) $sibling->order_id === $placement->orderId ) {
                return KanbanCardPlacement::BEFORE === $placement->mode ? $index : $index + 1;
            }
        }

        return count( $siblings );
    }

    /**
     * Numbers the column 0, 1, 2… with `$card` at `$index`, writing only the
     * siblings whose position changes.
     *
     * @since 1.2.0
     *
     * @param  array<int, OrderBoardAssignment>  $siblings  The other cards, in order.
     * @param  int                               $index     The card's place.
     * @param  OrderBoardAssignment              $card      The card (saved by the caller).
     *
     * @return void
     */
    private function renumber( array $siblings, int $index, OrderBoardAssignment $card ): void
    {
        array_splice( $siblings, $index, 0, [ $card ] );

        foreach ( $siblings as $position => $row ) {
            if ( $row === $card ) {
                $card->position = $position;

                continue;
            }

            if ( (int) $row->position !== $position ) {
                OrderBoardAssignment::query()->whereKey( $row->id )->update( [ 'position' => $position ] );
            }
        }
    }
}
