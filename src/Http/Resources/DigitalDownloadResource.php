<?php

/**
 * DigitalDownloadResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\DigitalDownload}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.digitalDownload`.
 * The token is never rendered — only its holder has it.
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
 * @property \ArtisanPackUI\Ecommerce\Models\DigitalDownload $resource
 */
class DigitalDownloadResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'digitalDownload';

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
            'order_item_id'       => $this->resource->order_item_id,
            'digital_file_id'     => $this->resource->digital_file_id,
            'downloads_remaining' => $this->resource->downloads_remaining,
            'expires_at'          => $this->resource->expires_at,
            'first_downloaded_at' => $this->resource->first_downloaded_at,
            'last_downloaded_at'  => $this->resource->last_downloaded_at,
            'download_count'      => $this->resource->download_count,
            'created_at'          => $this->resource->created_at,
            'updated_at'          => $this->resource->updated_at,
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
            'file' => [ 'file', DigitalFileResource::class ],
        ];
    }
}
