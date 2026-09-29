<?php

/**
 * WebhookSubscriptionDisabled event.
 *
 * Dispatched by {@see \ArtisanPackUI\Ecommerce\Services\WebhookDeliveryService} when a
 * subscription reaches the consecutive-failure ceiling and is switched off
 * (engine spec §8.2 step 6). Listen for it to notify an operator.
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

use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class WebhookSubscriptionDisabled
{
    /**
     * @since 1.0.0
     *
     * @param  WebhookSubscription  $subscription  The subscription that was disabled.
     */
    public function __construct(
        public readonly WebhookSubscription $subscription,
    ) {
    }
}
