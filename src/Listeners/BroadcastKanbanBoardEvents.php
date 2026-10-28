<?php

/**
 * BroadcastKanbanBoardEvents.
 *
 * Relays every board change to `private-ecommerce.kanban.board.{board}` as a
 * {@see KanbanBoardBroadcast} (ArtisanPack-UI/ecommerce#200): cards added,
 * removed, moved, and reordered, board/column configuration changes, and
 * automations that fired or failed. Enabled by
 * `artisanpack.ecommerce.kanban.broadcast`. The domain events dispatch
 * after commit, and a broadcaster failure is logged — it never fails the
 * write that caused it.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Listeners;

use ArtisanPackUI\Ecommerce\Broadcasting\KanbanBoardBroadcast;
use ArtisanPackUI\Ecommerce\Events\KanbanAutomationFailed;
use ArtisanPackUI\Ecommerce\Events\KanbanAutomationTriggered;
use ArtisanPackUI\Ecommerce\Events\KanbanBoardAssignmentAdded;
use ArtisanPackUI\Ecommerce\Events\KanbanBoardAssignmentRemoved;
use ArtisanPackUI\Ecommerce\Events\KanbanBoardConfigChanged;
use ArtisanPackUI\Ecommerce\Events\KanbanCardMoved;
use ArtisanPackUI\Ecommerce\Events\KanbanCardReordered;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
class BroadcastKanbanBoardEvents
{
    /**
     * Broadcasts one board event, logging (not throwing) a broadcaster
     * failure.
     *
     * @since 1.2.0
     *
     * @param  string                $type     Event type.
     * @param  int                   $boardId  Board id.
     * @param  array<string, mixed>  $fields   Event fields.
     *
     * @return void
     */
    public static function send( string $type, int $boardId, array $fields = [] ): void
    {
        try {
            broadcast( KanbanBoardBroadcast::make( $type, $boardId, $fields ) );
        } catch ( Throwable $e ) {
            report( $e );

            Log::channel( 'ecommerce' )->error( 'Kanban board broadcast failed; the change stands.', [
                'type'     => $type,
                'board_id' => $boardId,
                'error'    => $e->getMessage(),
            ] );
        }
    }

    /**
     * @since 1.2.0
     *
     * @param  Dispatcher  $events  Event dispatcher.
     *
     * @return array<class-string, string>
     */
    public function subscribe( Dispatcher $events ): array
    {
        return [
            KanbanCardMoved::class              => 'cardMoved',
            KanbanCardReordered::class          => 'cardReordered',
            KanbanBoardAssignmentAdded::class   => 'cardAdded',
            KanbanBoardAssignmentRemoved::class => 'cardRemoved',
            KanbanBoardConfigChanged::class     => 'configChanged',
            KanbanAutomationTriggered::class    => 'automationFired',
            KanbanAutomationFailed::class       => 'automationFailed',
        ];
    }

    /**
     * @since 1.2.0
     *
     * @param  KanbanCardMoved  $event  Event.
     *
     * @return void
     */
    public function cardMoved( KanbanCardMoved $event ): void
    {
        $position = OrderBoardAssignment::query()
            ->where( 'order_id', $event->order->id )
            ->where( 'board_id', $event->board->id )
            ->value( 'position' );

        self::send( 'card-moved', (int) $event->board->id, [
            'order_id'       => (int) $event->order->id,
            'column_id'      => (int) $event->to->id,
            'from_column_id' => $event->from->exists ? (int) $event->from->id : null,
            'to_column_id'   => (int) $event->to->id,
            'position'       => null === $position ? null : (int) $position,
            'actor_user_id'  => $event->actorUserId,
        ] );
    }

    /**
     * @since 1.2.0
     *
     * @param  KanbanCardReordered  $event  Event.
     *
     * @return void
     */
    public function cardReordered( KanbanCardReordered $event ): void
    {
        $position = OrderBoardAssignment::query()
            ->where( 'order_id', $event->order->id )
            ->where( 'board_id', $event->board->id )
            ->value( 'position' );

        self::send( 'card-reordered', (int) $event->board->id, [
            'order_id'       => (int) $event->order->id,
            'column_id'      => (int) $event->column->id,
            'from_column_id' => (int) $event->column->id,
            'to_column_id'   => (int) $event->column->id,
            'position'       => null === $position ? null : (int) $position,
            'actor_user_id'  => $event->actorUserId,
        ] );
    }

    /**
     * @since 1.2.0
     *
     * @param  KanbanBoardAssignmentAdded  $event  Event.
     *
     * @return void
     */
    public function cardAdded( KanbanBoardAssignmentAdded $event ): void
    {
        $column = $this->columnId( $event->assignment );

        self::send( 'card-added', (int) $event->assignment->board_id, [
            'order_id'      => (int) $event->assignment->order_id,
            'column_id'     => $column,
            'to_column_id'  => $column,
            'position'      => (int) $event->assignment->position,
            'actor_user_id' => $event->actorUserId,
        ] );
    }

    /**
     * @since 1.2.0
     *
     * @param  KanbanBoardAssignmentRemoved  $event  Event.
     *
     * @return void
     */
    public function cardRemoved( KanbanBoardAssignmentRemoved $event ): void
    {
        $column = $this->columnId( $event->assignment );

        self::send( 'card-removed', (int) $event->assignment->board_id, [
            'order_id'       => (int) $event->assignment->order_id,
            'column_id'      => $column,
            'from_column_id' => $column,
            'actor_user_id'  => $event->actorUserId,
        ] );
    }

    /**
     * @since 1.2.0
     *
     * @param  KanbanBoardConfigChanged  $event  Event.
     *
     * @return void
     */
    public function configChanged( KanbanBoardConfigChanged $event ): void
    {
        self::send( 'config-changed', (int) $event->board->id, [
            'column_id'     => $event->columnId,
            'change'        => $event->change,
            'actor_user_id' => $event->actorUserId,
        ] );
    }

    /**
     * @since 1.2.0
     *
     * @param  KanbanAutomationTriggered  $event  Event.
     *
     * @return void
     */
    public function automationFired( KanbanAutomationTriggered $event ): void
    {
        self::send( 'automation-fired', (int) $event->automation->board_id, [
            'order_id'      => (int) $event->order->id,
            'column_id'     => (int) $event->automation->to_column_id,
            'automation_id' => (int) $event->automation->id,
        ] );
    }

    /**
     * @since 1.2.0
     *
     * @param  KanbanAutomationFailed  $event  Event.
     *
     * @return void
     */
    public function automationFailed( KanbanAutomationFailed $event ): void
    {
        self::send( 'automation-failed', (int) $event->automation->board_id, [
            'order_id'      => (int) $event->order->id,
            'column_id'     => (int) $event->automation->to_column_id,
            'automation_id' => (int) $event->automation->id,
        ] );
    }

    /**
     * The id of the column `$assignment`'s card is (or was) in.
     *
     * @since 1.2.0
     *
     * @param  OrderBoardAssignment  $assignment  Card.
     *
     * @return int|null
     */
    protected function columnId( OrderBoardAssignment $assignment ): ?int
    {
        $id = KanbanColumn::query()
            ->where( 'board_id', $assignment->board_id )
            ->where( 'substatus_id', $assignment->substatus_id )
            ->value( 'id' );

        return null === $id ? null : (int) $id;
    }
}
