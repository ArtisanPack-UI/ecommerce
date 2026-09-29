<?php

/**
 * KanbanCardWidgetResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\KanbanCardWidget}
 * (engine spec §9.13). One entry in the card widget catalog. Filterable via `ap.ecommerce.api.resource.kanbanCardWidget`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\KanbanCardWidget $resource
 */
class KanbanCardWidgetResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'kanbanCardWidget';

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
            'key'            => $this->resource->key,
            'label'          => $this->resource->label,
            'default_config' => (array) ( $this->resource->default_config ?? [] ),
            'provided_by'    => $this->resource->provided_by,
        ];
    }
}
