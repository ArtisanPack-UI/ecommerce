<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeClientFactory;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeGateway;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeSignatureVerifier;
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\ClientInterface;

uses( RefreshDatabase::class );

/**
 * Answers Stripe API calls from a `METHOD path` → [status, body] map and
 * records each call.
 */
function fakeStripeHttp( array $responses ): object
{
    $client = new class( $responses ) implements ClientInterface {
        /** @var array<int, string> */
        public array $calls = [];

        public function __construct( private readonly array $responses )
        {
        }

        // stripe-php 17+ added `$apiMode` and `$maxNetworkRetries`; declaring
        // them optional keeps the fake compatible with every supported major.
        public function request( $method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null ): array
        {
            $call          = strtoupper( $method ) . ' ' . parse_url( $absUrl, PHP_URL_PATH );
            $this->calls[] = $call;
            $response      = $this->responses[ $call ] ?? throw new ApiConnectionException( "Unexpected call {$call}" );

            if ( $response instanceof Throwable ) {
                throw $response;
            }

            return [ json_encode( $response[1] ), $response[0], [] ];
        }
    };

    ApiRequestor::setHttpClient( $client );

    return $client;
}

function stripeVoidGateway(): StripeGateway
{
    return new StripeGateway( app( StripeClientFactory::class ), app( StripeSignatureVerifier::class ), app( 'config' ) );
}

function intentJson( string $status ): array
{
    return [ 'id' => 'pi_123', 'object' => 'payment_intent', 'status' => $status ];
}

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.gateways.stripe.secret_key', 'sk_test_dummy' );

    $this->order = Order::factory()->create( [ 'payment_gateway_key' => StripeGateway::KEY, 'payment_status' => 'pending', 'payment_reference' => 'pi_123' ] );
} );

afterEach( function (): void {
    ApiRequestor::setHttpClient( null );
} );

it( 'cancels a live authorization once Stripe confirms it', function (): void {
    $http = fakeStripeHttp( [
        'GET /v1/payment_intents/pi_123'         => [ 200, intentJson( 'requires_capture' ) ],
        'POST /v1/payment_intents/pi_123/cancel' => [ 200, intentJson( 'canceled' ) ],
    ] );

    stripeVoidGateway()->voidPendingPayment( $this->order );

    expect( $http->calls )->toBe( [ 'GET /v1/payment_intents/pi_123', 'POST /v1/payment_intents/pi_123/cancel' ] );
} );

it( 'treats an already-canceled or missing intent as a no-op', function ( array $retrieve ): void {
    $http = fakeStripeHttp( [ 'GET /v1/payment_intents/pi_123' => $retrieve ] );

    stripeVoidGateway()->voidPendingPayment( $this->order );

    expect( $http->calls )->toBe( [ 'GET /v1/payment_intents/pi_123' ] );
} )->with( [
    'canceled' => [ [ 200, intentJson( 'canceled' ) ] ],
    'missing'  => [ [ 404, [ 'error' => [ 'type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such payment_intent' ] ] ] ],
] );

it( 'refuses to report a captured payment as voided', function (): void {
    fakeStripeHttp( [ 'GET /v1/payment_intents/pi_123' => [ 200, intentJson( 'succeeded' ) ] ] );

    expect( fn () => stripeVoidGateway()->voidPendingPayment( $this->order ) )->toThrow( RuntimeException::class, 'already captured' );
} );

it( 'throws when Stripe does not confirm the cancellation', function (): void {
    fakeStripeHttp( [
        'GET /v1/payment_intents/pi_123'         => [ 200, intentJson( 'requires_capture' ) ],
        'POST /v1/payment_intents/pi_123/cancel' => [ 200, intentJson( 'requires_capture' ) ],
    ] );

    expect( fn () => stripeVoidGateway()->voidPendingPayment( $this->order ) )->toThrow( RuntimeException::class, 'did not confirm' );
} );

it( 'propagates a refused or unreachable void instead of swallowing it', function ( array $cancel, string $exception ): void {
    fakeStripeHttp( [
        'GET /v1/payment_intents/pi_123'         => [ 200, intentJson( 'requires_capture' ) ],
        'POST /v1/payment_intents/pi_123/cancel' => $cancel,
    ] );

    expect( fn () => stripeVoidGateway()->voidPendingPayment( $this->order ) )->toThrow( $exception );
} )->with( [
    'refused'     => [ [ 400, [ 'error' => [ 'type' => 'invalid_request_error', 'code' => 'payment_intent_unexpected_state', 'message' => 'Cannot cancel' ] ] ], InvalidRequestException::class ],
    'unreachable' => [ [ 500, [ 'error' => [ 'type' => 'api_error', 'message' => 'Server error' ] ] ], ApiErrorException::class ],
] );
