<?php

/**
 * ShipmentResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\Shipment}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.shipment`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\Shipment $resource
 */
class ShipmentResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'shipment';

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
            'order_id'        => $this->resource->order_id,
            'method_key'      => $this->resource->method_key,
            'carrier'         => $this->resource->carrier,
            'service'         => $this->resource->service,
            'tracking_number' => $this->resource->tracking_number,
            'tracking_url'    => $this->resource->tracking_url,
            'label_id'        => $this->resource->label_id,
            'status'          => $this->resource->status,
            'shipped_at'      => $this->resource->shipped_at,
            'delivered_at'    => $this->resource->delivered_at,
            'meta'            => $this->publicMeta(),
            'created_at'      => $this->resource->created_at,
            'updated_at'      => $this->resource->updated_at,
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
            'items' => [ 'items', ShipmentItemResource::class ],
        ];
    }

    /**
     * Shipment meta with the local-pickup code hash removed — the hash is
     * a verification secret and never leaves the server.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    protected function publicMeta(): array
    {
        $meta = (array) ( $this->resource->meta ?? [] );

        unset( $meta['pickup']['code_hash'] );

        return $meta;
    }
}
