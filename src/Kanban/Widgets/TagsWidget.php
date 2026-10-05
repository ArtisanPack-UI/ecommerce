<?php

/**
 * TagsWidget.
 *
 * `tags`: the order's tags — `meta.tags` by default, filterable through
 * `ap.ecommerce.kanban.orderTags` so a tagging satellite can supply its own.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Kanban\Widgets;

use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class TagsWidget extends AbstractKanbanCardWidget
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'tags';

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return __( 'Tags' );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order         $order   Order.
     * @param  KanbanColumn  $column  Column.
     *
     * @return array<string, string>
     */
    public function render( Order $order, KanbanColumn $column ): array
    {
        $tags = (array) applyFilters( 'ap.ecommerce.kanban.orderTags', (array) ( $order->meta['tags'] ?? [] ), $order );
        $tags = array_values( array_filter( array_map( static fn ( mixed $tag ): string => is_scalar( $tag ) ? trim( (string) $tag ) : '', $tags ) ) );

        return $this->payload( implode( ', ', $tags ), 'neutral', [ 'icon' => [] === $tags ? null : 'tag' ] );
    }
}
