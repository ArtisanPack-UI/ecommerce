<?php

/**
 * WebhookDispatcher.
 *
 * Fans an event out to every active subscription that listens for it
 * (engine spec §8.2 steps 2–3): one `webhook_deliveries` row per
 * subscription, then a queued {@see DeliverWebhookJob} per row.
 *
 * New rows are written with `next_retry_at` a short lease in the future,
 * so the `ecommerce:retry-webhook-deliveries` sweep only picks a row up if
 * its queued job was lost — never while the job is still pending.
 *
 * Satellites fire their own events with
 * `app( WebhookDispatcher::class )->dispatch( 'subscription.renewed', $data )`.
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

use ArtisanPackUI\Ecommerce\Jobs\DeliverWebhookJob;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class WebhookDispatcher
{
    /**
     * JSON encoding used for the delivered body and its `payload_hash`, so
     * the ledger hash matches the bytes a receiver gets (absent a
     * `webhook.delivering` filter change).
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Records and queues a delivery of `$event` to every listening subscription.
     *
     * @since 1.0.0
     *
     * @param  string                $event  Wire event name (e.g. `order.refunded`).
     * @param  array<string, mixed>  $data   Event data (the payload's `data` key).
     *
     * @return Collection<int, WebhookDelivery>
     */
    public function dispatch( string $event, array $data ): Collection
    {
        $subscriptions = WebhookSubscription::query()
            ->active()
            ->get()
            ->filter( static fn ( WebhookSubscription $subscription ): bool => $subscription->listensTo( $event ) );

        if ( $subscriptions->isEmpty() ) {
            return new Collection();
        }

        $payload = [
            'id'         => (string) Str::uuid(),
            'event'      => $event,
            'created_at' => Carbon::now()->toIso8601String(),
            'data'       => $data,
        ];

        return $subscriptions
            ->map( fn ( WebhookSubscription $subscription ): WebhookDelivery => $this->queue( $subscription, $event, $payload ) )
            ->values();
    }

    /**
     * Records one delivery row and queues its job.
     *
     * @since 1.0.0
     *
     * @param  WebhookSubscription   $subscription  Target subscription.
     * @param  string                $event         Wire event name.
     * @param  array<string, mixed>  $payload       Full payload envelope.
     *
     * @return WebhookDelivery
     */
    public function queue( WebhookSubscription $subscription, string $event, array $payload ): WebhookDelivery
    {
        $claim = Carbon::now()->addSeconds( self::claimSeconds() )->startOfSecond();

        $delivery = WebhookDelivery::query()->create( [
            'subscription_id' => $subscription->id,
            'event'           => $event,
            'payload'         => $payload,
            'payload_hash'    => hash( 'sha256', (string) json_encode( $payload, self::JSON_FLAGS ) ),
            'next_retry_at'   => $claim,
        ] + self::subjectIds( $payload ) );

        DeliverWebhookJob::dispatch( $delivery->id, $claim->toDateTimeString() )->afterCommit();

        return $delivery;
    }

    /**
     * How long a queued or in-flight delivery is claimed before the retry
     * sweep may pick it up again.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public static function claimSeconds(): int
    {
        return max( 30, (int) config( 'artisanpack.ecommerce.webhooks.claim_seconds', 300 ) );
    }

    /**
     * The order and customer a payload is about, stored on the delivery row
     * so customer delete-and-anonymize can find it through an index.
     *
     * Searches the payload's `data` breadth-first, so the event's top-level
     * subject wins over nested references. An order is an object with
     * `type: order` or an `order_id` key; a customer is an object with
     * `type: customer` or a `customer_id` key (an order's `customer_id`
     * counts).
     *
     * @since 1.0.0
     *
     * @param  array<array-key, mixed>  $payload  Full payload envelope.
     *
     * @return array{order_id: int|null, customer_id: int|null}
     */
    public static function subjectIds( array $payload ): array
    {
        $found = [ 'order_id' => null, 'customer_id' => null ];
        $queue = [ is_array( $payload['data'] ?? null ) ? $payload['data'] : $payload ];

        while ( [] !== $queue && ( null === $found['order_id'] || null === $found['customer_id'] ) ) {
            $node = array_shift( $queue );
            $type = $node['type'] ?? null;
            $id   = is_numeric( $node['id'] ?? null ) ? (int) $node['id'] : null;

            foreach ( [ 'order' => 'order_id', 'customer' => 'customer_id' ] as $kind => $column ) {
                if ( null !== $found[ $column ] ) {
                    continue;
                }

                if ( $kind === $type && null !== $id ) {
                    $found[ $column ] = $id;
                } elseif ( is_numeric( $node[ $column ] ?? null ) ) {
                    $found[ $column ] = (int) $node[ $column ];
                }
            }

            foreach ( $node as $value ) {
                if ( is_array( $value ) ) {
                    $queue[] = $value;
                }
            }
        }

        return $found;
    }
}
