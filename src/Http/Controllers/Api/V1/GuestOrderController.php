<?php

/**
 * GuestOrderController.
 *
 * Guest access to an order (#175): look one up by email and order number
 * (`GET orders/guest-lookup`), or open a signed link from the confirmation
 * email (`GET orders/view/{token}`). Both answer the order with its lines
 * and shipments; neither reveals whether an order number exists.
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

use ArtisanPackUI\Ecommerce\Exceptions\GuestLookupLockedException;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\GuestOrderLookupRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\OrderResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\GuestOrderLookupService;
use ArtisanPackUI\Ecommerce\Support\OrderViewToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class GuestOrderController extends ApiController
{
    /**
     * Relations a guest may include.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const INCLUDES = [
        'items'     => 'items',
        'shipments' => 'shipments',
    ];

    /**
     * @since 1.0.0
     *
     * @param  GuestOrderLookupRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Look up a guest order', resource: OrderResource::class, includes: self::INCLUDES, description: 'A wrong email and an unknown order number both answer 404. Repeated failures lock the order number and the caller out for a while (429).' )]
    public function lookup( GuestOrderLookupRequest $request ): JsonResponse
    {
        try {
            $order = app( GuestOrderLookupService::class )->find( (string) $request->validated( 'email' ), (string) $request->validated( 'order_number' ), $request->ip() );
        } catch ( GuestLookupLockedException $e ) {
            return Problem::make( 429, 'lookup-locked', __( 'Too many attempts' ), $e->getMessage(), $request )->header( 'Retry-After', (string) $e->retryAfter );
        }

        if ( null === $order ) {
            return Problem::make( 404, 'order-not-found', __( 'Not found' ), __( 'No order matches that email and order number.' ), $request );
        }

        return $this->resourceResponse( $order, $request, OrderResource::class, self::INCLUDES );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $token    Signed order-view token.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Open a signed order link', resource: OrderResource::class, includes: self::INCLUDES )]
    public function view( Request $request, string $token ): JsonResponse
    {
        $order = OrderViewToken::verify( $token );

        if ( null === $order ) {
            return Problem::make( 404, 'order-link-invalid', __( 'Not found' ), __( 'This order link is invalid or has expired.' ), $request );
        }

        return $this->resourceResponse( $order, $request, OrderResource::class, self::INCLUDES );
    }
}
