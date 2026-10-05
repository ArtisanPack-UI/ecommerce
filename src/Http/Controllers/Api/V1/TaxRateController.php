<?php

/**
 * TaxRateController.
 *
 * CRUD for `admin/tax-rates` (engine spec §9.8).
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\TaxRateRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\TaxRateResource;
use ArtisanPackUI\Ecommerce\Models\TaxRate;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class TaxRateController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List tax rates', resource: TaxRateResource::class, collection: true, filters: [ 'tax_class_key' => 'string', 'country_code' => 'string', 'region_code' => 'string', 'is_active' => 'boolean' ], sorts: [ 'priority', 'country_code' ] )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            TaxRate::query(),
            $request,
            TaxRateResource::class,
            [ 'tax_class_key' => 'tax_class_key', 'country_code' => 'country_code', 'region_code' => 'region_code', 'is_active' => [ 'is_active', 'bool' ] ],
            [ 'priority' => 'priority', 'country_code' => 'country_code' ],
            [],
            'country_code,priority',
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  TaxRateRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create a tax rate', resource: TaxRateResource::class, status: 201 )]
    public function store( TaxRateRequest $request ): JsonResponse
    {
        return $this->resourceResponse( TaxRate::query()->create( $request->validated() ), $request, TaxRateResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  TaxRateRequest  $request  Validated request.
     * @param  TaxRate         $rate     Rate.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a tax rate', resource: TaxRateResource::class )]
    public function update( TaxRateRequest $request, TaxRate $rate ): JsonResponse
    {
        $rate->fill( $request->validated() )->save();

        return $this->resourceResponse( $rate, $request, TaxRateResource::class );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  TaxRate  $rate     Rate.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a tax rate', resource: TaxRateResource::class )]
    public function destroy( Request $request, TaxRate $rate ): JsonResponse
    {
        $rate->delete();

        return $this->resourceResponse( $rate, $request, TaxRateResource::class );
    }
}
