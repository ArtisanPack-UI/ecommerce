<?php

/**
 * PrintShippingLabelTrigger.
 *
 * `print-shipping-label`: buys a label through a `shipping-labels-*`
 * satellite (any provider registered in ShippingLabelProviderRegistry).
 * Config:
 *
 * - `provider`   — Label provider registry key (required).
 * - `method_key` — Used only when a shipment has to be created first;
 *                  defaults to the order's `shipping_method_key`.
 *
 * Labels the newest shipment that doesn't have one yet, creating a
 * shipment for every unshipped unit when there is none.
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
use ArtisanPackUI\Ecommerce\Registries\ShippingLabelProviderRegistry;
use ArtisanPackUI\Ecommerce\Services\ShipmentService;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PrintShippingLabelTrigger extends AbstractKanbanAutomationTrigger
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'print-shipping-label';

    /**
     * @since 1.0.0
     *
     * @param  ShipmentService                $shipments  Shipment service.
     * @param  ShippingLabelProviderRegistry  $providers  Label providers.
     */
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly ShippingLabelProviderRegistry $providers,
    ) {
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
        return __( 'Print a shipping label' );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order                 $order       Order.
     * @param  KanbanAutomation      $automation  Automation.
     * @param  array<string, mixed>  $config      See class docblock.
     *
     * @throws InvalidArgumentException When the provider isn't registered or no shipment can be made.
     *
     * @return void
     */
    public function fire( Order $order, KanbanAutomation $automation, array $config ): void
    {
        $provider = $this->requireString( $config, 'provider' );

        if ( ! $this->providers->has( $provider ) ) {
            throw new InvalidArgumentException( sprintf( 'No shipping label provider is registered under "%s".', $provider ) );
        }

        $shipment = $order->shipments()->whereNull( 'label_id' )->latest( 'id' )->first();

        if ( null === $shipment ) {
            $method = $this->optionalString( $config, 'method_key' ) ?? $order->shipping_method_key;

            if ( null === $method || '' === $method ) {
                throw new InvalidArgumentException( 'The "print-shipping-label" trigger needs a "method_key" to create a shipment.' );
            }

            $shipment = $this->shipments->create( $order, $method );
        }

        $this->shipments->buyLabel( $shipment, $provider );
    }
}
