<?php

/**
 * ShippingMethodController.
 *
 * `POST admin/shipping-zones/{zone}/methods`,
 * `PATCH|DELETE admin/shipping-methods/{method}` (engine spec §9.8).
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ShippingMethodRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\ShippingMethodResource;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ShippingMethodController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  ShippingMethodRequest  $request  Validated request.
     * @param  ShippingZone           $zone     Zone.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Add a shipping method to a zone', resource: ShippingMethodResource::class, status: 201 )]
    public function store( ShippingMethodRequest $request, ShippingZone $zone ): JsonResponse
    {
        $method = $zone->methods()->create( $request->validated() + [ 'config' => [] ] );

        return $this->resourceResponse( $method, $request, ShippingMethodResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  ShippingMethodRequest  $request  Validated request.
     * @param  ShippingMethod         $method   Method.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a shipping method', resource: ShippingMethodResource::class )]
    public function update( ShippingMethodRequest $request, ShippingMethod $method ): JsonResponse
    {
        $method->fill( $request->validated() )->save();

        return $this->resourceResponse( $method, $request, ShippingMethodResource::class );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request         $request  Request.
     * @param  ShippingMethod  $method   Method.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a shipping method', resource: ShippingMethodResource::class )]
    public function destroy( Request $request, ShippingMethod $method ): JsonResponse
    {
        $method->delete();

        return $this->resourceResponse( $method, $request, ShippingMethodResource::class );
    }
}
