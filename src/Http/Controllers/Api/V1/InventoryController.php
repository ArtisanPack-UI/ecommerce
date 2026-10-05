<?php

/**
 * InventoryController.
 *
 * `admin/inventory` (engine spec §9.6): list stock rows, change a row's
 * stock settings, and adjust its on-hand quantity through
 * {@see InventoryService} (engine issue #140). Admin-gated; writes need the
 * `inventory.adjust` ability and an Idempotency-Key.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\AdjustInventoryRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\UpdateInventoryItemRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\InventoryItemResource;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class InventoryController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  InventoryService  $inventory  Stock writes.
     */
    public function __construct( private readonly InventoryService $inventory )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List inventory items', resource: InventoryItemResource::class, collection: true, filters: [ 'stockable_id' => 'int-list', 'warehouse_id' => 'int-list', 'low_stock' => 'string' ], sorts: [ 'quantity_on_hand' ], includes: [ 'reservations' ] )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            InventoryItem::query(),
            $request,
            InventoryItemResource::class,
            [
                'stockable_id' => [ 'stockable_id', 'int' ],
                'warehouse_id' => [ 'warehouse_id', 'int' ],
                'low_stock'    => static fn ( Builder $query, string $value ) => in_array( $value, [ '1', 'true' ], true )
                    ? $query->whereNotNull( 'low_stock_threshold' )->whereRaw( 'quantity_on_hand <= low_stock_threshold + quantity_reserved' )
                    : $query,
            ],
            [ 'quantity_on_hand' => 'quantity_on_hand' ],
            [ 'reservations' => 'reservations' ],
        );
    }

    /**
     * Changes the row's stock settings. Quantities are not writable here.
     *
     * @since 1.0.0
     *
     * @param  UpdateInventoryItemRequest  $request  Validated request.
     * @param  InventoryItem               $item     Stock row.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update an inventory item\'s stock settings', resource: InventoryItemResource::class )]
    public function update( UpdateInventoryItemRequest $request, InventoryItem $item ): JsonResponse
    {
        return $this->resourceResponse( $this->inventory->updateSettings( $item, $request->validated() ), $request, InventoryItemResource::class );
    }

    /**
     * Adds a signed delta to the row's on-hand quantity, with a reason for
     * the audit log.
     *
     * @since 1.0.0
     *
     * @param  AdjustInventoryRequest  $request  Validated request.
     * @param  InventoryItem           $item     Stock row.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Adjust an inventory item\'s on-hand quantity', resource: InventoryItemResource::class )]
    public function adjust( AdjustInventoryRequest $request, InventoryItem $item ): JsonResponse
    {
        $adjusted = $this->inventory->adjust( $item, (int) $request->validated( 'delta' ), trim( (string) $request->validated( 'reason' ) ) );

        return $this->resourceResponse( $adjusted, $request, InventoryItemResource::class );
    }
}
