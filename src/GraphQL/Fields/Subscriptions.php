<?php

/**
 * Subscriptions.
 *
 * Root `Subscription` fields of the ecommerce GraphQL schema (engine spec
 * §10.4). The fields describe the real-time payloads; delivery happens
 * over Laravel broadcasting (Reverb / Pusher / Echo), not over the HTTP
 * endpoint: when `artisanpack.ecommerce.graphql.subscriptions` is on,
 * {@see \ArtisanPackUI\Ecommerce\Listeners\BroadcastGraphQLSubscriptions}
 * broadcasts each event on its private channel as `{field}` with a body of
 * `{ "data": { "{field}": … } }` — the same shape a GraphQL response for
 * that subscription field would have.
 *
 * | Field                   | Channel                         | Source                                 |
 * |-------------------------|---------------------------------|----------------------------------------|
 * | `orderStatusChanged`    | `private-ecommerce.admin`       | `OrderStatusChanged` event             |
 * | `paymentSucceeded`      | `private-ecommerce.admin`       | `PaymentSucceeded` event               |
 * | `stockChanged`          | `private-ecommerce.admin`       | `ap.ecommerce.inventory.adjusted` hook |
 * | `webhookDeliveryFailed` | `private-ecommerce.admin`       | `WebhookFailed` event                  |
 *
 * `orderPlaced`, `kanbanCardMoved`, and `reviewSubmitted` join this list
 * with the checkout, kanban, and review services that fire them. Satellites
 * add their own through `ap.ecommerce.graphql.extend` and broadcast a
 * {@see \ArtisanPackUI\Ecommerce\Broadcasting\GraphQLSubscriptionBroadcast}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\GraphQL\Fields;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class Subscriptions
{
    /**
     * Channel (without the `private-` prefix) for admin surfaces.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ADMIN_CHANNEL = 'ecommerce.admin';

    /**
     * Payload types.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>>
     */
    public function types(): array
    {
        return [
            'OrderStatusChangedEvent' => [
                'description' => 'An order moved between system statuses.',
                'fields'      => [ 'order' => 'Order!', 'from' => 'String!', 'to' => 'String!' ],
            ],
            'PaymentSucceededEvent' => [
                'description' => 'A payment was captured for an order.',
                'fields'      => [ 'order' => 'Order!', 'payment' => 'JSON' ],
            ],
            'StockChangedEvent' => [
                'description' => 'An inventory level changed.',
                'fields'      => [ 'inventory_item' => 'InventoryItem!', 'delta' => 'Int!', 'new_level' => 'Int!' ],
            ],
            'WebhookDeliveryFailedEvent' => [
                'description' => 'An outbound webhook delivery attempt failed.',
                'fields'      => [ 'delivery' => 'WebhookDelivery!', 'reason' => 'String!' ],
            ],
        ];
    }

    /**
     * Root subscription fields. Each resolves to the broadcast payload.
     *
     * @since 1.0.0
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(): array
    {
        $payload = static fn ( mixed $root ): mixed => $root;

        return [
            'orderStatusChanged'    => [ 'type' => 'OrderStatusChangedEvent', 'resolve' => $payload ],
            'paymentSucceeded'      => [ 'type' => 'PaymentSucceededEvent', 'resolve' => $payload ],
            'stockChanged'          => [ 'type' => 'StockChangedEvent', 'resolve' => $payload ],
            'webhookDeliveryFailed' => [ 'type' => 'WebhookDeliveryFailedEvent', 'resolve' => $payload ],
        ];
    }
}
