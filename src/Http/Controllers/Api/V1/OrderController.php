<?php

/**
 * OrderController.
 *
 * `GET orders`, `GET orders/{order}`, `PATCH orders/{order}` (engine spec
 * §9.3). Admin-gated by the `ecommerce.can:order,*` route middleware.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateOrderRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\OrderResource;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const INCLUDES = [
        'items'            => 'items',
        'customer'         => 'customer',
        'notes'            => 'notes',
        'timeline'         => 'timelineEntries',
        'edits'            => 'edits',
        'refunds'          => 'refunds',
        'refunds.items'    => 'refunds.items',
        'shipments'        => 'shipments',
        'shipments.items'  => 'shipments.items',
        'promotion_usages' => 'promotionUsages',
    ];

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List orders', resource: OrderResource::class, collection: true )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            Order::query(),
            $request,
            OrderResource::class,
            [
                'system_status'      => 'system_status',
                'payment_status'     => 'payment_status',
                'fulfillment_status' => 'fulfillment_status',
                'customer_id'        => [ 'customer_id', 'int' ],
                'email'              => 'email',
                'order_number'       => 'order_number',
            ],
            [ 'created_at' => 'created_at', 'total' => 'total_amount' ],
            self::INCLUDES,
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  Order    $order    Order.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Get an order', resource: OrderResource::class )]
    public function show( Request $request, Order $order ): JsonResponse
    {
        return $this->resourceResponse( $order, $request, OrderResource::class, self::INCLUDES );
    }

    /**
     * @since 1.0.0
     *
     * @param  UpdateOrderRequest  $request  Validated request.
     * @param  Order               $order    Order.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update an order', resource: OrderResource::class )]
    public function update( UpdateOrderRequest $request, Order $order ): JsonResponse
    {
        $data = $request->validated();

        // PATCH semantics: merge meta rather than replacing it, so a client
        // updating one key can't erase the engine's (or a satellite's).
        if ( array_key_exists( 'meta', $data ) ) {
            $data['meta'] = array_replace_recursive( (array) ( $order->meta ?? [] ), (array) $data['meta'] );
        }

        $order->fill( $data )->save();

        return $this->resourceResponse( $order, $request, OrderResource::class, self::INCLUDES );
    }
}
