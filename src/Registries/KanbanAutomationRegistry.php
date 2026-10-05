<?php

/**
 * KanbanAutomationRegistry.
 *
 * Runtime registry of {@see \ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger}
 * implementations. Triggers run by kanban automations when a card moves. Core registers send-email, dispatch-job, webhook, update-order-field, create-shipment, print-shipping-label. Engine spec §5 row 11.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Registries;

use ArtisanPackUI\Ecommerce\Contracts\KanbanAutomationTrigger;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @extends AbstractContractRegistry<KanbanAutomationTrigger>
 *
 * @method KanbanAutomationTrigger get( string $key )
 */
class KanbanAutomationRegistry extends AbstractContractRegistry
{
    /**
     * @since 1.0.0
     *
     * @return class-string<KanbanAutomationTrigger>
     */
    protected function contract(): string
    {
        return KanbanAutomationTrigger::class;
    }
}
