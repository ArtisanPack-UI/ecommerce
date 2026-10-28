<?php

/**
 * DaysInColumnWidget.
 *
 * `days-in-column`: whole days the card has sat in its current column.
 * Turns `warning` after `stale_after_days` days (per instance, else
 * `artisanpack.ecommerce.kanban.stale_after_days`) and `danger` after
 * twice that.
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
use ArtisanPackUI\Ecommerce\Support\ConfigField;
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
     * `stale_after_days`: days before the card turns `warning`.
     *
     * @since 1.2.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [
            ConfigField::make( 'stale_after_days', 'number', __( 'Stale after (days)' ), [
                'rules' => [ 'integer', 'min:1' ],
                'help'  => __( 'The card turns amber after this many days in the column and red after twice as many. Leave empty for the store default.' ),
            ] ),
        ];
    }

    /**
     * @since 1.0.0
     * @since 1.2.0 Reads `stale_after_days` from the instance config.
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
        $stale = max( 1, (int) $this->config( 'stale_after_days', config( 'artisanpack.ecommerce.kanban.stale_after_days', 3 ) ) );
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
