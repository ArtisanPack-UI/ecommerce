<?php

/**
 * OrderSubstatusController.
 *
 * `admin/order-substatuses` (engine issue #143): list, create, update,
 * reorder within a system status, and delete, through
 * {@see OrderSubstatusService}. Refusals render as 422
 * `substatus-write-failed` problems.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\OrderSubstatusRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ReorderOrderSubstatusesRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\OrderSubstatusResource;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\OrderSubstatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderSubstatusController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  OrderSubstatusService  $substatuses  Sub-status writes.
     */
    public function __construct( private readonly OrderSubstatusService $substatuses )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List order sub-statuses', resource: OrderSubstatusResource::class, collection: true )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            OrderSubstatus::query(),
            $request,
            OrderSubstatusResource::class,
            [ 'system_status' => 'system_status', 'key' => 'key' ],
            [ 'position' => 'position', 'key' => 'key', 'label' => 'label' ],
            [],
            'position',
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  OrderSubstatusRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create an order sub-status', resource: OrderSubstatusResource::class, status: 201 )]
    public function store( OrderSubstatusRequest $request ): JsonResponse
    {
        return $this->resourceResponse( $this->substatuses->create( $request->validated() ), $request, OrderSubstatusResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  OrderSubstatusRequest  $request    Validated request.
     * @param  OrderSubstatus         $substatus  Sub-status.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update an order sub-status', resource: OrderSubstatusResource::class )]
    public function update( OrderSubstatusRequest $request, OrderSubstatus $substatus ): JsonResponse
    {
        return $this->resourceResponse( $this->substatuses->update( $substatus, $request->validated() ), $request, OrderSubstatusResource::class );
    }

    /**
     * Refused (422) while orders, board cards, or kanban columns use the
     * sub-status, or when it is the last one of its system status.
     *
     * @since 1.0.0
     *
     * @param  Request         $request    Request.
     * @param  OrderSubstatus  $substatus  Sub-status.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete an order sub-status', resource: OrderSubstatusResource::class )]
    public function destroy( Request $request, OrderSubstatus $substatus ): JsonResponse
    {
        $this->substatuses->delete( $substatus );

        return $this->resourceResponse( $substatus, $request, OrderSubstatusResource::class );
    }

    /**
     * Sets the order of one system status's sub-statuses.
     *
     * @since 1.0.0
     *
     * @param  ReorderOrderSubstatusesRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Reorder the sub-statuses of a system status', resource: OrderSubstatusResource::class, collection: true )]
    public function reorder( ReorderOrderSubstatusesRequest $request ): JsonResponse
    {
        return OrderSubstatusResource::collection(
            $this->substatuses->reorder( (string) $request->validated( 'system_status' ), (array) $request->validated( 'ids' ) ),
        )->response( $request );
    }
}
