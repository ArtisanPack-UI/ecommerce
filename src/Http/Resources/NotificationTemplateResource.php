<?php

/**
 * NotificationTemplateResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\NotificationTemplate}
 * (engine spec §9.13) for the template editor: the editable Twig sources,
 * the catalog `label` and preference `category`, the declared `variables`
 * (dotted paths, `*` marking a list) to drive autocomplete, and the
 * `preview_data` the live preview renders against. Filterable via
 * `ap.ecommerce.api.resource.notificationTemplate`.
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
 * @property \ArtisanPackUI\Ecommerce\Models\NotificationTemplate $resource
 */
class NotificationTemplateResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'notificationTemplate';

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return array<string, mixed>
     */
    protected function fields( Request $request ): array
    {
        $definition = $this->resource->definition();

        return [
            'key'          => $this->resource->key,
            'channel'      => $this->resource->channel,
            'locale'       => $this->resource->locale,
            'label'        => $definition?->label() ?? $this->resource->key,
            'category'     => $definition?->category(),
            'subject'      => $this->resource->subject,
            'body'         => $this->resource->body,
            'variables'    => array_values( $definition?->variables() ?? (array) $this->resource->variables ),
            'preview_data' => (array) $this->resource->preview_data,
            'is_active'    => $this->resource->is_active,
            'created_at'   => $this->resource->created_at,
            'updated_at'   => $this->resource->updated_at,
        ];
    }
}
