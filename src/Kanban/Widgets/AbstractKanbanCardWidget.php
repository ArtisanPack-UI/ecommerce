<?php

/**
 * AbstractKanbanCardWidget.
 *
 * Base for the built-in kanban card widgets: no live refresh channel by
 * default, a payload builder that keeps every widget's output in the
 * {@see \ArtisanPackUI\Ecommerce\Contracts\KanbanCardWidget::render()}
 * shape, and per-instance config (ArtisanPack-UI/ecommerce#202): the
 * renderer calls {@see self::renderWithConfig()}, and `render()` reads the
 * merged config through {@see self::config()}.
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

use ArtisanPackUI\Ecommerce\Contracts\ConfigurableKanbanCardWidget;
use ArtisanPackUI\Ecommerce\Contracts\DescribesConfig;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class AbstractKanbanCardWidget implements ConfigurableKanbanCardWidget, DescribesConfig
{
    /**
     * Config for the render in progress.
     *
     * @since 1.2.0
     *
     * @var array<string, mixed>
     */
    private array $instanceConfig = [];

    /**
     * No configuration unless a widget declares some (engine issue #149).
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [];
    }

    /**
     * Renders with `$config` available through {@see self::config()}.
     *
     * @since 1.2.0
     *
     * @param  Order                 $order   Order.
     * @param  KanbanColumn          $column  Column.
     * @param  array<string, mixed>  $config  Merged config.
     *
     * @return array{label: string, value: string, tone?: 'danger'|'info'|'neutral'|'success'|'warning', icon?: string, tooltip?: string, href?: string}
     */
    public function renderWithConfig( Order $order, KanbanColumn $column, array $config ): array
    {
        $previous             = $this->instanceConfig;
        $this->instanceConfig = $config;

        try {
            return $this->render( $order, $column );
        } finally {
            $this->instanceConfig = $previous;
        }
    }

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
     * The merged config for the render in progress, or one value from it.
     * Empty (and `$default` returned) when called through plain `render()`.
     *
     * @since 1.2.0
     *
     * @param  string|null  $key      Config key; null for the whole config.
     * @param  mixed        $default  Value when the key is missing or null.
     *
     * @return mixed
     */
    protected function config( ?string $key = null, mixed $default = null ): mixed
    {
        if ( null === $key ) {
            return $this->instanceConfig;
        }

        return $this->instanceConfig[ $key ] ?? $default;
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
