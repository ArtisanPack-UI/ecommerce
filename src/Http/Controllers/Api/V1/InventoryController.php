<?php

/**
 * InventoryController.
 *
 * `GET admin/inventory` (engine spec §9.6). Admin-gated.
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

use ArtisanPackUI\Ecommerce\Http\Resources\InventoryItemResource;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
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
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List inventory items', resource: InventoryItemResource::class, collection: true )]
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
}
