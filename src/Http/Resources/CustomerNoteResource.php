<?php

/**
 * CustomerNoteResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\CustomerNote}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.customerNote`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\CustomerNote $resource
 */
class CustomerNoteResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'customerNote';

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
            'customer_id'    => $this->resource->customer_id,
            'author_user_id' => $this->resource->author_user_id,
            'body'           => $this->resource->body,
            'created_at'     => $this->resource->created_at,
        ];
    }
}
