<?php

/**
 * ReconcilePaymentsCommand.
 *
 * `ecommerce:reconcile-payments` (engine spec §11.6): the safety net behind
 * payment webhooks. It looks up payment sessions that went quiet — carts
 * waiting on a payment and placed orders still `pending`, last touched more
 * than `checkout.reconcile_after_minutes` ago (and within the last
 * {@see self::WINDOW_DAYS} days) — asks each provider for the session's
 * status, and settles the settled ones through {@see PaymentReconciler}.
 * Scheduled every 15 minutes.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Console\Commands;

use ArtisanPackUI\Ecommerce\Checkout\CheckoutState;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Services\PaymentReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ReconcilePaymentsCommand extends Command
{
    /**
     * How far back sessions are polled; older ones are left alone.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const WINDOW_DAYS = 7;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'ecommerce:reconcile-payments
        {--minutes= : Only sessions quiet for at least this many minutes (default: checkout.reconcile_after_minutes).}';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Settle checkouts whose payment the provider confirmed or cancelled without the storefront hearing about it.';

    /**
     * @since 1.0.0
     *
     * @param  PaymentGatewayRegistry  $gateways    Gateways.
     * @param  PaymentReconciler       $reconciler  Settles a session.
     *
     * @return int
     */
    public function handle( PaymentGatewayRegistry $gateways, PaymentReconciler $reconciler ): int
    {
        $minutes = $this->option( 'minutes' );
        $minutes = null === $minutes ? (int) config( 'artisanpack.ecommerce.checkout.reconcile_after_minutes', 15 ) : (int) $minutes;
        $before  = Carbon::now()->subMinutes( max( 0, $minutes ) );
        $after   = Carbon::now()->subDays( self::WINDOW_DAYS );
        $counts  = [ PaymentReconciler::FINALIZED => 0, PaymentReconciler::FAILED => 0, PaymentReconciler::SKIPPED => 0, 'errors' => 0 ];

        $settle = function ( string $gatewayKey, string $reference ) use ( $gateways, $reconciler, &$counts ): void {
            $gateway = $gateways->find( $gatewayKey );

            if ( null === $gateway ) {
                ++$counts[ PaymentReconciler::SKIPPED ];

                return;
            }

            try {
                $outcome = PaymentReconciler::outcomeFor( $gateway->retrievePaymentSession( $reference ) );
                $done    = null === $outcome ? PaymentReconciler::SKIPPED : $reconciler->reconcile( $gatewayKey, $reference, $outcome );
            } catch ( Throwable $exception ) {
                Log::channel( 'ecommerce' )->warning( 'Could not reconcile a payment session.', [ 'gateway' => $gatewayKey, 'error' => $exception->getMessage() ] );
                ++$counts['errors'];

                return;
            }

            ++$counts[ $done ];
        };

        Cart::query()
            ->whereNull( 'completed_order_id' )
            ->whereNotNull( 'payment_reference' )
            ->whereNotNull( 'payment_gateway_key' )
            ->where( 'checkout_state', CheckoutState::PAYMENT_PENDING )
            ->whereBetween( 'updated_at', [ $after, $before ] )
            ->select( [ 'id', 'payment_gateway_key', 'payment_reference' ] )
            ->chunkById( 100, function ( $carts ) use ( $settle ): void {
                foreach ( $carts as $cart ) {
                    $settle( (string) $cart->payment_gateway_key, (string) $cart->payment_reference );
                }
            } );

        Order::query()
            ->where( 'system_status', 'pending' )
            ->where( 'payment_status', 'pending' )
            ->whereNotNull( 'payment_reference' )
            ->whereNotNull( 'payment_gateway_key' )
            ->whereBetween( 'placed_at', [ $after, $before ] )
            ->select( [ 'id', 'payment_gateway_key', 'payment_reference' ] )
            ->chunkById( 100, function ( $orders ) use ( $settle ): void {
                foreach ( $orders as $order ) {
                    $settle( (string) $order->payment_gateway_key, (string) $order->payment_reference );
                }
            } );

        $this->info( sprintf(
            'Finalized %d, released %d, left %d, errors %d.',
            $counts[ PaymentReconciler::FINALIZED ],
            $counts[ PaymentReconciler::FAILED ],
            $counts[ PaymentReconciler::SKIPPED ],
            $counts['errors'],
        ) );

        return 0 === $counts['errors'] ? self::SUCCESS : self::FAILURE;
    }
}
