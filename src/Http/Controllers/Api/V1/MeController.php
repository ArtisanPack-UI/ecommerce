<?php

/**
 * MeController.
 *
 * The signed-in shopper's own data (engine spec §9.4; #173): profile
 * (`GET/PATCH me`), saved addresses (`me/addresses`), order history
 * (`GET me/orders`, `GET me/orders/{order}`), and claiming guest orders
 * placed under their email (`POST me/claims`). Every route needs a token
 * that allows storefront access and a customer record linked to the user;
 * everything is scoped to that record, so another customer's order or
 * address is a 404.
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

use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\Exceptions\ClaimRateLimitedException;
use ArtisanPackUI\Ecommerce\Exceptions\ClaimVerificationFailedException;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ClaimOrdersRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CustomerAddressRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateMeRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\CustomerAddressResource;
use ArtisanPackUI\Ecommerce\Http\Resources\CustomerResource;
use ArtisanPackUI\Ecommerce\Http\Resources\OrderResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerAddress;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\CustomerAddressService;
use ArtisanPackUI\Ecommerce\Services\CustomerClaimService;
use ArtisanPackUI\Ecommerce\Services\CustomerOrderHistory;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class MeController extends ApiController
{
    /**
     * Relations a shopper may include on their orders.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    private const ORDER_INCLUDES = [
        'items'          => 'items',
        'shipments'      => 'shipments',
        'refunds'        => 'refunds',
        'customer_notes' => 'customerNotes',
    ];

    /**
     * @since 1.0.0
     *
     * @param  CustomerService         $customers  Profile.
     * @param  CustomerAddressService  $addresses  Addresses.
     * @param  CustomerOrderHistory    $history    Orders.
     * @param  CustomerClaimService    $claims     Guest-order claims.
     */
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerAddressService $addresses,
        private readonly CustomerOrderHistory $history,
        private readonly CustomerClaimService $claims,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Get my customer profile', resource: CustomerResource::class )]
    public function show( Request $request ): JsonResponse
    {
        return $this->forCustomer( $request, fn ( Customer $customer ): JsonResponse => $this->resourceResponse( $customer, $request, CustomerResource::class ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  UpdateMeRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update my customer profile', resource: CustomerResource::class )]
    public function update( UpdateMeRequest $request ): JsonResponse
    {
        return $this->forCustomer( $request, fn ( Customer $customer ): JsonResponse => $this->resourceResponse(
            $this->customers->updateProfile( $customer, $request->validated() ),
            $request,
            CustomerResource::class,
        ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List my saved addresses', resource: CustomerAddressResource::class, collection: true )]
    public function addresses( Request $request ): JsonResponse
    {
        return $this->forCustomer( $request, fn ( Customer $customer ): JsonResponse => CustomerAddressResource::collection( $customer->addresses()->orderBy( 'id' )->get() )->response( $request ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  CustomerAddressRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Save an address', resource: CustomerAddressResource::class, status: 201 )]
    public function storeAddress( CustomerAddressRequest $request ): JsonResponse
    {
        return $this->forCustomer( $request, fn ( Customer $customer ): JsonResponse => $this->resourceResponse(
            $this->addresses->create( $customer, $request->validated() ),
            $request,
            CustomerAddressResource::class,
            [],
            201,
        ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  CustomerAddressRequest  $request  Validated request.
     * @param  int                     $address  Address id.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a saved address', resource: CustomerAddressResource::class )]
    public function updateAddress( CustomerAddressRequest $request, int $address ): JsonResponse
    {
        return $this->forCustomer( $request, fn ( Customer $customer ): JsonResponse => $this->resourceResponse(
            $this->addresses->update( $this->address( $customer, $address ), $request->validated() ),
            $request,
            CustomerAddressResource::class,
        ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  int      $address  Address id.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a saved address', resource: CustomerAddressResource::class )]
    public function destroyAddress( Request $request, int $address ): JsonResponse
    {
        return $this->forCustomer( $request, function ( Customer $customer ) use ( $request, $address ): JsonResponse {
            $model = $this->address( $customer, $address );
            $this->addresses->delete( $model );

            return $this->resourceResponse( $model, $request, CustomerAddressResource::class );
        } );
    }

    /**
     * My orders, newest first. `filter[status]` takes `open`, `completed`,
     * `cancelled`, or a system status.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List my orders', resource: OrderResource::class, collection: true, filters: [ 'status' => 'string' ], sorts: [ 'placed_at' ], includes: [ 'items', 'shipments', 'refunds', 'customer_notes' ] )]
    public function orders( Request $request ): JsonResponse
    {
        return $this->forCustomer( $request, fn ( Customer $customer ): JsonResponse => $this->listResponse(
            $this->history->query( $customer )->reorder(),
            $request,
            OrderResource::class,
            [ 'status' => function ( Builder $query, string $value ): void {
                $query->whereIn( 'system_status', CustomerOrderHistory::GROUPS[ $value ] ?? [ $value ] );
            } ],
            [ 'placed_at' => 'placed_at' ],
            self::ORDER_INCLUDES,
            '-placed_at',
        ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  int      $order    Order id.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Get one of my orders', resource: OrderResource::class, includes: [ 'items', 'shipments', 'refunds', 'customer_notes' ] )]
    public function order( Request $request, int $order ): JsonResponse
    {
        return $this->forCustomer( $request, function ( Customer $customer ) use ( $request, $order ): JsonResponse {
            $model = $this->history->find( $customer, $order );

            abort_if( null === $model, 404 );

            return $this->resourceResponse( $model, $request, OrderResource::class, self::ORDER_INCLUDES );
        } );
    }

    /**
     * Claims the guest orders placed under my email, verified by one order
     * number and its shipping postal code. Answers the claimed orders.
     *
     * @since 1.0.0
     *
     * @param  ClaimOrdersRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Claim my guest orders', resource: OrderResource::class, collection: true )]
    public function claim( ClaimOrdersRequest $request ): JsonResponse
    {
        return $this->forCustomer( $request, function ( Customer $customer ) use ( $request ): JsonResponse {
            try {
                $claimed = $this->claims->claim( $customer, (string) $request->validated( 'order_number' ), (string) $request->validated( 'postal_code' ), $request->ip() );
            } catch ( ClaimRateLimitedException ) {
                return Problem::make( 429, 'claim-rate-limited', __( 'Too many attempts' ), __( 'Too many claim attempts. Try again later.' ), $request );
            } catch ( ClaimVerificationFailedException ) {
                return Problem::make( 422, 'claim-not-verified', __( 'Claim not verified' ), __( 'No order matches that order number and postal code.' ), $request );
            }

            return OrderResource::collection( $claimed )->response( $request );
        } );
    }

    /**
     * Runs `$callback` for the signed-in shopper's customer record.
     *
     * @since 1.0.0
     *
     * @param  Request                      $request   Request.
     * @param  Closure(Customer): JsonResponse  $callback  Operation.
     *
     * @return JsonResponse
     */
    protected function forCustomer( Request $request, Closure $callback ): JsonResponse
    {
        $user = $request->user();

        if ( null !== $user && ! TokenAbilities::allowsStorefront( $user ) ) {
            return Problem::make( 403, 'forbidden', __( 'Forbidden' ), __( 'Missing ability :ability.', [ 'ability' => TokenAbilities::STOREFRONT ] ), $request );
        }

        $customer = null === $user ? null : $this->customers->customerForUser( $user, true );

        if ( null === $customer ) {
            return Problem::make( 404, 'customer-not-found', __( 'Not found' ), __( 'No customer record is linked to this account.' ), $request );
        }

        return $callback( $customer );
    }

    /**
     * One of the customer's addresses, or a 404.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer.
     * @param  int       $id        Address id.
     *
     * @return CustomerAddress
     */
    protected function address( Customer $customer, int $id ): CustomerAddress
    {
        $address = $customer->addresses()->whereKey( $id )->first();

        abort_if( null === $address, 404 );

        return $address;
    }
}
