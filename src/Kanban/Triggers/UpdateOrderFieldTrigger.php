<?php

/**
 * UpdateOrderFieldTrigger.
 *
 * `update-order-field`: sets a simple value on the order. Config:
 *
 * - `field` — `meta.<path>` (dot path inside the order's `meta`), or a
 *             top-level column allowed by the
 *             `ap.ecommerce.kanban.updatableOrderFields` filter (empty by
 *             default, so money and status columns can never be written
 *             from admin data).
 * - `value` — Scalar, null, or array.
 *
 * The change is written to the order timeline.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Kanban\Triggers;

use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class UpdateOrderFieldTrigger extends AbstractKanbanAutomationTrigger
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'update-order-field';

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
        return __( 'Update an order field' );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order                 $order       Order.
     * @param  KanbanAutomation      $automation  Automation.
     * @param  array<string, mixed>  $config      See class docblock.
     *
     * @throws InvalidArgumentException When the field is not writable or the value is an object.
     *
     * @return void
     */
    public function fire( Order $order, KanbanAutomation $automation, array $config ): void
    {
        $field = $this->requireString( $config, 'field' );
        $value = $config['value'] ?? null;

        if ( is_object( $value ) ) {
            throw new InvalidArgumentException( 'The "update-order-field" value must be a scalar, null, or array.' );
        }

        if ( str_starts_with( $field, 'meta.' ) ) {
            $path = substr( $field, 5 );

            if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', $path ) ) {
                throw new InvalidArgumentException( sprintf( 'Invalid meta path "%s".', $path ) );
            }

            $meta = (array) ( $order->meta ?? [] );
            data_set( $meta, $path, $value );
            $order->meta = $meta;
        } elseif ( in_array( $field, (array) applyFilters( 'ap.ecommerce.kanban.updatableOrderFields', [], $order ), true ) ) {
            $order->setAttribute( $field, $value );
        } else {
            throw new InvalidArgumentException( sprintf( 'Order field "%s" cannot be updated by an automation.', $field ) );
        }

        $order->save();

        OrderTimelineEntry::query()->create( [
            'order_id'      => $order->id,
            'actor_user_id' => null,
            'event_type'    => 'kanban.order_field_updated',
            'payload'       => [ 'automation_id' => $automation->id, 'field' => $field ],
        ] );
    }
}
