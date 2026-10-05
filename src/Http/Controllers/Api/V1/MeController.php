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
use ArtisanPackUI\Ecommerce\Digital\DigitalFileStreamer;
use ArtisanPackUI\Ecommerce\Exceptions\ClaimRateLimitedException;
use ArtisanPackUI\Ecommerce\Exceptions\ClaimVerificationFailedException;
use ArtisanPackUI\Ecommerce\Exceptions\DigitalDownloadException;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ClaimOrdersRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CustomerAddressRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateMeRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\CustomerAddressResource;
use ArtisanPackUI\Ecommerce\Http\Resources\CustomerResource;
use ArtisanPackUI\Ecommerce\Http\Resources\DigitalDownloadResource;
use ArtisanPackUI\Ecommerce\Http\Resources\LicenseKeyResource;
use ArtisanPackUI\Ecommerce\Http\Resources\OrderResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerAddress;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Registries\AccountMenuRegistry;
use ArtisanPackUI\Ecommerce\Services\CustomerAddressService;
use ArtisanPackUI\Ecommerce\Services\CustomerClaimService;
use ArtisanPackUI\Ecommerce\Services\CustomerOrderHistory;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use ArtisanPackUI\Ecommerce\Services\DigitalDownloadService;
use ArtisanPackUI\Ecommerce\Services\LicenseService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * My digital downloads (#174): each entitlement with its file, order
     * line, remaining downloads, and expiry. Download one through
     * `me/downloads/{download}` (or `/stream`) while signed in.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List my digital downloads', resource: DigitalDownloadResource::class, collection: true, sorts: [ 'created_at', 'expires_at' ], includes: [ 'file', 'order_item' ] )]
    public function downloads( Request $request ): JsonResponse
    {
        return $this->forCustomer( $request, fn ( Customer $customer ): JsonResponse => $this->listResponse(
            app( DigitalDownloadService::class )->forCustomer( $customer ),
            $request,
            DigitalDownloadResource::class,
            [],
            [ 'created_at' => 'created_at', 'expires_at' => 'expires_at' ],
            [ 'file' => 'file', 'order_item' => 'orderItem' ],
            '-created_at',
        ) );
    }

    /**
     * Downloads one of my files as an attachment, spending a download.
     *
     * @since 1.0.0
     *
     * @param  Request  $request   Request.
     * @param  int      $download  Entitlement id.
     *
     * @return JsonResponse|Response|StreamedResponse
     */
    #[ApiOperation( summary: 'Download one of my files', description: 'Same limits and expiry as the emailed link; spends one download.' )]
    public function download( Request $request, int $download ): JsonResponse|Response|StreamedResponse
    {
        return $this->deliverOwned( $request, $download, DigitalDownloadService::MODE_DOWNLOAD, true );
    }

    /**
     * Streams one of my files (byte-range aware); the first request of a
     * stream spends a download.
     *
     * @since 1.0.0
     *
     * @param  Request  $request   Request.
     * @param  int      $download  Entitlement id.
     *
     * @return JsonResponse|Response|StreamedResponse
     */
    #[ApiOperation( summary: 'Stream one of my files' )]
    public function streamDownload( Request $request, int $download ): JsonResponse|Response|StreamedResponse
    {
        return $this->deliverOwned( $request, $download, DigitalDownloadService::MODE_STREAM, DigitalFileStreamer::startsStream( $request ) );
    }

    /**
     * My license keys (#174), in full, with their activations.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List my license keys', resource: LicenseKeyResource::class, collection: true, sorts: [ 'created_at' ], includes: [ 'activations', 'order_item' ] )]
    public function licenseKeys( Request $request ): JsonResponse
    {
        return $this->forCustomer( $request, fn ( Customer $customer ): JsonResponse => $this->listResponse(
            app( LicenseService::class )->forCustomer( $customer ),
            $request,
            LicenseKeyResource::class,
            [],
            [ 'created_at' => 'created_at' ],
            [ 'activations' => 'activations', 'order_item' => 'orderItem' ],
            '-created_at',
        ) );
    }

    /**
     * The account navigation for the signed-in shopper (#179).
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Get my account menu' )]
    public function accountMenu( Request $request ): JsonResponse
    {
        return $this->forCustomer( $request, static fn ( Customer $customer ): JsonResponse => new JsonResponse( [
            'data' => app( AccountMenuRegistry::class )->visibleTo( $customer ),
        ] ) );
    }

    /**
     * Redeems one of the shopper's entitlements and sends the file.
     *
     * @since 1.0.0
     *
     * @param  Request  $request   Request.
     * @param  int      $download  Entitlement id.
     * @param  string   $mode      Delivery mode.
     * @param  bool     $counted   Whether the request spends a download.
     *
     * @return JsonResponse|Response|StreamedResponse
     */
    protected function deliverOwned( Request $request, int $download, string $mode, bool $counted ): JsonResponse|Response|StreamedResponse
    {
        return $this->forCustomer( $request, function ( Customer $customer ) use ( $request, $download, $mode, $counted ): JsonResponse|Response|StreamedResponse {
            try {
                $entitlement = app( DigitalDownloadService::class )->redeemOwned( $customer, $download, $mode, $request, $counted );

                return app( DigitalFileStreamer::class )->respond( $entitlement, $request, $mode );
            } catch ( DigitalDownloadException $e ) {
                return Problem::make( $e->status, $e->errorCode, __( 'Download unavailable' ), $e->getMessage(), $request );
            }
        } );
    }

    /**
     * Runs `$callback` for the signed-in shopper's customer record.
     *
     * @since 1.0.0
     *
     * @param  Request                      $request   Request.
     * @param  Closure(Customer): (JsonResponse|Response|StreamedResponse)  $callback  Operation.
     *
     * @return JsonResponse|Response|StreamedResponse
     */
    protected function forCustomer( Request $request, Closure $callback ): JsonResponse|Response|StreamedResponse
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
