<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Checkout\CheckoutState;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeClientFactory;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeGateway;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeSignatureVerifier;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\InventoryReservation;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\PaymentReconciler;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

require_once __DIR__ . '/CheckoutTestHelpers.php';

uses( RefreshDatabase::class );

const RECONCILE_WEBHOOK_SECRET = 'whsec_reconcile';

/**
 * Answers Stripe API calls from a `METHOD path` → [status, body] map.
 *
 * @param  array<string, array{0: int, 1: array<string, mixed>}>  $responses
 */
function reconcileStripeHttp( array $responses ): object
{
    $client = new class( $responses ) implements ClientInterface {
        /** @var array<int, string> */
        public array $calls = [];

        public function __construct( private readonly array $responses )
        {
        }

        public function request( $method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null ): array
        {
            $call          = strtoupper( $method ) . ' ' . parse_url( $absUrl, PHP_URL_PATH );
            $this->calls[] = $call;
            $response      = $this->responses[ $call ] ?? throw new ApiConnectionException( "Unexpected call {$call}" );

            return [ json_encode( $response[1] ), $response[0], [] ];
        }
    };

    ApiRequestor::setHttpClient( $client );

    return $client;
}

/**
 * Posts a Stripe event to the webhook endpoint, signed like Stripe does.
 *
 * @param  array<string, mixed>  $object
 */
function reconcilePostEvent( $test, string $id, string $type, array $object ): Illuminate\Testing\TestResponse
{
    $payload   = json_encode( [ 'id' => $id, 'object' => 'event', 'type' => $type, 'api_version' => '2024-06-20', 'data' => [ 'object' => $object ] ], JSON_THROW_ON_ERROR );
    $timestamp = time();
    $signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, RECONCILE_WEBHOOK_SECRET );

    return $test->call( 'POST', '/ecommerce/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE'          => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => sprintf( 't=%d,v1=%s', $timestamp, $signature ),
    ], $payload );
}

/**
 * A ready cart whose Stripe PaymentIntent `pi_123` the shopper confirmed.
 */
function reconcileStripeCart( $product ): Cart
{
    $cart = readyCart( $product );
    $cart->forceFill( [ 'payment_gateway_key' => 'stripe', 'payment_reference' => 'pi_123', 'checkout_state' => CheckoutState::PAYMENT_PENDING ] )->save();

    return $cart->refresh();
}

function reconcileIntent( string $status, int $amount = 2_500 ): array
{
    return [ 'id' => 'pi_123', 'object' => 'payment_intent', 'status' => $status, 'amount' => $amount, 'amount_received' => 'succeeded' === $status ? $amount : 0, 'amount_capturable' => 0, 'currency' => 'usd' ];
}

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.gateways.stripe.secret_key', 'sk_test_dummy' );
    config()->set( 'artisanpack.ecommerce.gateways.stripe.webhook_secret', RECONCILE_WEBHOOK_SECRET );

    $this->fake = checkoutGateway();
    app( PaymentGatewayRegistry::class )->register( 'stripe', new StripeGateway( app( StripeClientFactory::class ), app( StripeSignatureVerifier::class ), app( 'config' ) ) );
    checkoutZone();

    $this->product = checkoutProduct();
} );

afterEach( function (): void {
    ApiRequestor::setHttpClient( null );
} );

describe( 'webhooks', function (): void {
    it( 'places and settles the order when Stripe reports the payment succeeded', function (): void {
        $cart = reconcileStripeCart( $this->product );
        reconcileStripeHttp( [ 'GET /v1/payment_intents/pi_123' => [ 200, reconcileIntent( 'succeeded' ) ] ] );

        reconcilePostEvent( $this, 'evt_1', 'payment_intent.succeeded', reconcileIntent( 'succeeded' ) )->assertOk();

        $order = Order::query()->sole();

        expect( $order->payment_status )->toBe( 'paid' )
            ->and( $order->payment_reference )->toBe( 'pi_123' )
            ->and( $cart->refresh()->completed_order_id )->toBe( $order->id )
            ->and( $cart->checkout_state )->toBe( CheckoutState::COMPLETED );

        // Stripe redelivers: the same event settles nothing twice.
        reconcilePostEvent( $this, 'evt_1', 'payment_intent.succeeded', reconcileIntent( 'succeeded' ) )->assertOk()->assertJsonPath( 'duplicate', true );

        expect( Order::query()->count() )->toBe( 1 );
    } );

    it( 'releases the cart\'s stock and fires checkout.failed when the payment failed', function (): void {
        InventoryItem::factory()->create( [ 'stockable_type' => $this->product->getMorphClass(), 'stockable_id' => $this->product->id, 'quantity_on_hand' => 5 ] );
        $cart   = reconcileStripeCart( $this->product );
        $failed = [];
        addAction( 'ap.ecommerce.checkout.failed', function ( Cart $cart ) use ( &$failed ): void {
            $failed[] = $cart->id;
        } );

        expect( InventoryReservation::query()->count() )->toBe( 1 );

        reconcilePostEvent( $this, 'evt_2', 'payment_intent.payment_failed', reconcileIntent( 'requires_payment_method' ) )->assertOk();

        expect( InventoryReservation::query()->count() )->toBe( 0 )
            ->and( $cart->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_SELECTION )
            ->and( $cart->payment_reference )->toBe( 'pi_123' )
            ->and( $failed )->toBe( [ $cart->id ] )
            ->and( Order::query()->count() )->toBe( 0 );
    } );

    it( 'ignores outcomes for sessions no checkout holds', function (): void {
        expect( app( PaymentReconciler::class )->reconcile( 'stripe', 'pi_unknown', WebhookResult::OUTCOME_SUCCEEDED ) )->toBe( PaymentReconciler::SKIPPED );
    } );
} );

describe( 'reconcile command', function (): void {
    it( 'settles quiet sessions the provider confirmed or cancelled and leaves the rest', function (): void {
        $paid     = readyCart( $this->product );
        $unpaid   = readyCart( $this->product );
        $canceled = readyCart( $this->product );
        $fresh    = readyCart( $this->product );
        $checkout = app( CheckoutService::class );

        foreach ( [ $paid, $unpaid, $canceled, $fresh ] as $cart ) {
            $checkout->createPaymentSession( $cart );
        }

        $this->fake->confirm( $paid->refresh()->payment_reference );
        $this->fake->confirm( $fresh->refresh()->payment_reference );
        $this->fake->confirm( $canceled->refresh()->payment_reference, PaymentSession::STATUS_CANCELED );

        Cart::query()->whereKey( [ $paid->id, $unpaid->id, $canceled->id ] )->update( [ 'updated_at' => Carbon::now()->subMinutes( 20 ) ] );

        $this->artisan( 'ecommerce:reconcile-payments' )
            ->expectsOutputToContain( 'Finalized 1, released 1, left 1, errors 0.' )
            ->assertSuccessful();

        expect( $paid->refresh()->completed_order_id )->not->toBeNull()
            ->and( $unpaid->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_PENDING )
            ->and( $canceled->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_SELECTION )
            ->and( $fresh->refresh()->completed_order_id )->toBeNull();
    } );

    it( 'resumes a placed order whose payment needed a step-up', function (): void {
        $cart    = readyCart( $this->product );
        $session = app( CheckoutService::class )->createPaymentSession( $cart );
        $this->fake->confirm( $session->reference, PaymentSession::STATUS_REQUIRES_ACTION );

        $order = app( CheckoutService::class )->finalize( $cart, $session->reference )->order;
        $this->fake->confirm( $session->reference );
        Order::query()->whereKey( $order->id )->update( [ 'placed_at' => Carbon::now()->subMinutes( 30 ) ] );

        $this->artisan( 'ecommerce:reconcile-payments' )->assertSuccessful();

        expect( $order->refresh()->payment_status )->toBe( 'paid' );
    } );
} );
