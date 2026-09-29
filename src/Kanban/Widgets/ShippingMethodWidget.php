<?php

/**
 * ShippingMethodWidget.
 *
 * `shipping-method`: the order's shipping method, labelled from the shipping
 * method type registry when the key is a built-in driver.
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
use ArtisanPackUI\Ecommerce\Registries\ShippingMethodTypeRegistry;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ShippingMethodWidget extends AbstractKanbanCardWidget
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'shipping-method';

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
        return __( 'Shipping' );
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
        $key = (string) ( $order->shipping_method_key ?? '' );

        if ( '' === $key ) {
            return $this->payload( __( 'None' ) );
        }

        $registry = app( ShippingMethodTypeRegistry::class );
        $label    = $registry->has( $key ) ? (string) ( $registry->meta( $key )['label'] ?? $key ) : $this->humanize( $key );

        return $this->payload( $label, 'neutral', [ 'icon' => 'truck' ] );
    }
}
