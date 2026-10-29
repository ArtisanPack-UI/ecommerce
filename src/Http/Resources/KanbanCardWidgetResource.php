<?php

/**
 * KanbanCardWidgetResource.
 *
 * REST representation of {@see \ArtisanPackUI\Ecommerce\Models\KanbanCardWidget}
 * (engine spec §9.13). One entry in the card widget catalog. Filterable via `ap.ecommerce.api.resource.kanbanCardWidget`.
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

use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
use ArtisanPackUI\Ecommerce\Support\ConfigSchema;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property \ArtisanPackUI\Ecommerce\Models\KanbanCardWidget $resource
 */
class KanbanCardWidgetResource extends EcommerceResource
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'kanbanCardWidget';

    /**
     * `label` is what the widget shows; `label_override` is the stored
     * label, or null when the widget's own label is used (since 1.2.0).
     *
     * @since 1.0.0
     * @since 1.2.0 Adds `label_override`; `label` falls back to the widget's own.
     *
     * @param  Request  $request  Request.
     *
     * @return array<string, mixed>
     */
    protected function fields( Request $request ): array
    {
        return [
            'key'            => $this->resource->key,
            'label'          => $this->label(),
            'label_override' => filled( $this->resource->label ) ? (string) $this->resource->label : null,
            'default_config' => (array) ( $this->resource->default_config ?? [] ),
            'provided_by'    => $this->resource->provided_by,
            'config_schema'  => $this->configSchema(),
        ];
    }

    /**
     * The label the widget shows: the stored override, else the registered
     * widget's own label, else its key.
     *
     * @since 1.2.0
     *
     * @return string
     */
    protected function label(): string
    {
        if ( filled( $this->resource->label ) ) {
            return (string) $this->resource->label;
        }

        $registry = app( KanbanCardWidgetRegistry::class );
        $key      = (string) $this->resource->key;

        if ( ! $registry->has( $key ) ) {
            return $key;
        }

        return (string) ( $registry->meta( $key )['label'] ?? $registry->get( $key )->label() );
    }

    /**
     * The registered widget's declared config schema (engine issue #149),
     * or `null` when it declares none or is no longer registered.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function configSchema(): ?array
    {
        $registry = app( KanbanCardWidgetRegistry::class );
        $key      = (string) $this->resource->key;

        return $registry->has( $key ) ? ConfigSchema::of( $registry->get( $key ) ) : null;
    }
}
