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
use ArtisanPackUI\Ecommerce\Exceptions\CartOperationException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
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
     *
     * @param  CheckoutService   $checkout   Finalize.
     * @param  InventoryService  $inventory  Releases holds.
     */
    public function __construct(
        protected CheckoutService $checkout,
        protected InventoryService $inventory,
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
        $cart = DB::transaction( function () use ( $gatewayKey, $reference ): ?Cart {
            $cart = Cart::query()
                ->where( 'payment_gateway_key', $gatewayKey )
                ->where( 'payment_reference', $reference )
                ->whereNull( 'completed_order_id' )
                ->lockForUpdate()
                ->first();

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
        return Cart::query()
            ->where( 'payment_gateway_key', $gatewayKey )
            ->where( 'payment_reference', $reference )
            ->whereNull( 'completed_order_id' )
            ->first();
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
        $order = Order::query()
            ->where( 'payment_gateway_key', $gatewayKey )
            ->where( 'payment_reference', $reference )
            ->where( 'payment_status', 'pending' )
            ->first();

        return null === $order ? null : Cart::query()->where( 'completed_order_id', $order->id )->first();
    }
}
