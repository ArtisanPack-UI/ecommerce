<?php

/**
 * PaymentReconciler.
 *
 * Moves a checkout forward from what the payment provider reports instead
 * of waiting on the shopper's browser — a shopper who closes the tab after
 * confirming, or pays with an asynchronous method, still gets their order
 * (#168). It acts on a normalized outcome for a session reference:
 *
 * - `succeeded` — the cart holding that session is finalized through
 *   {@see CheckoutService::finalize()}, the same idempotent path the
 *   storefront calls (a cart that is already an order has that order's
 *   payment resumed). A finalize already running elsewhere is left to it.
 * - `failed` — the cart's stock holds are released, it steps back to
 *   `payment_selection` (the shopper can try another payment method), and
 *   `ap.ecommerce.checkout.failed` fires. A placed order waiting on that
 *   session is left pending for a retry.
 * - `requires_action` and `refunded` need nothing here (refunds are recorded
 *   through the refund flow).
 *
 * Checkouts are matched on `payment_gateway_key` against the key set from
 * {@see self::webhookGatewayKeys()}: the gateway's own key, plus the
 * registered sibling keys it declares through {@see SharesWebhookEndpoint}
 * (#207). When a reference matches more than one checkout across those
 * keys, the most recently updated one wins and a warning is logged.
 *
 * Outcomes come from verified gateway webhooks ({@see ReconcilePaymentSession})
 * and from `ecommerce:reconcile-payments`, which polls sessions that went
 * quiet.
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

use ArtisanPackUI\Ecommerce\Checkout\CheckoutState;
use ArtisanPackUI\Ecommerce\Contracts\SharesWebhookEndpoint;
use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PaymentReconciler
{
    /**
     * What a reconcile did: finalized the cart or resumed its order.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const FINALIZED = 'finalized';

    /**
     * What a reconcile did: released a failed payment's cart.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const FAILED = 'failed';

    /**
     * What a reconcile did: nothing (no matching checkout, nothing to do, or
     * a checkout failure the shopper has to fix).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SKIPPED = 'skipped';

    /**
     * @since 1.0.0
     * @since 1.3.0 Takes the payment gateway registry.
     *
     * @param  CheckoutService              $checkout   Finalize.
     * @param  InventoryService             $inventory  Releases holds.
     * @param  PaymentGatewayRegistry|null  $gateways   Resolves the gateway behind a key (the container's registry when omitted).
     */
    public function __construct(
        protected CheckoutService $checkout,
        protected InventoryService $inventory,
        protected ?PaymentGatewayRegistry $gateways = null,
    ) {
    }

    /**
     * Acts on `$outcome` for the session `$reference` made with `$gatewayKey`.
     *
     * @since 1.0.0
     *
     * @param  string  $gatewayKey  Gateway key.
     * @param  string  $reference   Session reference.
     * @param  string  $outcome     `WebhookResult::OUTCOME_*` value.
     *
     * @return string One of {@see self::FINALIZED}, {@see self::FAILED}, {@see self::SKIPPED}.
     */
    public function reconcile( string $gatewayKey, string $reference, string $outcome ): string
    {
        if ( '' === $reference ) {
            return self::SKIPPED;
        }

        return match ( $outcome ) {
            WebhookResult::OUTCOME_SUCCEEDED => $this->succeeded( $gatewayKey, $reference ),
            WebhookResult::OUTCOME_FAILED    => $this->failed( $gatewayKey, $reference ),
            default                          => self::SKIPPED,
        };
    }

    /**
     * The outcome a polled session's status stands for, if it is settled.
     *
     * @since 1.0.0
     *
     * @param  PaymentSession  $session  Session.
     *
     * @return string|null
     */
    public static function outcomeFor( PaymentSession $session ): ?string
    {
        // `processing` (an asynchronous method still clearing) isn't settled yet.
        return match ( true ) {
            in_array( $session->status, [ PaymentSession::STATUS_AUTHORIZED, PaymentSession::STATUS_SUCCEEDED ], true ) => WebhookResult::OUTCOME_SUCCEEDED,
            $session->isCanceled()                                                                                      => WebhookResult::OUTCOME_FAILED,
            default                                                                                                     => null,
        };
    }

    /**
     * The gateway keys whose checkouts an outcome reported for `$gatewayKey`
     * may settle.
     *
     * Always `$gatewayKey` itself. When the gateway registered under it
     * implements {@see SharesWebhookEndpoint}, the keys it declares are
     * added, minus any that aren't registered in {@see PaymentGatewayRegistry}:
     * a gateway can never claim sessions under a key nothing has registered.
     * An unregistered `$gatewayKey` yields just itself.
     *
     * @since 1.3.0
     *
     * @param  string  $gatewayKey  Key of the gateway that reported the outcome.
     *
     * @return array<int, string>
     */
    public function webhookGatewayKeys( string $gatewayKey ): array
    {
        $registry = $this->gateways ?? app( PaymentGatewayRegistry::class );
        $gateway  = $registry->find( $gatewayKey );

        if ( ! $gateway instanceof SharesWebhookEndpoint ) {
            return [ $gatewayKey ];
        }

        $keys = [ $gatewayKey ];

        foreach ( $gateway->webhookGatewayKeys() as $key ) {
            if ( is_string( $key ) && $registry->has( $key ) && ! in_array( $key, $keys, true ) ) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Finalizes the checkout holding a session the provider says succeeded.
     *
     * @since 1.0.0
     *
     * @param  string  $gatewayKey  Gateway key.
     * @param  string  $reference   Session reference.
     *
     * @return string
     */
    protected function succeeded( string $gatewayKey, string $reference ): string
    {
        $cart = $this->cartFor( $gatewayKey, $reference ) ?? $this->cartForOrderWith( $gatewayKey, $reference );

        if ( null === $cart ) {
            return self::SKIPPED;
        }

        try {
            $result = $this->checkout->finalize( $cart, $reference, [ 'source' => 'reconcile' ] );
        } catch ( CartOperationException $exception ) {
            // A finalize already in flight, a changed total, …: the shopper
            // (or the in-flight request) settles it.
            Log::channel( 'ecommerce' )->info( 'A provider-confirmed payment could not be finalized from its webhook.', [
                'cart_id' => $cart->id,
                'code'    => $exception->errorCode,
            ] );

            return self::SKIPPED;
        }

        Log::channel( 'ecommerce' )->info( 'Finalized a checkout from its payment provider.', [
            'cart_id'  => $cart->id,
            'order_id' => $result->order->id,
            'status'   => $result->status(),
        ] );

        return self::FINALIZED;
    }

    /**
     * Releases a cart whose payment the provider says failed.
     *
     * @since 1.0.0
     *
     * @param  string  $gatewayKey  Gateway key.
     * @param  string  $reference   Session reference.
     *
     * @return string
     */
    protected function failed( string $gatewayKey, string $reference ): string
    {
        $keys = $this->webhookGatewayKeys( $gatewayKey );

        $cart = DB::transaction( function () use ( $keys, $reference ): ?Cart {
            /** @var Cart|null $cart */
            $cart = $this->mostRecent(
                Cart::query()
                    ->whereIn( 'payment_gateway_key', $keys )
                    ->where( 'payment_reference', $reference )
                    ->whereNull( 'completed_order_id' )
                    ->lockForUpdate(),
                $keys,
                $reference,
            );

            if ( null === $cart ) {
                return null;
            }

            $this->inventory->releaseFor( $cart );

            $cart->forceFill( [ 'checkout_state' => CheckoutState::PAYMENT_SELECTION ] )->save();

            return $cart;
        } );

        if ( null === $cart ) {
            return self::SKIPPED;
        }

        doAction( 'ap.ecommerce.checkout.failed', $cart, new RuntimeException( 'The payment provider reported the payment as failed.' ) );

        return self::FAILED;
    }

    /**
     * The open cart holding the session.
     *
     * @since 1.0.0
     *
     * @param  string  $gatewayKey  Gateway key.
     * @param  string  $reference   Session reference.
     *
     * @return Cart|null
     */
    protected function cartFor( string $gatewayKey, string $reference ): ?Cart
    {
        $keys = $this->webhookGatewayKeys( $gatewayKey );

        /** @var Cart|null */
        return $this->mostRecent(
            Cart::query()
                ->whereIn( 'payment_gateway_key', $keys )
                ->where( 'payment_reference', $reference )
                ->whereNull( 'completed_order_id' ),
            $keys,
            $reference,
        );
    }

    /**
     * The converted cart of a pending order paid with the session (its
     * payment is resumed through finalize).
     *
     * @since 1.0.0
     *
     * @param  string  $gatewayKey  Gateway key.
     * @param  string  $reference   Session reference.
     *
     * @return Cart|null
     */
    protected function cartForOrderWith( string $gatewayKey, string $reference ): ?Cart
    {
        $keys = $this->webhookGatewayKeys( $gatewayKey );

        $order = $this->mostRecent(
            Order::query()
                ->whereIn( 'payment_gateway_key', $keys )
                ->where( 'payment_reference', $reference )
                ->where( 'payment_status', 'pending' ),
            $keys,
            $reference,
        );

        return null === $order ? null : Cart::query()->where( 'completed_order_id', $order->id )->first();
    }

    /**
     * The most recently updated row `$query` matches, logging a warning when
     * the reference matched more than one (two checkouts across sibling
     * gateway keys holding the same provider reference).
     *
     * @since 1.3.0
     *
     * @param  Builder<Cart>|Builder<Order>  $query      Matching query.
     * @param  array<int, string>            $keys       Gateway keys matched.
     * @param  string                        $reference  Session reference.
     *
     * @return Model|null
     */
    protected function mostRecent( Builder $query, array $keys, string $reference ): ?Model
    {
        $matches = $query
            ->orderByDesc( 'updated_at' )
            ->orderByDesc( 'id' )
            ->limit( 2 )
            ->get();

        if ( $matches->count() > 1 ) {
            Log::channel( 'ecommerce' )->warning( 'A payment reference matched more than one checkout; reconciling the most recent one.', [
                'model'        => class_basename( $query->getModel() ),
                'id'           => $matches->first()->getKey(),
                'reference'    => $reference,
                'gateway_keys' => $keys,
            ] );
        }

        return $matches->first();
    }
}
