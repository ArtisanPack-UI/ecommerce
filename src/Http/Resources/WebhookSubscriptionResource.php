<?php

/**
 * WebhookSubscriptionResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\WebhookSubscription}
 * (engine spec §9.13). Filterable via `ap.ecommerce.api.resource.webhookSubscription`.
 * The signing `secret` is only rendered by {@see self::withSecret()} — the
 * create response — so it is shown to the operator exactly once.
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
use Illuminate\Http\Resources\MissingValue;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property \ArtisanPackUI\Ecommerce\Models\WebhookSubscription $resource
 */
class WebhookSubscriptionResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'webhookSubscription';

    /**
     * Whether to render the signing secret.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected bool $revealSecret = false;

    /**
     * Renders the signing secret (use only on the create response).
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function withSecret(): static
    {
        $this->revealSecret = true;

        return $this;
    }

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
            'name'                 => $this->resource->name,
            'url'                  => $this->resource->url,
            'events'               => array_values( (array) $this->resource->events ),
            'is_active'            => $this->resource->is_active,
            'secret'               => $this->revealSecret ? $this->resource->secret : new MissingValue(),
            'last_success_at'      => $this->resource->last_success_at,
            'last_failure_at'      => $this->resource->last_failure_at,
            'consecutive_failures' => $this->resource->consecutive_failures,
            'created_at'           => $this->resource->created_at,
            'updated_at'           => $this->resource->updated_at,
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
            'deliveries' => [ 'deliveries', WebhookDeliveryResource::class ],
        ];
    }
}
