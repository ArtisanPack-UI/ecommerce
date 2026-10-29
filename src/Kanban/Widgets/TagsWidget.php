<?php

/**
 * TagsWidget.
 *
 * `tags`: the order's tags — `meta.tags` by default, filterable through
 * `ap.ecommerce.kanban.orderTags` so a tagging satellite can supply its own.
 * `max_tags` (per instance) caps how many are listed; the rest show as
 * "+N".
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
use ArtisanPackUI\Ecommerce\Support\ConfigField;

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
     * `max_tags`: how many tags to list before "+N".
     *
     * @since 1.2.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [
            ConfigField::make( 'max_tags', 'number', __( 'Tags to show' ), [
                'rules' => [ 'integer', 'min:1' ],
                'help'  => __( 'Further tags are summarized as "+N". Leave empty to show every tag.' ),
            ] ),
        ];
    }

    /**
     * @since 1.0.0
     * @since 1.2.0 Honors `max_tags`.
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

        $max   = $this->config( 'max_tags' );
        $shown = is_numeric( $max ) && (int) $max > 0 ? array_slice( $tags, 0, (int) $max ) : $tags;
        $value = implode( ', ', $shown );

        if ( count( $shown ) < count( $tags ) ) {
            $value .= ' ' . __( '+:count', [ 'count' => count( $tags ) - count( $shown ) ] );
        }

        return $this->payload( $value, 'neutral', [
            'icon'    => [] === $tags ? null : 'tag',
            'tooltip' => count( $shown ) < count( $tags ) ? implode( ', ', $tags ) : null,
        ] );
    }
}
