<?php

/**
 * WebhookDeliveryResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\WebhookDelivery}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.webhookDelivery`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\WebhookDelivery $resource
 */
class WebhookDeliveryResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'webhookDelivery';

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
            'subscription_id' => $this->resource->subscription_id,
            'event'           => $this->resource->event,
            'payload_hash'    => $this->resource->payload_hash,
            'payload'         => $this->resource->payload,
            'response_status' => $this->resource->response_status,
            'response_body'   => $this->resource->response_body,
            'attempts'        => $this->resource->attempts,
            'delivered_at'    => $this->resource->delivered_at,
            'next_retry_at'   => $this->resource->next_retry_at,
            'created_at'      => $this->resource->created_at,
        ];
    }
}
