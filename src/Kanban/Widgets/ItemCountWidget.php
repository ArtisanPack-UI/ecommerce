<?php

/**
 * ItemCountWidget.
 *
 * `item-count`: total units across the order's lines ("3 items").
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

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ItemCountWidget extends AbstractKanbanCardWidget
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'item-count';

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
        return __( 'Items' );
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
        $count = (int) $order->items()->sum( 'quantity' );

        return $this->payload( trans_choice( ':count item|:count items', $count, [ 'count' => $count ] ) );
    }
}
