<?php

/**
 * DigitalFileResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\DigitalFile}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.digitalFile`.
 * Admin-only: storage `disk` / `path` never reach customers.
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
 * @property \ArtisanPackUI\Ecommerce\Models\DigitalFile $resource
 */
class DigitalFileResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'digitalFile';

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
            'product_id'         => $this->resource->product_id,
            'product_variant_id' => $this->resource->product_variant_id,
            'media_id'           => $this->resource->media_id,
            'disk'               => $this->adminOnly( $request, $this->resource->disk ),
            'path'               => $this->adminOnly( $request, $this->resource->path ),
            'label'              => $this->resource->label,
            'version'            => $this->resource->version,
            'is_streaming_only'  => $this->resource->is_streaming_only,
            'checksum_sha256'    => $this->resource->checksum_sha256,
            'archived_at'        => $this->resource->archived_at,
            'created_at'         => $this->resource->created_at,
            'updated_at'         => $this->resource->updated_at,
        ];
    }
}
