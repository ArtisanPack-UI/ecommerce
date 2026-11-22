<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\CancelsPaymentSessions;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeClientFactory;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeGateway;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeSignatureVerifier;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

/**
 * Answers Stripe API calls from a `METHOD path` → [status, body] map and
 * records each call (with its params).
 */
function stripeCancelHttp( array $responses ): object
{
    $client = new class( $responses ) implements ClientInterface {
        /** @var array<int, string> */
        public array $calls = [];

        /** @var array<int, array<string, mixed>> */
        public array $headers = [];

        public function __construct( private readonly array $responses )
        {
        }

        public function request( $method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null ): array
        {
            $call            = strtoupper( $method ) . ' ' . parse_url( $absUrl, PHP_URL_PATH );
            $this->calls[]   = $call;
            $this->headers[] = $headers;
            $response        = $this->responses[ $call ] ?? throw new ApiConnectionException( "Unexpected call {$call}" );

            return [ json_encode( $response[1] ), $response[0], [] ];
        }
    };

    ApiRequestor::setHttpClient( $client );

    return $client;
}

function stripeCancelGateway(): StripeGateway
{
    return new StripeGateway( app( StripeClientFactory::class ), app( StripeSignatureVerifier::class ), app( 'config' ) );
}

function stripeCancelIntent( string $status ): array
{
    return [ 'id' => 'pi_123', 'object' => 'payment_intent', 'status' => $status ];
}

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.gateways.stripe.secret_key', 'sk_test_dummy' );
} );

afterEach( function (): void {
    ApiRequestor::setHttpClient( null );
} );

it( 'implements the optional session-cancelling contract', function (): void {
    expect( stripeCancelGateway() )->toBeInstanceOf( CancelsPaymentSessions::class );
} );

it( 'cancels an intent the shopper has not confirmed, with an idempotency key', function ( string $status ): void {
    $http = stripeCancelHttp( [
        'GET /v1/payment_intents/pi_123'         => [ 200, stripeCancelIntent( $status ) ],
        'POST /v1/payment_intents/pi_123/cancel' => [ 200, stripeCancelIntent( 'canceled' ) ],
    ] );

    stripeCancelGateway()->cancelPaymentSession( 'pi_123' );

    expect( $http->calls )->toBe( [ 'GET /v1/payment_intents/pi_123', 'POST /v1/payment_intents/pi_123/cancel' ] )
        ->and( implode( "\n", $http->headers[1] ) )->toContain( 'ap-ec-cancel-session-pi_123' );
} )->with( [ 'requires_payment_method', 'requires_confirmation', 'requires_action' ] );

it( 'treats an already-canceled or missing intent as a no-op', function ( array $retrieve ): void {
    $http = stripeCancelHttp( [ 'GET /v1/payment_intents/pi_123' => $retrieve ] );

    stripeCancelGateway()->cancelPaymentSession( 'pi_123' );

    expect( $http->calls )->toBe( [ 'GET /v1/payment_intents/pi_123' ] );
} )->with( [
    'canceled' => [ [ 200, stripeCancelIntent( 'canceled' ) ] ],
    'missing'  => [ [ 404, [ 'error' => [ 'type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such payment_intent' ] ] ] ],
] );

it( 'never cancels a confirmed intent', function ( string $status ): void {
    $http = stripeCancelHttp( [ 'GET /v1/payment_intents/pi_123' => [ 200, stripeCancelIntent( $status ) ] ] );

    expect( fn () => stripeCancelGateway()->cancelPaymentSession( 'pi_123' ) )->toThrow( RuntimeException::class, 'already confirmed' )
        ->and( $http->calls )->toBe( [ 'GET /v1/payment_intents/pi_123' ] );
} )->with( [ 'succeeded', 'requires_capture', 'processing' ] );

it( 'throws when Stripe does not confirm the cancellation', function (): void {
    stripeCancelHttp( [
        'GET /v1/payment_intents/pi_123'         => [ 200, stripeCancelIntent( 'requires_payment_method' ) ],
        'POST /v1/payment_intents/pi_123/cancel' => [ 200, stripeCancelIntent( 'requires_payment_method' ) ],
    ] );

    expect( fn () => stripeCancelGateway()->cancelPaymentSession( 'pi_123' ) )->toThrow( RuntimeException::class, 'did not confirm' );
} );
