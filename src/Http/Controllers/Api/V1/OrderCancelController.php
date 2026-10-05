<?php

/**
 * OrderCancelController.
 *
 * `POST orders/{order}/cancel` (engine spec §9.3). Delegates to
 * {@see OrderCancellationService::cancel()}, the same service the GraphQL
 * `cancelOrder` mutation uses. An order whose status does not allow
 * cancelling is a 422 problem+json. The response is the cancelled order,
 * with what was released and what is still owed under
 * `meta.cancellation`.
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

use ArtisanPackUI\Ecommerce\Exceptions\OrderNotCancellableException;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CancelOrderRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\OrderResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\OrderCancellationService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderCancelController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  OrderCancellationService  $cancellations  Cancellation service.
     */
    public function __construct( private readonly OrderCancellationService $cancellations )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  CancelOrderRequest  $request  Validated request.
     * @param  Order               $order    Order.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Cancel an order', resource: OrderResource::class, description: 'Releases the order\'s inventory reservations and voids an uncaptured payment. Never refunds; `meta.cancellation.refund_owed` is what is still owed.' )]
    public function store( CancelOrderRequest $request, Order $order ): JsonResponse
    {
        $actor = $request->user()?->getAuthIdentifier();

        try {
            $summary = $this->cancellations->cancel( $order, (string) $request->validated( 'reason' ), is_numeric( $actor ) ? (int) $actor : null );
        } catch ( OrderNotCancellableException | InvalidArgumentException $exception ) {
            return Problem::make( 422, 'order-not-cancellable', __( 'Order not cancellable' ), $exception->getMessage(), $request );
        }

        $payload = ( new OrderResource( $summary->order ) )->response( $request )->getData( true );

        $payload['meta']['cancellation'] = [
            'released'       => $summary->reservations,
            'payment_voided' => $summary->voidsPayment,
            'refund_owed'    => $summary->refundOwedAmount,
            'currency'       => $summary->currency,
        ];

        return new JsonResponse( $payload );
    }
}
