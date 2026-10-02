<?php

/**
 * CustomerAddressController.
 *
 * `POST customers/{customer}/addresses`,
 * `PATCH customers/{customer}/addresses/{address}`, and
 * `DELETE customers/{customer}/addresses/{address}` (engine issue #142).
 * Writes go through {@see CustomerAddressService}, which handles default
 * shipping / billing and records the `address.*` activity entries.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CustomerAddressRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\CustomerAddressResource;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerAddress;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\CustomerAddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerAddressController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  CustomerAddressService  $addresses  Customer addresses.
     */
    public function __construct( private readonly CustomerAddressService $addresses )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  CustomerAddressRequest  $request   Validated request.
     * @param  Customer                $customer  Customer.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Add an address to a customer', resource: CustomerAddressResource::class, status: 201 )]
    public function store( CustomerAddressRequest $request, Customer $customer ): JsonResponse
    {
        $address = $this->addresses->create( $customer, $request->validated(), $this->actorId( $request ) );

        return $this->resourceResponse( $address, $request, CustomerAddressResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  CustomerAddressRequest  $request   Validated request.
     * @param  Customer                $customer  Customer.
     * @param  CustomerAddress         $address   Address (scoped to the customer).
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a customer address', resource: CustomerAddressResource::class )]
    public function update( CustomerAddressRequest $request, Customer $customer, CustomerAddress $address ): JsonResponse
    {
        $address = $this->addresses->update( $address, $request->validated(), $this->actorId( $request ) );

        return $this->resourceResponse( $address, $request, CustomerAddressResource::class );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request          $request   Request.
     * @param  Customer         $customer  Customer.
     * @param  CustomerAddress  $address   Address (scoped to the customer).
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a customer address', resource: CustomerAddressResource::class )]
    public function destroy( Request $request, Customer $customer, CustomerAddress $address ): JsonResponse
    {
        $this->addresses->delete( $address, $this->actorId( $request ) );

        return $this->resourceResponse( $address, $request, CustomerAddressResource::class );
    }

    /**
     * The acting user's id, when numeric.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return int|null
     */
    private function actorId( Request $request ): ?int
    {
        $actor = $request->user()?->getAuthIdentifier();

        return is_numeric( $actor ) ? (int) $actor : null;
    }
}
