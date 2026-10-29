<?php

/**
 * KanbanCatalogController.
 *
 * What a kanban settings UI can offer: the registered card widgets
 * (`GET kanban/widgets`, merged with any `kanban_card_widgets` overrides)
 * and the registered automation triggers (`GET kanban/triggers`). The
 * overrides are written with `PUT kanban/widgets/{key}` and removed with
 * `DELETE kanban/widgets/{key}`.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\KanbanCardWidgetOverrideRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\KanbanCardWidgetResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Http\Support\ValidationErrors;
use ArtisanPackUI\Ecommerce\Models\KanbanCardWidget;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry;
use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
use ArtisanPackUI\Ecommerce\Services\KanbanCardWidgetService;
use ArtisanPackUI\Ecommerce\Support\ConfigSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            $rows[] = $stored->get( $key ) ?? $this->catalogRow( $widgets, $key );
        }

        return new JsonResponse( [ 'data' => KanbanCardWidgetResource::collection( $rows )->resolve( $request ) ] );
    }

    /**
     * Stores a widget's label and/or default config for every board.
     *
     * @since 1.2.0
     *
     * @param  KanbanCardWidgetOverrideRequest  $request  Validated request.
     * @param  KanbanCardWidgetService          $service  Widget settings.
     * @param  KanbanCardWidgetRegistry         $widgets  Widget registry.
     * @param  string                           $key      Widget key.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Override a kanban card widget\'s label or default config', resource: KanbanCardWidgetResource::class )]
    public function updateWidget( KanbanCardWidgetOverrideRequest $request, KanbanCardWidgetService $service, KanbanCardWidgetRegistry $widgets, string $key ): JsonResponse
    {
        if ( ! $widgets->has( $key ) ) {
            return Problem::make( 404, 'not-found', __( 'Not found' ), __( 'No card widget is registered under that key.' ), $request );
        }

        try {
            $row = $service->saveOverride( $key, $request->validated() );
        } catch ( ValidationException $e ) {
            return Problem::make( 422, 'validation-failed', __( 'Validation failed' ), $e->getMessage(), $request, ValidationErrors::from( $e->validator ) );
        }

        return $this->resourceResponse( $row, $request, KanbanCardWidgetResource::class );
    }

    /**
     * Removes a widget's stored override and returns the widget's own
     * label and default config.
     *
     * @since 1.2.0
     *
     * @param  Request                   $request  Request.
     * @param  KanbanCardWidgetService   $service  Widget settings.
     * @param  KanbanCardWidgetRegistry  $widgets  Widget registry.
     * @param  string                    $key      Widget key.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Remove a kanban card widget override', resource: KanbanCardWidgetResource::class )]
    public function resetWidget( Request $request, KanbanCardWidgetService $service, KanbanCardWidgetRegistry $widgets, string $key ): JsonResponse
    {
        if ( ! $widgets->has( $key ) ) {
            return Problem::make( 404, 'not-found', __( 'Not found' ), __( 'No card widget is registered under that key.' ), $request );
        }

        $service->resetOverride( $key );

        return $this->resourceResponse( $this->catalogRow( $widgets, $key ), $request, KanbanCardWidgetResource::class );
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

    /**
     * An unsaved catalog row for a registered widget with no override.
     *
     * @since 1.2.0
     *
     * @param  KanbanCardWidgetRegistry  $widgets  Widget registry.
     * @param  string                    $key      Widget key.
     *
     * @return KanbanCardWidget
     */
    protected function catalogRow( KanbanCardWidgetRegistry $widgets, string $key ): KanbanCardWidget
    {
        return new KanbanCardWidget( [
            'key'            => $key,
            'label'          => (string) ( $widgets->meta( $key )['label'] ?? $widgets->get( $key )->label() ),
            'default_config' => [],
            'provided_by'    => (string) ( $widgets->meta( $key )['provided_by'] ?? 'ecommerce' ),
        ] );
    }
}
