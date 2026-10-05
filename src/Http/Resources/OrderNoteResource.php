<?php

/**
 * OrderNoteResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\OrderNote}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.orderNote`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\OrderNote $resource
 */
class OrderNoteResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'orderNote';

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
            'order_id'            => $this->resource->order_id,
            'author_user_id'      => $this->adminOnly( $request, $this->resource->author_user_id ),
            'body'                => $this->resource->body,
            'is_customer_visible' => $this->resource->is_customer_visible,
            'created_at'          => $this->resource->created_at,
        ];
    }
}
