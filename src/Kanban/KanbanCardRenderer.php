<?php

/**
 * KanbanCardRenderer.
 *
 * Renders the widget payloads for a card (parent plan §9.3). The widget
 * list comes from the column's `card_widgets`, falling back to the board's
 * `settings.card_widgets`, then `artisanpack.ecommerce.kanban.default_card_widgets`.
 * List entries are keys or `{ key, config }` ({@see KanbanWidgetList});
 * widgets implementing {@see ConfigurableKanbanCardWidget} receive config
 * merged as schema defaults → `kanban_card_widgets.default_config` →
 * instance config, and a stored `kanban_card_widgets.label` replaces the
 * widget's label. Each payload is normalized to
 *
 * ```json
 * { "key", "label", "value", "tone", "icon"?, "tooltip"?, "href"?, "refresh_channel"? }
 * ```
 *
 * and passed through `ap.ecommerce.kanban.widgetRendered`. Unregistered
 * widget keys are skipped; a widget that throws is logged and skipped so
 * one broken satellite widget never blanks a board.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Kanban;

use ArtisanPackUI\Ecommerce\Contracts\ConfigurableKanbanCardWidget;
use ArtisanPackUI\Ecommerce\Contracts\DescribesConfig;
use ArtisanPackUI\Ecommerce\Contracts\KanbanCardWidget;
use ArtisanPackUI\Ecommerce\Models\KanbanCardWidget as KanbanCardWidgetOverride;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
use ArtisanPackUI\Ecommerce\Support\ConfigSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanCardRenderer
{
    /**
     * Tones a widget may use; anything else renders as `neutral`.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const TONES = [ 'neutral', 'success', 'warning', 'danger', 'info' ];

    /**
     * Stored widget overrides by key, loaded once per renderer (one per
     * request: the renderer is bound scoped).
     *
     * @since 1.2.0
     *
     * @var Collection<string, KanbanCardWidgetOverride>|null
     */
    private ?Collection $overrides = null;

    /**
     * @since 1.0.0
     *
     * @param  KanbanCardWidgetRegistry  $widgets  Registered widgets.
     */
    public function __construct( private readonly KanbanCardWidgetRegistry $widgets )
    {
    }

    /**
     * Widget payloads for `$order`'s card in `$column`.
     *
     * @since 1.0.0
     * @since 1.2.0 Passes merged per-instance config to configurable widgets.
     *
     * @param  Order         $order   Order.
     * @param  KanbanColumn  $column  Column the card is in.
     *
     * @return array<int, array<string, string|null>>
     */
    public function render( Order $order, KanbanColumn $column ): array
    {
        $payloads = [];

        foreach ( $this->widgetEntries( $column ) as $entry ) {
            $key = $entry['key'];

            if ( ! $this->widgets->has( $key ) ) {
                continue;
            }

            try {
                $widget  = $this->widgets->get( $key );
                $raw     = $widget instanceof ConfigurableKanbanCardWidget
                    ? $widget->renderWithConfig( $order, $column, $this->config( $key, $widget, $entry['config'] ) )
                    : $widget->render( $order, $column );
                $payload = $this->normalize( $key, $raw, $widget, $order );
            } catch ( Throwable $e ) {
                Log::channel( 'ecommerce' )->error( 'Kanban card widget threw; skipping it.', [
                    'widget'   => $key,
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ] );

                continue;
            }

            $payloads[] = $payload;
        }

        return $payloads;
    }

    /**
     * The widget keys configured for `$column`.
     *
     * @since 1.0.0
     *
     * @param  KanbanColumn  $column  Column.
     *
     * @return array<int, string>
     */
    public function widgetKeys( KanbanColumn $column ): array
    {
        return array_column( $this->widgetEntries( $column ), 'key' );
    }

    /**
     * The widget entries configured for `$column` — the column's list, else
     * the board's, else the config default — as `{ key, config }` pairs.
     *
     * @since 1.2.0
     *
     * @param  KanbanColumn  $column  Column.
     *
     * @return array<int, array{key: string, config: array<string, mixed>}>
     */
    public function widgetEntries( KanbanColumn $column ): array
    {
        return KanbanWidgetList::normalize( $this->widgetLists( $column )[ $this->widgetSource( $column ) ] );
    }

    /**
     * Which list {@see self::widgetEntries()} reads for `$column`:
     * `column` when the column has its own, else `board` when the board
     * does, else `default` (the engine config).
     *
     * @since 1.2.0
     *
     * @param  KanbanColumn  $column  Column.
     *
     * @return 'board'|'column'|'default'
     */
    public function widgetSource( KanbanColumn $column ): string
    {
        foreach ( $this->widgetLists( $column ) as $source => $list ) {
            if ( [] !== KanbanWidgetList::normalize( $list ) ) {
                return $source;
            }
        }

        return 'default';
    }

    /**
     * Merged config for one widget instance: schema defaults, then the
     * stored override's `default_config`, then the instance config.
     *
     * @since 1.2.0
     *
     * @param  string                $key       Widget key.
     * @param  KanbanCardWidget      $widget    Widget.
     * @param  array<string, mixed>  $instance  Instance config.
     *
     * @return array<string, mixed>
     */
    public function config( string $key, KanbanCardWidget $widget, array $instance = [] ): array
    {
        $defaults = [];

        if ( $widget instanceof DescribesConfig ) {
            foreach ( ConfigSchema::normalize( $widget->configSchema() ) as $field ) {
                if ( array_key_exists( 'default', $field ) ) {
                    $defaults[ $field['name'] ] = $field['default'];
                }
            }
        }

        $stored = (array) ( $this->override( $key )?->default_config ?? [] );

        return array_merge( $defaults, $stored, array_filter( $instance, static fn ( mixed $value ): bool => null !== $value ) );
    }

    /**
     * Drops the cached widget overrides so the next render re-reads them.
     *
     * @since 1.2.0
     *
     * @return void
     */
    public function forgetOverrides(): void
    {
        $this->overrides = null;
    }

    /**
     * The column, board, and default widget lists for `$column`.
     *
     * @since 1.2.0
     *
     * @param  KanbanColumn  $column  Column.
     *
     * @return array{column: mixed, board: mixed, default: mixed}
     */
    protected function widgetLists( KanbanColumn $column ): array
    {
        return [
            'column'  => $column->card_widgets,
            'board'   => $column->board?->settings['card_widgets'] ?? [],
            'default' => config( 'artisanpack.ecommerce.kanban.default_card_widgets', [] ),
        ];
    }

    /**
     * The stored `kanban_card_widgets` row for `$key`, if any.
     *
     * @since 1.2.0
     *
     * @param  string  $key  Widget key.
     *
     * @return KanbanCardWidgetOverride|null
     */
    protected function override( string $key ): ?KanbanCardWidgetOverride
    {
        $this->overrides ??= KanbanCardWidgetOverride::query()->get()->keyBy( 'key' );

        return $this->overrides->get( $key );
    }

    /**
     * Normalizes one widget payload and runs the render filter.
     *
     * @since 1.0.0
     *
     * @param  string                $key      Registry key.
     * @param  array<string, mixed>  $raw      What the widget returned.
     * @param  KanbanCardWidget      $widget   Widget.
     * @param  Order                 $order    Order.
     *
     * @return array<string, string|null>
     */
    protected function normalize( string $key, array $raw, KanbanCardWidget $widget, Order $order ): array
    {
        $optional = static fn ( mixed $value ): ?string => is_scalar( $value ) && '' !== (string) $value ? (string) $value : null;
        $tone     = in_array( $raw['tone'] ?? null, self::TONES, true ) ? $raw['tone'] : 'neutral';

        $payload = [
            'key'             => $key,
            'label'           => $this->label( $key, $raw, $widget ),
            'value'           => is_scalar( $raw['value'] ?? null ) ? (string) $raw['value'] : '',
            'tone'            => $tone,
            'icon'            => $optional( $raw['icon'] ?? null ),
            'tooltip'         => $optional( $raw['tooltip'] ?? null ),
            'href'            => $optional( $raw['href'] ?? null ),
            'refresh_channel' => $widget->refreshSubscription( $order ),
        ];

        $filtered = applyFilters( 'ap.ecommerce.kanban.widgetRendered', $payload, $widget, $order );

        return is_array( $filtered ) ? $filtered : $payload;
    }

    /**
     * The payload label: the store's override, else what the widget
     * returned, else the widget's own label.
     *
     * @since 1.2.0
     *
     * @param  string                $key     Widget key.
     * @param  array<string, mixed>  $raw     What the widget returned.
     * @param  KanbanCardWidget      $widget  Widget.
     *
     * @return string
     */
    protected function label( string $key, array $raw, KanbanCardWidget $widget ): string
    {
        $override = $this->override( $key )?->label;

        if ( is_string( $override ) && '' !== $override ) {
            return $override;
        }

        return is_scalar( $raw['label'] ?? null ) ? (string) $raw['label'] : $widget->label();
    }
}
