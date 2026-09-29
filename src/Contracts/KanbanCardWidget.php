<?php

/**
 * KanbanCardWidget contract.
 *
 * A small info chunk rendered on a kanban card ("Total: $124.00",
 * "3 items", "Days in this column: 3"). Widgets return a framework-agnostic
 * payload — never HTML — so the Livewire, React, and Vue kanban satellites
 * all consume the same data and decide their own markup. Satellites
 * register widgets against
 * {@see \ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry}.
 *
 * Engine spec §4.12, parent plan §9.3.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface KanbanCardWidget
{
    /**
     * Registry key (engine spec §2.5).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string;

    /**
     * Human-readable label for the column widget picker.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string;

    /**
     * Framework-agnostic render payload for `$order`'s card in `$column`.
     *
     * Implementations MUST NOT mutate the order and MUST NOT return HTML.
     * `label` and `value` are required strings; `tone` is one of
     * `neutral`, `success`, `warning`, `danger`, `info`.
     *
     * @since 1.0.0
     *
     * @param  Order         $order   Order the card represents.
     * @param  KanbanColumn  $column  Column the card is in.
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
    public function render( Order $order, KanbanColumn $column ): array;

    /**
     * Optional broadcast channel whose messages should trigger a
     * client-side refresh of just this widget (avoids a full-card
     * re-render). Return `null` when the widget only changes on card moves.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order the card represents.
     *
     * @return string|null
     */
    public function refreshSubscription( Order $order ): ?string;
}
