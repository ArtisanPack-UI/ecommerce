<?php

/**
 * RendersKanbanProblems.
 *
 * Maps the exceptions a kanban move or board assignment can raise to 422
 * `problem+json` responses (engine spec §11.5).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1\Concerns;

use ArtisanPackUI\Ecommerce\Exceptions\IncompatibleBoardSubstatusException;
use ArtisanPackUI\Ecommerce\Exceptions\InvalidOrderStatusTransitionException;
use ArtisanPackUI\Ecommerce\Exceptions\KanbanOperationException;
use ArtisanPackUI\Ecommerce\Exceptions\SubstatusTransitionRejectedException;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
trait RendersKanbanProblems
{
    /**
     * Runs `$operation`, turning refused kanban operations into 422s.
     *
     * @since 1.0.0
     *
     * @param  Request                $request    Request.
     * @param  Closure(): JsonResponse $operation  Operation producing the success response.
     *
     * @return JsonResponse
     */
    protected function kanbanOperation( Request $request, Closure $operation ): JsonResponse
    {
        try {
            return $operation();
        } catch ( KanbanOperationException $e ) {
            return Problem::make( 422, $e->errorCode, __( 'Kanban operation refused' ), $e->getMessage(), $request );
        } catch ( InvalidOrderStatusTransitionException $e ) {
            return Problem::make( 422, 'invalid-status-transition', __( 'Invalid status transition' ), $e->getMessage(), $request );
        } catch ( IncompatibleBoardSubstatusException $e ) {
            return Problem::make( 422, 'incompatible-column', __( 'Incompatible column' ), $e->getMessage(), $request );
        } catch ( SubstatusTransitionRejectedException $e ) {
            return Problem::make( 422, 'move-rejected', __( 'Kanban operation refused' ), $e->getMessage(), $request );
        }
    }

    /**
     * The acting user's id, when numeric.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return int|null
     */
    protected function actorId( Request $request ): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_numeric( $id ) ? (int) $id : null;
    }
}
