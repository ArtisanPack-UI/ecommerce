<?php

/**
 * TotalWidget.
 *
 * `total`: the order total in the order currency ("$124.00"). Refunds show
 * in the tooltip and turn the tone to `warning`.
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
use ArtisanPackUI\Ecommerce\Support\MoneyFormatter;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class TotalWidget extends AbstractKanbanCardWidget
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'total';

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
        return __( 'Total' );
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
        $total    = $this->format( (int) $order->total_amount, (string) $order->total_currency );
        $refunded = (int) $order->total_refunded_amount;

        if ( $refunded > 0 ) {
            return $this->payload( $total, 'warning', [
                'tooltip' => __( ':amount refunded', [ 'amount' => $this->format( $refunded, (string) ( $order->total_refunded_currency ?? $order->total_currency ) ) ] ),
            ] );
        }

        return $this->payload( $total );
    }

    /**
     * Formats minor units for display in the active locale.
     *
     * @since 1.0.0
     *
     * @param  int     $amount    Minor units.
     * @param  string  $currency  ISO 4217 code.
     *
     * @return string
     */
    protected function format( int $amount, string $currency ): string
    {
        return MoneyFormatter::format( $amount, $currency );
    }
}
