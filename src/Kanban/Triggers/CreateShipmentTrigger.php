<?php

/**
 * CreateShipmentTrigger.
 *
 * `create-shipment`: ships every unshipped unit on the order. Config:
 *
 * - `method_key` — Shipping method key; defaults to the order's
 *                  `shipping_method_key`.
 * - `carrier`, `service` — Optional shipment details.
 *
 * Throws (and so is logged as a failed automation) when the order has
 * nothing left to ship.
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
use ArtisanPackUI\Ecommerce\Services\ShipmentService;
use ArtisanPackUI\Ecommerce\Support\ConfigField;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CreateShipmentTrigger extends AbstractKanbanAutomationTrigger
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'create-shipment';

    /**
     * @since 1.0.0
     *
     * @param  ShipmentService  $shipments  Shipment service.
     */
    public function __construct( private readonly ShipmentService $shipments )
    {
    }

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
        return __( 'Create a shipment' );
    }

    /**
     * Fields this trigger's `config` takes (engine issue #149).
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [
            ConfigField::make( 'method_key', 'text', __( 'Shipping method' ), [ 'rules' => [ 'max:120' ], 'help' => __( 'Defaults to the order\'s shipping method.' ) ] ),
            ConfigField::make( 'carrier', 'text', __( 'Carrier' ), [ 'rules' => [ 'max:120' ] ] ),
            ConfigField::make( 'service', 'text', __( 'Service' ), [ 'rules' => [ 'max:120' ] ] ),
        ];
    }

    /**
     * @since 1.0.0
     *
     * @param  Order                 $order       Order.
     * @param  KanbanAutomation      $automation  Automation.
     * @param  array<string, mixed>  $config      See class docblock.
     *
     * @throws InvalidArgumentException When no method is known or nothing can be shipped.
     *
     * @return void
     */
    public function fire( Order $order, KanbanAutomation $automation, array $config ): void
    {
        $method = $this->optionalString( $config, 'method_key' ) ?? $order->shipping_method_key;

        if ( null === $method || '' === $method ) {
            throw new InvalidArgumentException( 'The "create-shipment" trigger needs a "method_key" (the order has no shipping method).' );
        }

        $this->shipments->create( $order, $method, [], array_filter( [
            'carrier' => $this->optionalString( $config, 'carrier' ),
            'service' => $this->optionalString( $config, 'service' ),
        ] ) );
    }
}
