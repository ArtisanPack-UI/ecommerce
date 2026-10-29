<?php

/**
 * KanbanBoardConfigChanged event.
 *
 * Dispatched after commit when a board's configuration changes — the board
 * itself, or a column added, edited, reordered, or deleted
 * (ArtisanPack-UI/ecommerce#200, #203). Connected kanban UIs re-read the
 * board shell when they hear it.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Events;

use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
class KanbanBoardConfigChanged implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.2.0
     *
     * @var string
     */
    public const BOARD_UPDATED = 'board-updated';

    /**
     * @since 1.2.0
     *
     * @var string
     */
    public const COLUMN_CREATED = 'column-created';

    /**
     * @since 1.2.0
     *
     * @var string
     */
    public const COLUMN_UPDATED = 'column-updated';

    /**
     * @since 1.2.0
     *
     * @var string
     */
    public const COLUMNS_REORDERED = 'columns-reordered';

    /**
     * @since 1.2.0
     *
     * @var string
     */
    public const COLUMN_DELETED = 'column-deleted';

    /**
     * @since 1.2.0
     *
     * @param  KanbanBoard  $board        Board whose configuration changed.
     * @param  string       $change       One of the constants above.
     * @param  int|null     $columnId     Column the change was about, if any.
     * @param  int|null     $actorUserId  Acting user, if any.
     */
    public function __construct(
        public readonly KanbanBoard $board,
        public readonly string $change,
        public readonly ?int $columnId = null,
        public readonly ?int $actorUserId = null,
    ) {
    }
}
