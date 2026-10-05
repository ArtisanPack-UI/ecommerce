<?php

/**
 * KanbanColumnResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\KanbanColumn}
 * (engine spec §9.13). Label, color, and icon fall back to the column's sub-status; `card_count` is the number of cards in the column right now, for WIP display. Filterable via `ap.ecommerce.api.resource.kanbanColumn`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\KanbanColumn $resource
 */
class KanbanColumnResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'kanbanColumn';

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return array<string, mixed>
     */
    protected function fields( Request $request ): array
    {
        $substatus = $this->resource->substatus;
        $count     = $this->resource->getAttribute( 'card_count' ) ?? ( $this->resource->exists ? $this->resource->cardCount() : 0 );

        return [
            'board_id'          => $this->resource->board_id,
            'substatus_id'      => $this->resource->substatus_id,
            'system_status'     => $substatus?->system_status,
            'label'             => $this->resource->displayLabel(),
            'label_override'    => $this->resource->label_override,
            'color'             => $this->resource->color_override ?? $substatus?->color,
            'color_override'    => $this->resource->color_override,
            'icon'              => $this->resource->icon_override ?? $substatus?->icon,
            'icon_override'     => $this->resource->icon_override,
            'position'          => $this->resource->position,
            'wip_limit'         => $this->resource->wip_limit,
            'card_count'        => (int) $count,
            'is_over_wip_limit' => null !== $this->resource->wip_limit && (int) $count > (int) $this->resource->wip_limit,
            'card_widgets'      => array_values( (array) $this->resource->card_widgets ),
            'created_at'        => $this->resource->created_at,
            'updated_at'        => $this->resource->updated_at,
        ];
    }
}
