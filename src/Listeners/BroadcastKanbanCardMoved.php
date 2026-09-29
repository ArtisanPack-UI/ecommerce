<?php

/**
 * BroadcastKanbanCardMoved.
 *
 * Broadcasts every card move on the board's private channel
 * (`private-ecommerce.kanban.board.{board}`, engine spec §11.7) so all
 * connected kanban UIs stay in sync. Enabled by
 * `artisanpack.ecommerce.kanban.broadcast`. The event is `kanbanCardMoved`
 * with a body of `{ "data": { "kanbanCardMoved": { card, from_column_id,
 * to_column_id, board_id } } }` — the GraphQL subscription shape (engine
 * spec §10.4). The card renders with admin fields: channel members hold
 * `kanbanBoard.view`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Listeners;

use ArtisanPackUI\Ecommerce\Broadcasting\GraphQLSubscriptionBroadcast;
use ArtisanPackUI\Ecommerce\Events\KanbanCardMoved;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookPayloadFactory;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class BroadcastKanbanCardMoved
{
    /**
     * Channel name pattern (without the `private-` prefix).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CHANNEL = 'ecommerce.kanban.board.{board}';

    /**
     * Channel name for one board.
     *
     * @since 1.0.0
     *
     * @param  int  $boardId  Board id.
     *
     * @return string
     */
    public static function channel( int $boardId ): string
    {
        return str_replace( '{board}', (string) $boardId, self::CHANNEL );
    }

    /**
     * @since 1.0.0
     *
     * @param  KanbanCardMoved  $event  Event.
     *
     * @return void
     */
    public function handle( KanbanCardMoved $event ): void
    {
        $card = OrderBoardAssignment::query()
            ->where( 'order_id', $event->order->id )
            ->where( 'board_id', $event->board->id )
            ->first();

        if ( null === $card ) {
            return;
        }

        $card->setRelation( 'order', $event->order );
        $card->setRelation( 'column', $event->to );

        event( new GraphQLSubscriptionBroadcast( 'kanbanCardMoved', self::channel( (int) $event->board->id ), [
            'card'           => ( new WebhookPayloadFactory( true ) )->serialize( $card ),
            'from_column_id' => $event->from->exists ? (int) $event->from->id : null,
            'to_column_id'   => (int) $event->to->id,
            'board_id'       => (int) $event->board->id,
        ] ) );
    }
}
