<?php

/**
 * ActivityLogEntryResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\ActivityLogEntry}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.activityLogEntry`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\ActivityLogEntry $resource
 */
class ActivityLogEntryResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'activityLogEntry';

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
            'subject_type'  => $this->resource->subject_type,
            'subject_id'    => $this->resource->subject_id,
            'actor_user_id' => $this->resource->actor_user_id,
            'event_type'    => $this->resource->event_type,
            'payload'       => $this->resource->payload,
            'created_at'    => $this->resource->created_at,
        ];
    }
}
