<?php

/**
 * PaymentStatusWidget.
 *
 * `payment-status`: the order's payment status with a matching tone.
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
class PaymentStatusWidget extends AbstractKanbanCardWidget
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'payment-status';

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
        return __( 'Payment' );
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
        $status = (string) $order->payment_status;

        return $this->payload( $this->humanize( $status ), match ( $status ) {
            'paid'                                 => 'success',
            'pending'                              => 'warning',
            'failed'                               => 'danger',
            'refunded', 'partially_refunded'       => 'info',
            default                                => 'neutral',
        } );
    }
}
