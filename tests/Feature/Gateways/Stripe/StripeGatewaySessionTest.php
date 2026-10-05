<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeClientFactory;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeGateway;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeSignatureVerifier;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Support\ClientPaymentConfig;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Money\Money;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

uses( RefreshDatabase::class );

/**
 * Answers Stripe calls from a `METHOD path` → [status, body] map, recording
 * each call with its params and headers.
 */
function stripeSessionHttp( array $responses ): object
{
    $client = new class( $responses ) implements ClientInterface {
        /** @var array<int, array{call: string, params: array<string, mixed>, headers: array<int, string>}> */
        public array $requests = [];

        public function __construct( private readonly array $responses )
        {
        }

        public function request( $method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null ): array
        {
            $call             = strtoupper( $method ) . ' ' . parse_url( $absUrl, PHP_URL_PATH );
            $this->requests[] = [ 'call' => $call, 'params' => (array) $params, 'headers' => (array) $headers ];
            $response         = $this->responses[ $call ] ?? throw new ApiConnectionException( "Unexpected call {$call}" );

            return [ json_encode( $response[1] ), $response[0], [] ];
        }
    };

    ApiRequestor::setHttpClient( $client );

    return $client;
}

function stripeSessionGateway(): StripeGateway
{
    return new StripeGateway( app( StripeClientFactory::class ), app( StripeSignatureVerifier::class ), app( 'config' ) );
}

function stripeIntent( string $status, array $extra = [] ): array
{
    return array_merge( [ 'id' => 'pi_9', 'object' => 'payment_intent', 'status' => $status, 'amount' => 2_500, 'currency' => 'usd', 'client_secret' => 'pi_9_secret_x' ], $extra );
}

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.gateways.stripe.secret_key', 'sk_test_dummy' );
    config()->set( 'artisanpack.ecommerce.gateways.stripe.publishable_key', 'pk_test_dummy' );
} );

afterEach( function (): void {
    ApiRequestor::setHttpClient( null );
} );

it( 'retrieves a PaymentIntent as a session with a normalized status', function ( string $stripe, ?string $normalized ): void {
    stripeSessionHttp( [ 'GET /v1/payment_intents/pi_9' => [ 200, stripeIntent( $stripe ) ] ] );

    $session = stripeSessionGateway()->retrievePaymentSession( 'pi_9' );

    expect( $session->reference )->toBe( 'pi_9' )
        ->and( $session->amount->equals( Money::USD( 2_500 ) ) )->toBeTrue()
        ->and( $session->status )->toBe( $normalized );
} )->with( [
    'needs a method'  => [ 'requires_payment_method', PaymentSession::STATUS_REQUIRES_PAYMENT_METHOD ],
    'needs 3DS'       => [ 'requires_action', PaymentSession::STATUS_REQUIRES_ACTION ],
    'authorized'      => [ 'requires_capture', PaymentSession::STATUS_AUTHORIZED ],
    'captured'        => [ 'succeeded', PaymentSession::STATUS_SUCCEEDED ],
    'settling'        => [ 'processing', PaymentSession::STATUS_PROCESSING ],
    'canceled'        => [ 'canceled', PaymentSession::STATUS_CANCELED ],
] );

it( 'reports the amount Stripe actually received on capture', function (): void {
    stripeSessionHttp( [
        'GET /v1/payment_intents/pi_9'          => [ 200, stripeIntent( 'requires_capture' ) ],
        'POST /v1/payment_intents/pi_9/capture' => [ 200, stripeIntent( 'succeeded', [ 'amount_received' => 2_000, 'latest_charge' => 'ch_9' ] ) ],
    ] );
    $order = Order::factory()->create( [ 'currency' => 'USD', 'total_amount' => 2_500 ] );

    $result = stripeSessionGateway()->capturePayment( $order, new PaymentSession( 'stripe', 'pi_9', Money::USD( 2_500 ) ) );

    expect( $result->success )->toBeTrue()
        ->and( (int) $result->amount->getAmount() )->toBe( 2_000 )
        ->and( $result->gatewayReference )->toBe( 'ch_9' );
} );

it( 'sends the engine\'s idempotency key and refund id with a refund', function (): void {
    $http  = stripeSessionHttp( [ 'POST /v1/refunds' => [ 200, [ 'id' => 're_9', 'object' => 'refund', 'status' => 'succeeded' ] ] ] );
    $order = Order::factory()->create( [ 'currency' => 'USD', 'payment_reference' => 'pi_9' ] );

    $result = stripeSessionGateway()->refund( $order, Money::USD( 500 ), null, [ 'idempotency_key' => 'ap-ec-refund-42', 'refund_id' => 42 ] );

    expect( $result->success )->toBeTrue()
        ->and( $http->requests[0]['params']['metadata']['ap_ec_refund_id'] )->toBe( '42' )
        ->and( $http->requests[0]['headers'] )->toContain( 'Idempotency-Key: ap-ec-refund-42' );
} );

it( 'describes the Payment Element step for storefronts', function (): void {
    $cart    = Cart::factory()->create();
    $session = new PaymentSession( 'stripe', 'pi_9', Money::USD( 2_500 ), 'pi_9_secret_x' );

    $config = ClientPaymentConfig::for( stripeSessionGateway(), $cart, $session );

    expect( $config )->toMatchArray( [
        'driver'          => 'stripe-payment-element',
        'flow'            => 'embedded',
        'publishable_key' => 'pk_test_dummy',
        'client_secret'   => 'pi_9_secret_x',
        'gateway'         => 'stripe',
    ] )->and( $config )->not->toHaveKey( 'secret_key' );
} );

it( 'lets a host adjust the client config', function (): void {
    addFilter( 'ap.ecommerce.payment.clientConfig', static fn ( ?array $config ): ?array => [ ...$config, 'options' => [ 'theme' => 'night' ] ] );

    $config = ClientPaymentConfig::for( stripeSessionGateway(), Cart::factory()->create(), new PaymentSession( 'stripe', 'pi_9', Money::USD( 1 ), 'secret' ) );

    expect( $config['options'] )->toBe( [ 'theme' => 'night' ] );
} );
