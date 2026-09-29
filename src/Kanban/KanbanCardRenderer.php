<?php

/**
 * KanbanCardRenderer.
 *
 * Renders the widget payloads for a card (parent plan §9.3). The widget
 * list comes from the column's `card_widgets`, falling back to the board's
 * `settings.card_widgets`, then `artisanpack.ecommerce.kanban.default_card_widgets`.
 * Each payload is normalized to
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

use ArtisanPackUI\Ecommerce\Contracts\KanbanCardWidget;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
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
     *
     * @param  Order         $order   Order.
     * @param  KanbanColumn  $column  Column the card is in.
     *
     * @return array<int, array<string, string|null>>
     */
    public function render( Order $order, KanbanColumn $column ): array
    {
        $payloads = [];

        foreach ( $this->widgetKeys( $column ) as $key ) {
            if ( ! $this->widgets->has( $key ) ) {
                continue;
            }

            try {
                $widget  = $this->widgets->get( $key );
                $payload = $this->normalize( $key, $widget->render( $order, $column ), $widget, $order );
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
        $keys = (array) $column->card_widgets;

        if ( [] === $keys ) {
            $keys = (array) ( $column->board?->settings['card_widgets'] ?? [] );
        }

        if ( [] === $keys ) {
            $keys = (array) config( 'artisanpack.ecommerce.kanban.default_card_widgets', [] );
        }

        return array_values( array_unique( array_filter( $keys, 'is_string' ) ) );
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
            'label'           => is_scalar( $raw['label'] ?? null ) ? (string) $raw['label'] : $widget->label(),
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
}
