<?php

/**
 * GraphQLSubscriptionBroadcast.
 *
 * Broadcasts one GraphQL subscription event (engine spec §10.4) on a
 * private channel. The event name is the subscription field
 * (`orderStatusChanged`) and the body is `{ "data": { "<field>": … } }`,
 * so an Echo listener receives exactly what a GraphQL subscription
 * response for that field would contain.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class GraphQLSubscriptionBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    /**
     * @since 1.0.0
     *
     * @param  string                $field    Subscription field name.
     * @param  string                $channel  Private channel name (without `private-`).
     * @param  array<string, mixed>  $payload  The field's value.
     */
    public function __construct(
        public readonly string $field,
        public readonly string $channel,
        public readonly array $payload,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [ new PrivateChannel( $this->channel ) ];
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function broadcastAs(): string
    {
        return $this->field;
    }

    /**
     * @since 1.0.0
     *
     * @return array{data: array<string, array<string, mixed>>}
     */
    public function broadcastWith(): array
    {
        return [ 'data' => [ $this->field => $this->payload ] ];
    }
}
