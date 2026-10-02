<?php

/**
 * OrderSubstatusResource.
 *
 * A user-defined sub-status under one of the six system statuses (engine
 * spec §3.17).
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
 */
class OrderSubstatusResource extends EcommerceResource
{
    /**
     * Resource type name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'orderSubstatus';

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
            'system_status' => $this->resource->system_status,
            'key'           => $this->resource->key,
            'label'         => $this->resource->label,
            'color'         => $this->resource->color,
            'icon'          => $this->resource->icon,
            'position'      => (int) $this->resource->position,
            'is_terminal'   => (bool) $this->resource->is_terminal,
            'created_at'    => $this->resource->created_at,
            'updated_at'    => $this->resource->updated_at,
        ];
    }
}
