<?php

/**
 * TaxClassController.
 *
 * `GET admin/tax-classes`, `POST admin/tax-classes` (engine spec §9.8).
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\TaxClassRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\TaxClassResource;
use ArtisanPackUI\Ecommerce\Models\TaxClass;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class TaxClassController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List tax classes', resource: TaxClassResource::class, collection: true )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse( TaxClass::query(), $request, TaxClassResource::class, [ 'key' => 'key' ], [ 'key' => 'key' ], [ 'rates' => 'rates' ], 'key' );
    }

    /**
     * @since 1.0.0
     *
     * @param  TaxClassRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create a tax class', resource: TaxClassResource::class, status: 201 )]
    public function store( TaxClassRequest $request ): JsonResponse
    {
        return $this->resourceResponse( TaxClass::query()->create( $request->validated() ), $request, TaxClassResource::class, [], 201 );
    }
}
