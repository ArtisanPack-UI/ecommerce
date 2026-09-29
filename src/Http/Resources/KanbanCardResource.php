<?php

/**
 * KanbanCardResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment}
 * (engine spec §9.13). A card is an order's assignment to a board: its column plus the rendered widget payloads for that column (framework-agnostic — see {@see KanbanCardRenderer}). Set a `column` relation on the assignment to skip the column lookup. Filterable via `ap.ecommerce.api.resource.kanbanCard`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Resources;

use ArtisanPackUI\Ecommerce\Kanban\KanbanCardRenderer;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property \ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment $resource
 */
class KanbanCardResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'kanbanCard';

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return array<string, mixed>
     */
    protected function fields( Request $request ): array
    {
        $column = $this->resource->relationLoaded( 'column' ) ? $this->resource->getRelation( 'column' ) : $this->resource->currentColumn();
        // Don't lazy-load: a loaded `order` relation also renders as the include.
        $order  = $this->resource->relationLoaded( 'order' ) ? $this->resource->getRelation( 'order' ) : $this->resource->order()->first();

        return [
            'order_id'     => $this->resource->order_id,
            'board_id'     => $this->resource->board_id,
            'substatus_id' => $this->resource->substatus_id,
            'column_id'    => $column?->id,
            'assigned_at'  => $this->resource->assigned_at,
            'moved_at'     => $this->resource->moved_at,
            'removed_at'   => $this->resource->removed_at,
            'widgets'      => null === $column || null === $order ? [] : app( KanbanCardRenderer::class )->render( $order, $column ),
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, array{0: string, 1: class-string<EcommerceResource>}>
     */
    protected function relations(): array
    {
        return [
            'order' => [ 'order', OrderResource::class ],
        ];
    }
}
