<?php

/**
 * KanbanCardPolicy.
 *
 * Laravel policy for kanban cards (engine spec §6.18 resource `kanbanCard`).
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
class KanbanCardPolicy extends EcommercePolicy
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const RESOURCE = 'kanbanCard';

    /**
     * `ecommerce.kanbanCard.move`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  Model            $subject  The card (OrderBoardAssignment) being moved.
     *
     * @return bool
     */
    public function move( Authenticatable $user, Model $subject ): bool
    {
        return $this->decide( $user, 'move', $subject );
    }
}
