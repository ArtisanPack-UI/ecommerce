<?php

/**
 * BroadcastGraphQLSubscriptions.
 *
 * Turns engine events into GraphQL subscription broadcasts (engine spec
 * §10.4) when `artisanpack.ecommerce.graphql.subscriptions` is enabled.
 * Payloads render models through their REST resources with admin fields —
 * every channel here is `private-ecommerce.admin`, authorized only for
 * users holding all of `order.viewAny`, `product.viewAny`, and
 * `webhookSubscription.viewAny` (the scopes its events carry).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Listeners;

use ArtisanPackUI\Ecommerce\Broadcasting\GraphQLSubscriptionBroadcast;
use ArtisanPackUI\Ecommerce\Events\OrderStatusChanged;
use ArtisanPackUI\Ecommerce\Events\PaymentSucceeded;
use ArtisanPackUI\Ecommerce\Events\WebhookFailed;
use ArtisanPackUI\Ecommerce\GraphQL\Fields\Subscriptions;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookPayloadFactory;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class BroadcastGraphQLSubscriptions
{
    /**
     * @since 1.0.0
     *
     */
    private readonly WebhookPayloadFactory $payloads;

    /**
     * The admin channel's members may see admin-only fields.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->payloads = new WebhookPayloadFactory( true );
    }

    /**
     * Wires the listeners.
     *
     * @since 1.0.0
     *
     * @param  Dispatcher  $events  Event dispatcher.
     *
     * @return void
     */
    public function subscribe( Dispatcher $events ): void
    {
        $events->listen( OrderStatusChanged::class, [ $this, 'orderStatusChanged' ] );
        $events->listen( PaymentSucceeded::class, [ $this, 'paymentSucceeded' ] );
        $events->listen( WebhookFailed::class, [ $this, 'webhookDeliveryFailed' ] );

        addAction( 'ap.ecommerce.inventory.adjusted', [ $this, 'stockChanged' ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  OrderStatusChanged  $event  Event.
     *
     * @return void
     */
    public function orderStatusChanged( OrderStatusChanged $event ): void
    {
        $this->broadcast( 'orderStatusChanged', $this->payloads->fromEvent( $event ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  PaymentSucceeded  $event  Event.
     *
     * @return void
     */
    public function paymentSucceeded( PaymentSucceeded $event ): void
    {
        $this->broadcast( 'paymentSucceeded', $this->payloads->fromEvent( $event ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  WebhookFailed  $event  Event.
     *
     * @return void
     */
    public function webhookDeliveryFailed( WebhookFailed $event ): void
    {
        $this->broadcast( 'webhookDeliveryFailed', [
            'delivery' => $this->payloads->serialize( $event->delivery ),
            'reason'   => $event->reason->getMessage(),
        ] );
    }

    /**
     * `ap.ecommerce.inventory.adjusted` action.
     *
     * @since 1.0.0
     *
     * @param  InventoryItem  $item      Adjusted item.
     * @param  int            $delta     Applied delta.
     * @param  int            $newLevel  New on-hand quantity.
     *
     * @return void
     */
    public function stockChanged( InventoryItem $item, int $delta, int $newLevel ): void
    {
        $this->broadcast( 'stockChanged', [
            'inventory_item' => $this->payloads->serialize( $item ),
            'delta'          => $delta,
            'new_level'      => $newLevel,
        ] );
    }

    /**
     * @since 1.0.0
     *
     * @param  string                $field    Subscription field.
     * @param  array<string, mixed>  $payload  Payload.
     *
     * @return void
     */
    protected function broadcast( string $field, array $payload ): void
    {
        event( new GraphQLSubscriptionBroadcast( $field, Subscriptions::ADMIN_CHANNEL, $payload ) );
    }
}
