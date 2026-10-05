<?php

/**
 * ShippingZoneController.
 *
 * CRUD for `admin/shipping-zones` (engine spec §9.8).
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ShippingZoneRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\ShippingZoneResource;
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
class ShippingZoneController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    private const INCLUDES = [ 'methods' => 'methods' ];

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List shipping zones', resource: ShippingZoneResource::class, collection: true, filters: [ 'is_active' => 'boolean' ], sorts: [ 'priority', 'name' ], includes: self::INCLUDES )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse( ShippingZone::query(), $request, ShippingZoneResource::class, [ 'is_active' => [ 'is_active', 'bool' ] ], [ 'priority' => 'priority', 'name' => 'name' ], self::INCLUDES, 'priority' );
    }

    /**
     * @since 1.0.0
     *
     * @param  ShippingZoneRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create a shipping zone', resource: ShippingZoneResource::class, status: 201, includes: self::INCLUDES )]
    public function store( ShippingZoneRequest $request ): JsonResponse
    {
        return $this->resourceResponse( ShippingZone::query()->create( $request->validated() ), $request, ShippingZoneResource::class, self::INCLUDES, 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  ShippingZoneRequest  $request  Validated request.
     * @param  ShippingZone         $zone     Zone.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a shipping zone', resource: ShippingZoneResource::class, includes: self::INCLUDES )]
    public function update( ShippingZoneRequest $request, ShippingZone $zone ): JsonResponse
    {
        $zone->fill( $request->validated() )->save();

        return $this->resourceResponse( $zone, $request, ShippingZoneResource::class, self::INCLUDES );
    }

    /**
     * Deletes the zone (its methods cascade).
     *
     * @since 1.0.0
     *
     * @param  Request       $request  Request.
     * @param  ShippingZone  $zone     Zone.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a shipping zone', resource: ShippingZoneResource::class )]
    public function destroy( Request $request, ShippingZone $zone ): JsonResponse
    {
        $zone->delete();

        return $this->resourceResponse( $zone, $request, ShippingZoneResource::class );
    }
}
