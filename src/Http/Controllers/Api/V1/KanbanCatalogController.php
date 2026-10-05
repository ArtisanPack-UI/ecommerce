<?php

/**
 * KanbanCatalogController.
 *
 * What a kanban settings UI can offer: the registered card widgets
 * (`GET kanban/widgets`, merged with any `kanban_card_widgets` overrides)
 * and the registered automation triggers (`GET kanban/triggers`).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1;

use ArtisanPackUI\Ecommerce\Http\Resources\KanbanCardWidgetResource;
use ArtisanPackUI\Ecommerce\Models\KanbanCardWidget;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
use ArtisanPackUI\Ecommerce\Support\ConfigSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanCatalogController extends ApiController
{
    /**
     * Registered card widgets. A `kanban_card_widgets` row with the same key
     * overrides the label and default config.
     *
     * @since 1.0.0
     *
     * @param  Request                   $request  Request.
     * @param  KanbanCardWidgetRegistry  $widgets  Widget registry.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List the available kanban card widgets', resource: KanbanCardWidgetResource::class, collection: true )]
    public function widgets( Request $request, KanbanCardWidgetRegistry $widgets ): JsonResponse
    {
        $stored = KanbanCardWidget::query()->get()->keyBy( 'key' );
        $rows   = [];

        foreach ( $widgets->keys() as $key ) {
            $rows[] = $stored->get( $key ) ?? new KanbanCardWidget( [
                'key'            => $key,
                'label'          => (string) ( $widgets->meta( $key )['label'] ?? $widgets->get( $key )->label() ),
                'default_config' => [],
                'provided_by'    => (string) ( $widgets->meta( $key )['provided_by'] ?? 'ecommerce' ),
            ] );
        }

        return new JsonResponse( [ 'data' => KanbanCardWidgetResource::collection( $rows )->resolve( $request ) ] );
    }

    /**
     * Registered automation triggers, each with its `config_schema`
     * (`null` when the trigger declares none).
     *
     * @since 1.0.0
     *
     * @param  KanbanAutomationRegistry  $triggers  Trigger registry.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List the available kanban automation triggers' )]
    public function triggers( KanbanAutomationRegistry $triggers ): JsonResponse
    {
        return new JsonResponse( [ 'data' => ConfigSchema::catalog( $triggers ) ] );
    }
}
