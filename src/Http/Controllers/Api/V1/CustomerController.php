<?php

/**
 * CustomerController.
 *
 * `GET customers`, `GET customers/{customer}`, `PATCH customers/{customer}`
 * (engine spec §9.4). Admin-gated.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateCustomerRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\CustomerResource;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List customers', resource: CustomerResource::class, collection: true )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            Customer::query(),
            $request,
            CustomerResource::class,
            [ 'email' => 'email', 'user_id' => [ 'user_id', 'int' ] ],
            [ 'created_at' => 'created_at', 'orders_count' => 'orders_count', 'total_spent' => 'total_spent_amount' ],
            [ 'addresses' => 'addresses' ],
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request   $request   Request.
     * @param  Customer  $customer  Customer.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Get a customer', resource: CustomerResource::class )]
    public function show( Request $request, Customer $customer ): JsonResponse
    {
        return $this->resourceResponse( $customer, $request, CustomerResource::class, [ 'addresses' => 'addresses' ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  UpdateCustomerRequest  $request   Validated request.
     * @param  Customer               $customer  Customer.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a customer', resource: CustomerResource::class )]
    public function update( UpdateCustomerRequest $request, Customer $customer ): JsonResponse
    {
        $data = $request->validated();

        if ( array_key_exists( 'accepts_marketing', $data ) && (bool) $data['accepts_marketing'] !== (bool) $customer->accepts_marketing ) {
            $data['accepts_marketing_at'] = $data['accepts_marketing'] ? Carbon::now() : null;
        }

        if ( array_key_exists( 'meta', $data ) ) {
            $data['meta'] = array_replace_recursive( (array) ( $customer->meta ?? [] ), (array) $data['meta'] );
        }

        $customer->fill( $data )->save();

        return $this->resourceResponse( $customer, $request, CustomerResource::class, [ 'addresses' => 'addresses' ] );
    }
}
