<?php

/**
 * WebhookDelivered event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\WebhookDeliveryService} after an
 * outbound delivery received a 2xx response. Engine spec §7 event #40.
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

use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class WebhookDelivered implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  WebhookDelivery  $delivery  The successful delivery row.
     */
    public function __construct(
        public readonly WebhookDelivery $delivery,
    ) {
    }
}
