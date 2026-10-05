<?php

/**
 * CustomerWidget.
 *
 * `customer`: the shopper's name (from the billing, then shipping address)
 * with their email as the tooltip; guests show their email.
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
class CustomerWidget extends AbstractKanbanCardWidget
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'customer';

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
        return __( 'Customer' );
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
        $address = (array) ( $order->billing_address ?? [] ) ?: (array) ( $order->shipping_address ?? [] );
        $name    = trim( ( (string) ( $address['first_name'] ?? '' ) ) . ' ' . ( (string) ( $address['last_name'] ?? '' ) ) );

        return '' === $name
            ? $this->payload( (string) $order->email )
            : $this->payload( $name, 'neutral', [ 'tooltip' => (string) $order->email ] );
    }
}
