<?php

/**
 * OrderEditResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\OrderEdit}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.orderEdit`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\OrderEdit $resource
 */
class OrderEditResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'orderEdit';

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
            'order_id'          => $this->resource->order_id,
            'actor_user_id'     => $this->resource->actor_user_id,
            'reason'            => $this->resource->reason,
            'diff'              => $this->resource->diff,
            'pre_edit_snapshot' => $this->adminOnly( $request, $this->resource->pre_edit_snapshot ),
            'created_at'        => $this->resource->created_at,
        ];
    }
}
