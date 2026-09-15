<?php

/**
 * OrderEdited event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\OrderEditService::apply()}
 * (and rollback, which is itself an append-only edit) after the diff has been
 * persisted. Engine spec §7 event #12; plan §7.6.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Events;

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderEdit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderEdited
{
    /**
     * @since 1.0.0
     *
     * @param  Order                 $order  The refreshed order after the edit.
     * @param  array<string, mixed>  $diff   The diff JSON that was persisted on {@see OrderEdit::$diff}.
     * @param  OrderEdit             $edit   The append-only audit row that was written.
     */
    public function __construct(
        public readonly Order $order,
        public readonly array $diff,
        public readonly OrderEdit $edit,
    ) {
    }
}
