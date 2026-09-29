<?php

/**
 * KanbanAutomationResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\KanbanAutomation}
 * (engine spec §9.13). A `secret` in `trigger_config` is never echoed back; `has_secret` says whether one is stored. Filterable via `ap.ecommerce.api.resource.kanbanAutomation`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\KanbanAutomation $resource
 */
class KanbanAutomationResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'kanbanAutomation';

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return array<string, mixed>
     */
    protected function fields( Request $request ): array
    {
        $config = (array) ( $this->resource->trigger_config ?? [] );

        return [
            'board_id'       => $this->resource->board_id,
            'from_column_id' => $this->resource->from_column_id,
            'to_column_id'   => $this->resource->to_column_id,
            'trigger_key'    => $this->resource->trigger_key,
            'trigger_config' => array_diff_key( $config, [ 'secret' => true ] ),
            'has_secret'     => is_string( $config['secret'] ?? null ) && '' !== $config['secret'],
            'conditions'     => (array) ( $this->resource->conditions ?? [] ),
            'is_active'      => $this->resource->is_active,
            'created_at'     => $this->resource->created_at,
            'updated_at'     => $this->resource->updated_at,
        ];
    }
}
