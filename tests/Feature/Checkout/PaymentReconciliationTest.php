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
use Tests\Fixtures\CheckoutFakeGateway;
use Tests\Fixtures\SharedWebhookFakeGateway;

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

/**
 * A ready cart paid through `$gatewayKey` whose session the shopper
 * confirmed (or whose reference is forced to `$reference`).
 */
function reconcileSiblingCart( $product, string $gatewayKey, ?string $reference = null ): Cart
{
    $cart = readyCart( $product );
    app( CheckoutService::class )->setPaymentGateway( $cart, $gatewayKey );

    if ( null === $reference ) {
        $session = app( CheckoutService::class )->createPaymentSession( $cart->refresh() );
        app( PaymentGatewayRegistry::class )->get( $gatewayKey )->confirm( $session->reference );
    } else {
        $cart->forceFill( [ 'payment_reference' => $reference, 'checkout_state' => CheckoutState::PAYMENT_PENDING ] )->save();
    }

    return $cart->refresh();
}

describe( 'shared webhook endpoints', function (): void {
    beforeEach( function (): void {
        $this->card   = new CheckoutFakeGateway( 'fake-card' );
        $this->shared = new SharedWebhookFakeGateway( 'fake-shared', [ 'fake-shared', 'fake-card', 'fake-ghost', 42 ] );

        app( PaymentGatewayRegistry::class )->register( 'fake-card', $this->card );
        app( PaymentGatewayRegistry::class )->register( 'fake-shared', $this->shared );
    } );

    it( 'finalizes a sibling gateway\'s checkout from the shared gateway\'s webhook', function (): void {
        $cart = reconcileSiblingCart( $this->product, 'fake-card' );

        expect( $cart->payment_gateway_key )->toBe( 'fake-card' )
            ->and( app( PaymentReconciler::class )->reconcile( 'fake-shared', (string) $cart->payment_reference, WebhookResult::OUTCOME_SUCCEEDED ) )->toBe( PaymentReconciler::FINALIZED );

        $order = Order::query()->sole();

        expect( $order->payment_status )->toBe( 'paid' )
            ->and( $order->payment_gateway_key )->toBe( 'fake-card' )
            ->and( $cart->refresh()->completed_order_id )->toBe( $order->id );
    } );

    it( 'releases a sibling gateway\'s checkout when the shared gateway reports a failure', function (): void {
        $cart = reconcileSiblingCart( $this->product, 'fake-card', 'ref_failed' );

        expect( app( PaymentReconciler::class )->reconcile( 'fake-shared', 'ref_failed', WebhookResult::OUTCOME_FAILED ) )->toBe( PaymentReconciler::FAILED )
            ->and( $cart->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_SELECTION );
    } );

    it( 'resumes a sibling gateway\'s pending order from the shared gateway\'s webhook', function (): void {
        $cart     = readyCart( $this->product );
        $checkout = app( CheckoutService::class );
        $checkout->setPaymentGateway( $cart, 'fake-card' );
        $session = $checkout->createPaymentSession( $cart->refresh() );
        $this->card->confirm( $session->reference, PaymentSession::STATUS_REQUIRES_ACTION );

        $order = $checkout->finalize( $cart->refresh(), $session->reference )->order;
        $this->card->confirm( $session->reference );

        expect( $order->payment_status )->toBe( 'pending' )
            ->and( app( PaymentReconciler::class )->reconcile( 'fake-shared', $session->reference, WebhookResult::OUTCOME_SUCCEEDED ) )->toBe( PaymentReconciler::FINALIZED )
            ->and( $order->refresh()->payment_status )->toBe( 'paid' );
    } );

    it( 'only lets a gateway claim declared keys that are registered', function (): void {
        $ghost = reconcileSiblingCart( $this->product, 'fake-card', 'ref_ghost' );
        $ghost->forceFill( [ 'payment_gateway_key' => 'fake-ghost' ] )->save();

        expect( app( PaymentReconciler::class )->webhookGatewayKeys( 'fake-shared' ) )->toBe( [ 'fake-shared', 'fake-card' ] )
            ->and( app( PaymentReconciler::class )->reconcile( 'fake-shared', 'ref_ghost', WebhookResult::OUTCOME_FAILED ) )->toBe( PaymentReconciler::SKIPPED )
            ->and( $ghost->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_PENDING );
    } );

    it( 'always includes the gateway\'s own key, even when it leaves it out', function (): void {
        app( PaymentGatewayRegistry::class )->register( 'fake-forgetful', new SharedWebhookFakeGateway( 'fake-forgetful', [ 'fake-card' ] ) );

        expect( app( PaymentReconciler::class )->webhookGatewayKeys( 'fake-forgetful' ) )->toBe( [ 'fake-forgetful', 'fake-card' ] );
    } );

    it( 'leaves gateways without the contract matching only their own key', function (): void {
        $cart = reconcileSiblingCart( $this->product, 'fake-card', 'ref_own' );

        expect( app( PaymentReconciler::class )->webhookGatewayKeys( 'fake' ) )->toBe( [ 'fake' ] )
            ->and( app( PaymentReconciler::class )->webhookGatewayKeys( 'not-registered' ) )->toBe( [ 'not-registered' ] )
            ->and( app( PaymentReconciler::class )->reconcile( 'fake', 'ref_own', WebhookResult::OUTCOME_FAILED ) )->toBe( PaymentReconciler::SKIPPED )
            ->and( $cart->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_PENDING )
            ->and( app( PaymentReconciler::class )->reconcile( 'fake-card', 'ref_own', WebhookResult::OUTCOME_FAILED ) )->toBe( PaymentReconciler::FAILED );
    } );

    it( 'leaves a refunded outcome to the refund flow, for sibling keys too', function (): void {
        $cart = reconcileSiblingCart( $this->product, 'fake-card', 'ref_refunded' );

        expect( app( PaymentReconciler::class )->reconcile( 'fake-shared', 'ref_refunded', WebhookResult::OUTCOME_REFUNDED ) )->toBe( PaymentReconciler::SKIPPED )
            ->and( $cart->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_PENDING )
            ->and( Order::query()->count() )->toBe( 0 );
    } );

    it( 'settles the most recent checkout and warns when a reference matches several across the keys', function (): void {
        $older = reconcileSiblingCart( $this->product, 'fake-card', 'ref_shared' );
        $newer = reconcileSiblingCart( $this->product, 'fake-shared', 'ref_shared' );
        Cart::query()->whereKey( $older->id )->update( [ 'updated_at' => Carbon::now()->subMinutes( 10 ) ] );

        $logger = Mockery::spy();
        Illuminate\Support\Facades\Log::shouldReceive( 'channel' )->with( 'ecommerce' )->andReturn( $logger );

        expect( app( PaymentReconciler::class )->reconcile( 'fake-shared', 'ref_shared', WebhookResult::OUTCOME_FAILED ) )->toBe( PaymentReconciler::FAILED )
            ->and( $newer->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_SELECTION )
            ->and( $older->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_PENDING );

        $logger->shouldHaveReceived( 'warning' )->once()->withArgs( fn ( string $message, array $context ): bool => str_contains( $message, 'more than one checkout' ) && $newer->id === $context['id'] && 'ref_shared' === $context['reference'] && [ 'fake-shared', 'fake-card' ] === $context['gateway_keys'] );
    } );
} );
