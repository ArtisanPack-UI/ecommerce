<?php

/**
 * OrderShipmentController.
 *
 * `POST orders/{order}/shipments` and
 * `PATCH orders/{order}/shipments/{shipment}` (engine spec §9.3), both via
 * {@see ShipmentService} so quantity guards and shipment hooks apply.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1;

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\StoreShipmentRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateShipmentRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\ShipmentResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Shipment;
use ArtisanPackUI\Ecommerce\Services\ShipmentService;
use ArtisanPackUI\Ecommerce\ValueObjects\TrackingStatus;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderShipmentController extends ApiController
{
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
     * @param  StoreShipmentRequest  $request  Validated request.
     * @param  Order                 $order    Order.
     *
     * @return JsonResponse
     */
    public function store( StoreShipmentRequest $request, Order $order ): JsonResponse
    {
        $quantities = [];

        foreach ( (array) $request->validated( 'items', [] ) as $line ) {
            $quantities[ (int) $line['order_item_id'] ] = ( $quantities[ (int) $line['order_item_id'] ] ?? 0 ) + (int) $line['quantity'];
        }

        try {
            $shipment = $this->shipments->create(
                $order,
                (string) $request->validated( 'method_key' ),
                $quantities,
                $request->safe()->except( [ 'method_key', 'items' ] ),
            );
        } catch ( InvalidArgumentException $e ) {
            return Problem::make( 422, 'shipment-rejected', __( 'Shipment rejected' ), $e->getMessage(), $request );
        }

        return $this->resourceResponse( $shipment, $request, ShipmentResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  UpdateShipmentRequest  $request   Validated request.
     * @param  Order                  $order     Order.
     * @param  Shipment               $shipment  Shipment (scoped to $order).
     *
     * @return JsonResponse
     */
    public function update( UpdateShipmentRequest $request, Order $order, Shipment $shipment ): JsonResponse
    {
        $data = $request->validated();

        // Assign directly so an explicit null clears a field.
        $shipment->fill( array_intersect_key( $data, array_flip( [ 'carrier', 'service', 'tracking_number', 'tracking_url' ] ) ) );

        $this->shipments->updateTracking( $shipment, new TrackingStatus(
            status: (string) ( $data['status'] ?? $shipment->status ),
        ) );

        return $this->resourceResponse( $shipment->load( 'items' ), $request, ShipmentResource::class );
    }
}
