<?php

/**
 * OrderTimelineController.
 *
 * `GET orders/{order}/timeline` (engine spec §9.3): the order's
 * append-only timeline, newest first, cursor-paginated and filterable by
 * `event_type`.
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

use ArtisanPackUI\Ecommerce\Http\Resources\OrderTimelineEntryResource;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderTimelineController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  Order    $order    Order.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List an order\'s timeline', resource: OrderTimelineEntryResource::class, collection: true, filters: [ 'event_type' => 'string' ], sorts: [ 'id' ] )]
    public function index( Request $request, Order $order ): JsonResponse
    {
        return $this->listResponse(
            OrderTimelineEntry::query()->where( 'order_id', $order->id ),
            $request,
            OrderTimelineEntryResource::class,
            [ 'event_type' => 'event_type' ],
            [ 'id' => 'id' ],
        );
    }
}
