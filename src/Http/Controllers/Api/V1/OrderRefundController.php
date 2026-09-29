<?php

/**
 * OrderRefundController.
 *
 * `POST orders/{order}/refunds` (engine spec §9.3). Delegates to
 * {@see RefundService::issue()}, the same service the GraphQL
 * `issueRefund` mutation uses. A refund the order state, payload, or
 * gateway rejects is a 422 problem+json.
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

use ArtisanPackUI\Ecommerce\Exceptions\RefundNotAllowedException;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\IssueRefundRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\RefundResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\RefundService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderRefundController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  RefundService  $refunds  Refund service.
     */
    public function __construct( private readonly RefundService $refunds )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  IssueRefundRequest  $request  Validated request.
     * @param  Order               $order    Order.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Issue a refund against an order', resource: RefundResource::class, status: 201 )]
    public function store( IssueRefundRequest $request, Order $order ): JsonResponse
    {
        $actor = $request->user()?->getAuthIdentifier();

        try {
            $refund = $this->refunds->issue(
                $order,
                (array) $request->validated( 'lines' ),
                is_numeric( $actor ) ? (int) $actor : null,
                $request->validated( 'reason' ),
            );
        } catch ( RefundNotAllowedException | InvalidArgumentException $exception ) {
            return Problem::make( 422, 'refund-not-allowed', __( 'Refund not allowed' ), $exception->getMessage(), $request );
        }

        return $this->resourceResponse( $refund, $request, RefundResource::class, [ 'items' => 'items' ], 201 );
    }
}
