<?php

/**
 * ConfigurableKanbanCardWidget contract.
 *
 * Opt-in companion to {@see KanbanCardWidget} for widgets that take
 * per-instance config (ArtisanPack-UI/ecommerce#202). A column's or board's
 * widget list may hold `{ key, config }` entries; the renderer merges
 *
 * 1. the defaults the widget's {@see DescribesConfig::configSchema()} declares,
 * 2. the store's `kanban_card_widgets.default_config` override, then
 * 3. the entry's own `config`,
 *
 * and calls {@see self::renderWithConfig()} with the result. Widgets that
 * only implement {@see KanbanCardWidget} keep being called through
 * `render()` with no config, so 1.x widgets don't break.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
interface ConfigurableKanbanCardWidget extends KanbanCardWidget
{
    /**
     * Render payload for `$order`'s card in `$column` with the merged
     * instance config. Same payload rules as {@see KanbanCardWidget::render()}.
     *
     * @since 1.2.0
     *
     * @param  Order                 $order   Order the card represents.
     * @param  KanbanColumn          $column  Column the card is in.
     * @param  array<string, mixed>  $config  Merged config.
     *
     * @return array{
     *   label: string,
     *   value: string,
     *   tone?: 'danger'|'info'|'neutral'|'success'|'warning',
     *   icon?: string,
     *   tooltip?: string,
     *   href?: string,
     * }
     */
    public function renderWithConfig( Order $order, KanbanColumn $column, array $config ): array;
}
