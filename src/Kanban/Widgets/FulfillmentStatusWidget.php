<?php

/**
 * FulfillmentStatusWidget.
 *
 * `fulfillment-status`: the order's fulfillment status with a matching tone.
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
class FulfillmentStatusWidget extends AbstractKanbanCardWidget
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'fulfillment-status';

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
        return __( 'Fulfillment' );
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
        $status = (string) $order->fulfillment_status;

        return $this->payload( $this->humanize( $status ), match ( $status ) {
            'fulfilled' => 'success',
            'partial'   => 'warning',
            default     => 'neutral',
        } );
    }
}
