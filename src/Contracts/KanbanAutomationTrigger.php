<?php

/**
 * KanbanAutomationTrigger contract.
 *
 * The action half of a kanban automation ("when a card moves to Printing,
 * send an email"). Each `kanban_automations` row names a trigger by
 * registry key and supplies its `trigger_config`. Satellites register
 * triggers (`notify-slack`, `print-shipping-label`, …) against
 * {@see \ArtisanPackUI\Ecommerce\Registries\KanbanAutomationRegistry}.
 *
 * Engine spec §4.13, parent plan §9.4.
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

use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Models\Order;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface KanbanAutomationTrigger
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
     * Human-readable label for the automation builder.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string;

    /**
     * Runs the automation for `$order`.
     *
     * Implementations MUST validate `$config` and throw
     * {@see \InvalidArgumentException} when it is missing or malformed —
     * the runner logs the failure and carries on with the next automation,
     * so one bad row never blocks a card move.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order       The order whose card moved.
     * @param  KanbanAutomation      $automation  The automation row being run.
     * @param  array<string, mixed>  $config      From `kanban_automations.trigger_config`.
     *
     * @return void
     */
    public function fire( Order $order, KanbanAutomation $automation, array $config ): void;
}
