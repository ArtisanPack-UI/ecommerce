<?php

/**
 * KanbanCardWidgetRegistry.
 *
 * Runtime registry of {@see \ArtisanPackUI\Ecommerce\Contracts\KanbanCardWidget}
 * implementations. Card widgets rendered on kanban cards. Core registers total, item-count, customer, shipping-method, tags, days-in-column, payment-status, fulfillment-status. Engine spec §5 row 10.
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

use ArtisanPackUI\Ecommerce\Contracts\KanbanCardWidget;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @extends AbstractContractRegistry<KanbanCardWidget>
 *
 * @method KanbanCardWidget get( string $key )
 */
class KanbanCardWidgetRegistry extends AbstractContractRegistry
{
    /**
     * @since 1.0.0
     *
     * @return class-string<KanbanCardWidget>
     */
    protected function contract(): string
    {
        return KanbanCardWidget::class;
    }
}
