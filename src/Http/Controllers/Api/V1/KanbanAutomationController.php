<?php

/**
 * KanbanAutomationController.
 *
 * Automation CRUD: `POST kanban/boards/{board}/automations`,
 * `PATCH` / `DELETE kanban/automations/{automation}` (engine spec §9.10).
 * A stored `trigger_config.secret` is never returned; a PATCH that omits it
 * keeps the stored one.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\KanbanAutomationRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\KanbanAutomationResource;
use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanAutomationController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  KanbanAutomationRequest  $request  Validated request.
     * @param  KanbanBoard              $board    Board.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Attach an automation to a kanban board', resource: KanbanAutomationResource::class, status: 201 )]
    public function store( KanbanAutomationRequest $request, KanbanBoard $board ): JsonResponse
    {
        $automation = $board->automations()->create( $request->validated() );

        return $this->resourceResponse( $automation, $request, KanbanAutomationResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  KanbanAutomationRequest  $request     Validated request.
     * @param  KanbanAutomation         $automation  Automation.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a kanban automation', resource: KanbanAutomationResource::class )]
    public function update( KanbanAutomationRequest $request, KanbanAutomation $automation ): JsonResponse
    {
        $data   = $request->validated();
        $secret = ( (array) $automation->trigger_config )['secret'] ?? null;

        if ( array_key_exists( 'trigger_config', $data ) && ! array_key_exists( 'secret', (array) $data['trigger_config'] ) && null !== $secret ) {
            $data['trigger_config']['secret'] = $secret;
        }

        $automation->fill( $data )->save();

        return $this->resourceResponse( $automation, $request, KanbanAutomationResource::class );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request           $request     Request.
     * @param  KanbanAutomation  $automation  Automation.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a kanban automation', resource: KanbanAutomationResource::class )]
    public function destroy( Request $request, KanbanAutomation $automation ): JsonResponse
    {
        $automation->delete();

        return $this->resourceResponse( $automation, $request, KanbanAutomationResource::class );
    }
}
