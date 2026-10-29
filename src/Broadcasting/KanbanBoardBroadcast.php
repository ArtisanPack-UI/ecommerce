<?php

/**
 * KanbanBoardBroadcast.
 *
 * One board event on `private-ecommerce.kanban.board.{board}`
 * (ArtisanPack-UI/ecommerce#200, #201), broadcast as `kanbanBoardEvent`
 * with a body of ids — never rendered HTML — so each kanban UI re-reads
 * what it needs through its own authorization:
 *
 * ```json
 * { "type", "board_id", "order_id", "column_id", "from_column_id",
 *   "to_column_id", "position", "actor_user_id", "occurred_at", … }
 * ```
 *
 * `type` is one of {@see self::TYPES}; keys that don't apply are null.
 * Queued on `artisanpack.ecommerce.kanban.broadcast_connection` /
 * `broadcast_queue` (the defaults when null).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Broadcasting;

use ArtisanPackUI\Ecommerce\Listeners\BroadcastKanbanCardMoved;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
class KanbanBoardBroadcast implements ShouldBroadcast
{
    /**
     * Broadcast event name.
     *
     * @since 1.2.0
     *
     * @var string
     */
    public const NAME = 'kanbanBoardEvent';

    /**
     * Event types.
     *
     * @since 1.2.0
     *
     * @var array<int, string>
     */
    public const TYPES = [
        'card-added',
        'card-removed',
        'card-moved',
        'card-reordered',
        'card-updated',
        'config-changed',
        'automation-fired',
        'automation-failed',
    ];

    /**
     * Queue connection for the broadcast job.
     *
     * @since 1.2.0
     *
     * @var string|null
     */
    public ?string $connection;

    /**
     * Queue name for the broadcast job.
     *
     * @since 1.2.0
     *
     * @var string|null
     */
    public ?string $queue;

    /**
     * @since 1.2.0
     *
     * @param  int                   $boardId  Board id.
     * @param  array<string, mixed>  $payload  Event body (see the class doc).
     */
    public function __construct(
        public readonly int $boardId,
        public readonly array $payload,
    ) {
        $connection       = config( 'artisanpack.ecommerce.kanban.broadcast_connection' );
        $queue            = config( 'artisanpack.ecommerce.kanban.broadcast_queue' );
        $this->connection = is_string( $connection ) && '' !== $connection ? $connection : null;
        $this->queue      = is_string( $queue ) && '' !== $queue ? $queue : null;
    }

    /**
     * Builds the payload for one event.
     *
     * @since 1.2.0
     *
     * @param  string                $type     One of {@see self::TYPES}.
     * @param  int                   $boardId  Board id.
     * @param  array<string, mixed>  $fields   Event fields (`order_id`, `column_id`, …).
     *
     * @return self
     */
    public static function make( string $type, int $boardId, array $fields = [] ): self
    {
        return new self( $boardId, array_merge( [
            'type'           => $type,
            'board_id'       => $boardId,
            'order_id'       => null,
            'column_id'      => null,
            'from_column_id' => null,
            'to_column_id'   => null,
            'position'       => null,
            'actor_user_id'  => null,
        ], $fields, [ 'occurred_at' => now()->toIso8601String() ] ) );
    }

    /**
     * @since 1.2.0
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [ new PrivateChannel( BroadcastKanbanCardMoved::channel( $this->boardId ) ) ];
    }

    /**
     * @since 1.2.0
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @since 1.2.0
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
