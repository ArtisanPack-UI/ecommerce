<?php

/**
 * AbstractKanbanCardWidget.
 *
 * Base for the built-in kanban card widgets: no live refresh channel by
 * default, and a payload builder that keeps every widget's output in the
 * {@see \ArtisanPackUI\Ecommerce\Contracts\KanbanCardWidget::render()}
 * shape.
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

use ArtisanPackUI\Ecommerce\Contracts\KanbanCardWidget;
use ArtisanPackUI\Ecommerce\Models\Order;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class AbstractKanbanCardWidget implements KanbanCardWidget
{
    /**
     * Built-in widgets only change when the card moves or the order is
     * re-fetched.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return string|null
     */
    public function refreshSubscription( Order $order ): ?string
    {
        return null;
    }

    /**
     * Builds a render payload, dropping empty optional keys.
     *
     * @since 1.0.0
     *
     * @param  string                $value  Display value.
     * @param  string                $tone   Tone.
     * @param  array<string, mixed>  $extra  Optional `icon`, `tooltip`, `href`.
     *
     * @return array{label: string, value: string, tone: string, icon?: string, tooltip?: string, href?: string}
     */
    protected function payload( string $value, string $tone = 'neutral', array $extra = [] ): array
    {
        return array_merge(
            [ 'label' => $this->label(), 'value' => $value, 'tone' => $tone ],
            array_filter( $extra, static fn ( mixed $item ): bool => is_string( $item ) && '' !== $item ),
        );
    }

    /**
     * Human-readable form of a snake_case status value.
     *
     * @since 1.0.0
     *
     * @param  string  $status  Status value.
     *
     * @return string
     */
    protected function humanize( string $status ): string
    {
        return ucfirst( str_replace( [ '_', '-' ], ' ', $status ) );
    }
}
