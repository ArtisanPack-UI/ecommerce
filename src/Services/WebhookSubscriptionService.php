<?php

/**
 * WebhookSubscriptionService.
 *
 * Creates and updates outbound webhook subscriptions (engine spec §3.29)
 * for both the REST admin endpoints and the GraphQL mutations, so the two
 * surfaces share one set of rules:
 *
 * - a signing secret is generated when none is supplied;
 * - `ap.ecommerce.webhook.subscribing` (filter) sees the attributes before
 *   insert and may return `null` to abort (engine spec §6.16);
 * - re-activating a subscription clears its consecutive-failure count, so
 *   it gets the full failure budget again.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class WebhookSubscriptionService
{
    /**
     * @since 1.0.0
     *
     * @param  WebhookDispatcher  $dispatcher  Queues replayed deliveries.
     */
    public function __construct( protected WebhookDispatcher $dispatcher )
    {
    }

    /**
     * Creates a subscription, or returns `null` when a filter aborted it.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  `name`, `url`, `events`, optional `secret` / `is_active`.
     *
     * @return WebhookSubscription|null
     */
    public function create( array $attributes ): ?WebhookSubscription
    {
        $attributes['secret'] = isset( $attributes['secret'] ) && '' !== (string) $attributes['secret']
            ? (string) $attributes['secret']
            : 'whsec_' . Str::random( 48 );
        $attributes['events'] = array_values( array_unique( (array) ( $attributes['events'] ?? [] ) ) );

        $filtered = applyFilters( 'ap.ecommerce.webhook.subscribing', $attributes );

        if ( null === $filtered ) {
            return null;
        }

        return WebhookSubscription::query()->create( (array) $filtered );
    }

    /**
     * Applies a partial update.
     *
     * @since 1.0.0
     *
     * @param  WebhookSubscription   $subscription  Subscription.
     * @param  array<string, mixed>  $attributes    Changed attributes.
     *
     * @return WebhookSubscription
     */
    public function update( WebhookSubscription $subscription, array $attributes ): WebhookSubscription
    {
        if ( array_key_exists( 'events', $attributes ) ) {
            $attributes['events'] = array_values( array_unique( (array) $attributes['events'] ) );
        }

        $subscription->fill( $attributes );

        if ( $subscription->isDirty( 'is_active' ) && $subscription->is_active ) {
            $subscription->consecutive_failures = 0;
        }

        $subscription->save();

        return $subscription;
    }

    /**
     * Re-sends a past delivery as a new ledger row (the original row is
     * kept so the audit trail shows both attempts). Returns null when the
     * subscription is inactive: the new row would never be sent, so the
     * operator must re-enable the subscription first.
     *
     * @since 1.0.0
     *
     * @param  WebhookDelivery  $delivery  Delivery to replay.
     *
     * @return WebhookDelivery|null
     */
    public function replay( WebhookDelivery $delivery ): ?WebhookDelivery
    {
        $subscription = $delivery->subscription;

        if ( null === $subscription || ! $subscription->is_active ) {
            return null;
        }

        return $this->dispatcher->queue( $subscription, $delivery->event, (array) $delivery->payload );
    }

    /**
     * Puts back on the retry schedule the deliveries that were parked while
     * the subscription was inactive — not delivered, attempts left, and
     * taken off the schedule — so re-enabling a subscription can catch up
     * on what it missed (audit D17). They are sent by the retry sweep
     * (`ecommerce:retry-webhook-deliveries`). Returns how many were
     * requeued; none while the subscription is still inactive.
     *
     * Re-enabling a subscription does not do this on its own: old events
     * reach the receiver only when an operator asks for them.
     *
     * @since 1.0.0
     *
     * @param  WebhookSubscription  $subscription  Subscription.
     *
     * @return int
     */
    public function replayParked( WebhookSubscription $subscription ): int
    {
        if ( ! $subscription->is_active ) {
            return 0;
        }

        $maxAttempts = max( 1, (int) config( 'artisanpack.ecommerce.webhooks.max_attempts', WebhookDeliveryService::DEFAULT_MAX_ATTEMPTS ) );

        return WebhookDelivery::query()
            ->where( 'subscription_id', $subscription->id )
            ->whereNull( 'delivered_at' )
            ->whereNull( 'next_retry_at' )
            ->where( 'attempts', '<', $maxAttempts )
            ->update( [ 'next_retry_at' => Carbon::now() ] );
    }
}
