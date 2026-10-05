<?php

/**
 * OrderNoteController.
 *
 * `POST orders/{order}/notes` (engine spec §9.3). Delegates to
 * {@see OrderNoteService::add()}, the same service the GraphQL
 * `addOrderNote` mutation uses.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\AddOrderNoteRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\OrderNoteResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\OrderNoteService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderNoteController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  OrderNoteService  $notes  Note service.
     */
    public function __construct( private readonly OrderNoteService $notes )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  AddOrderNoteRequest  $request  Validated request.
     * @param  Order                $order    Order.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Add a note to an order', resource: OrderNoteResource::class, status: 201 )]
    public function store( AddOrderNoteRequest $request, Order $order ): JsonResponse
    {
        $actor = $request->user()?->getAuthIdentifier();

        try {
            $note = $this->notes->add(
                $order,
                (string) $request->validated( 'body' ),
                is_numeric( $actor ) ? (int) $actor : null,
                (bool) $request->validated( 'is_customer_visible', false ),
            );
        } catch ( InvalidArgumentException $exception ) {
            return Problem::make( 422, 'note-invalid', __( 'Note not added' ), $exception->getMessage(), $request );
        }

        return $this->resourceResponse( $note, $request, OrderNoteResource::class, [], 201 );
    }
}
