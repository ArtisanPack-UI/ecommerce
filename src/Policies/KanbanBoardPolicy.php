<?php

/**
 * KanbanBoardPolicy.
 *
 * Laravel policy for kanban boards and their columns and automations (engine spec §6.18 resource `kanbanBoard`).
 * Every method defers to {@see EcommercePolicy::decide()}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanBoardPolicy extends EcommercePolicy
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const RESOURCE = 'kanbanBoard';

    /**
     * `ecommerce.kanbanBoard.viewAny`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user  Acting user.
     *
     * @return bool
     */
    public function viewAny( Authenticatable $user ): bool
    {
        return $this->decide( $user, 'viewAny' );
    }

    /**
     * `ecommerce.kanbanBoard.view`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The board being viewed.
     *
     * @return bool
     */
    public function view( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'view', $subject );
    }

    /**
     * `ecommerce.kanbanBoard.create`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user  Acting user.
     *
     * @return bool
     */
    public function create( Authenticatable $user ): bool
    {
        return $this->decide( $user, 'create' );
    }

    /**
     * `ecommerce.kanbanBoard.update`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The board (or one of its columns / automations) being acted on.
     *
     * @return bool
     */
    public function update( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'update', $subject );
    }

    /**
     * `ecommerce.kanbanBoard.delete`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The board (or one of its columns / automations) being acted on.
     *
     * @return bool
     */
    public function delete( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'delete', $subject );
    }
}
