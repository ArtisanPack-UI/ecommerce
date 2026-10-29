<?php

/**
 * KanbanCardTags.
 *
 * The tags on a board's active cards, for a filter bar's tag options
 * (ArtisanPack-UI/ecommerce#199). Tags come from each order's
 * `meta['tags']` through `ap.ecommerce.kanban.orderTags` — the same source
 * as the tags card widget — so a tagging satellite that stores tags
 * elsewhere supplies both. Bounded to the most recently assigned
 * {@see self::MAX_ORDERS} cards and cached per request (the class is bound
 * as a scoped service).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Kanban;

use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
class KanbanCardTags
{
    /**
     * Most cards read for tags.
     *
     * @since 1.2.0
     *
     * @var int
     */
    public const MAX_ORDERS = 500;

    /**
     * Most tags returned.
     *
     * @since 1.2.0
     *
     * @var int
     */
    public const MAX_TAGS = 200;

    /**
     * Tags per board id for this request.
     *
     * @since 1.2.0
     *
     * @var array<int, array<int, string>>
     */
    private array $cache = [];

    /**
     * Distinct tags on `$board`'s active cards, sorted case-insensitively.
     *
     * @since 1.2.0
     *
     * @param  KanbanBoard  $board  The board.
     *
     * @return array<int, string>
     */
    public function forBoard( KanbanBoard $board ): array
    {
        return $this->cache[ (int) $board->id ] ??= $this->collect( $board );
    }

    /**
     * Forgets the cached tags (after a write in the same request).
     *
     * @since 1.2.0
     *
     * @return void
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @since 1.2.0
     *
     * @param  KanbanBoard  $board  The board.
     *
     * @return array<int, string>
     */
    private function collect( KanbanBoard $board ): array
    {
        $tags = [];

        $orders = Order::query()
            ->whereIn( 'id', OrderBoardAssignment::query()->active()->where( 'board_id', $board->id )->select( 'order_id' ) )
            ->latest( 'id' )
            ->limit( self::MAX_ORDERS )
            ->get();

        foreach ( $orders as $order ) {
            $orderTags = applyFilters( 'ap.ecommerce.kanban.orderTags', (array) ( $order->meta['tags'] ?? [] ), $order );

            foreach ( is_array( $orderTags ) ? $orderTags : [] as $tag ) {
                $tag = is_scalar( $tag ) ? trim( (string) $tag ) : '';

                if ( '' !== $tag ) {
                    $tags[ $tag ] = true;
                }
            }
        }

        $tags = array_map( 'strval', array_keys( $tags ) );
        natcasesort( $tags );

        return array_slice( array_values( $tags ), 0, self::MAX_TAGS );
    }
}
