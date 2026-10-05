<?php

/**
 * DaysInColumnWidget.
 *
 * `days-in-column`: whole days the card has sat in its current column.
 * Turns `warning` after `artisanpack.ecommerce.kanban.stale_after_days`
 * and `danger` after twice that.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Kanban\Widgets;

use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Support\LocalizedDate;
use Illuminate\Support\Carbon;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DaysInColumnWidget extends AbstractKanbanCardWidget
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'days-in-column';

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return __( 'Days in column' );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order         $order   Order.
     * @param  KanbanColumn  $column  Column.
     *
     * @return array<string, string>
     */
    public function render( Order $order, KanbanColumn $column ): array
    {
        $assignment = $order->relationLoaded( 'boardAssignments' )
            ? $order->boardAssignments->firstWhere( 'board_id', $column->board_id )
            : OrderBoardAssignment::query()
                ->where( 'order_id', $order->id )
                ->where( 'board_id', $column->board_id )
                ->first();

        $since = $assignment?->moved_at ?? $assignment?->assigned_at;

        if ( null === $since ) {
            return $this->payload( '0' );
        }

        $days  = (int) floor( $since->diffInDays( Carbon::now(), true ) );
        $stale = max( 1, (int) config( 'artisanpack.ecommerce.kanban.stale_after_days', 3 ) );
        $tone  = match ( true ) {
            $days >= 2 * $stale => 'danger',
            $days >= $stale     => 'warning',
            default             => 'neutral',
        };

        return $this->payload( (string) $days, $tone, [
            'tooltip' => __( 'In this column since :date', [ 'date' => LocalizedDate::format( $since ) ] ),
        ] );
    }
}
