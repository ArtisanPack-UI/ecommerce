<?php

/**
 * WebhookFailed event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\WebhookDeliveryService} after an
 * outbound delivery attempt got a non-2xx response or failed to connect.
 * Engine spec §7 event #41.
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
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class WebhookFailed implements ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  WebhookDelivery  $delivery  The delivery row after the failed attempt.
     * @param  Throwable        $reason    Why the attempt failed.
     */
    public function __construct(
        public readonly WebhookDelivery $delivery,
        public readonly Throwable $reason,
    ) {
    }
}
