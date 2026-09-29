<?php

/**
 * KanbanBoardResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\KanbanBoard}
 * (engine spec §9.13). Columns and automations are available as includes. Filterable via `ap.ecommerce.api.resource.kanbanBoard`.
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

use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property \ArtisanPackUI\Ecommerce\Models\KanbanBoard $resource
 */
class KanbanBoardResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'kanbanBoard';

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return array<string, mixed>
     */
    protected function fields( Request $request ): array
    {
        return [
            'key'           => $this->resource->key,
            'name'          => $this->resource->name,
            'description'   => $this->resource->description,
            'routing_rules' => (array) ( $this->resource->routing_rules ?? [] ),
            'is_default'    => $this->resource->is_default,
            'is_active'     => $this->resource->is_active,
            'position'      => $this->resource->position,
            'settings'      => (array) ( $this->resource->settings ?? [] ),
            'created_at'    => $this->resource->created_at,
            'updated_at'    => $this->resource->updated_at,
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
            'columns'     => [ 'columns', KanbanColumnResource::class ],
            'automations' => [ 'automations', KanbanAutomationResource::class ],
        ];
    }
}
