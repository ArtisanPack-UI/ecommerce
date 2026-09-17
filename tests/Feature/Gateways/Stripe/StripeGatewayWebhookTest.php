<?php

declare( strict_types=1 );

namespace Tests\Feature\Gateways\Stripe;

use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeClientFactory;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeGateway;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeSignatureVerifier;
use Illuminate\Http\Request;
use Stripe\WebhookSignature;
use Tests\TestCase;

final class StripeGatewayWebhookTest extends TestCase
{
    /**
     * @return void
     */
    public function test_verified_webhook_returns_verified_result_with_event_type_and_id(): void
    {
        $secret  = 'whsec_test_secret';
        $payload = json_encode( [
            'id'          => 'evt_test_1',
            'type'        => 'payment_intent.succeeded',
            'object'      => 'event',
            'api_version' => '2024-06-20',
            'data'        => [ 'object' => [ 'id' => 'pi_test_1', 'object' => 'payment_intent' ] ],
        ], JSON_THROW_ON_ERROR );

        config()->set( 'artisanpack.ecommerce.gateways.stripe.webhook_secret', $secret );

        $request = $this->signedRequest( $payload, $secret );

        $result = $this->gateway()->handleWebhook( $request );

        $this->assertTrue( $result->verified, 'errorCode=' . ( $result->errorCode ?? 'null' ) );
        $this->assertSame( 'payment_intent.succeeded', $result->eventType );
        $this->assertSame( 'evt_test_1', $result->eventId );
    }

    /**
     * @return void
     */
    public function test_tampered_payload_fails_verification(): void
    {
        $secret  = 'whsec_test_secret';
        $payload = json_encode( [
            'id'          => 'evt_test_2',
            'type'        => 'payment_intent.succeeded',
            'object'      => 'event',
            'api_version' => '2024-06-20',
            'data'        => [ 'object' => [ 'id' => 'pi_test_2' ] ],
        ], JSON_THROW_ON_ERROR );

        config()->set( 'artisanpack.ecommerce.gateways.stripe.webhook_secret', $secret );

        // Sign the original, then tamper with the body — signature is now stale.
        $request  = $this->signedRequest( $payload, $secret );
        $tampered = Request::create( '/webhooks/stripe', 'POST', [], [], [], [], $payload . '{"tampered":true}' );
        $tampered->headers->set( StripeGateway::SIGNATURE_HEADER, (string) $request->headers->get( StripeGateway::SIGNATURE_HEADER ) );
        $tampered->headers->set( 'Content-Type', 'application/json' );

        $result = $this->gateway()->handleWebhook( $tampered );

        $this->assertFalse( $result->verified );
        $this->assertSame( 'signature_mismatch', $result->errorCode );
    }

    /**
     * @return void
     */
    public function test_missing_signature_header_fails_verification(): void
    {
        config()->set( 'artisanpack.ecommerce.gateways.stripe.webhook_secret', 'whsec_test_secret' );

        $request = Request::create( '/webhooks/stripe', 'POST', [], [], [], [], '{}' );
        $request->headers->set( 'Content-Type', 'application/json' );

        $result = $this->gateway()->handleWebhook( $request );

        $this->assertFalse( $result->verified );
        $this->assertSame( 'signature_missing', $result->errorCode );
    }

    /**
     * @return void
     */
    public function test_missing_webhook_secret_fails_verification(): void
    {
        config()->set( 'artisanpack.ecommerce.gateways.stripe.webhook_secret', '' );

        $request = Request::create( '/webhooks/stripe', 'POST', [], [], [], [], '{}' );
        $request->headers->set( StripeGateway::SIGNATURE_HEADER, 't=1,v1=deadbeef' );

        $result = $this->gateway()->handleWebhook( $request );

        $this->assertFalse( $result->verified );
        $this->assertSame( 'webhook_secret_not_configured', $result->errorCode );
    }

    /**
     * Builds a request with a valid Stripe signature over `$payload`.
     *
     * @param  string  $payload  Raw JSON body.
     * @param  string  $secret   Endpoint secret.
     *
     * @return Request
     */
    private function signedRequest( string $payload, string $secret ): Request
    {
        $timestamp = time();
        $signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
        $header    = sprintf( 't=%d,v1=%s', $timestamp, $signature );

        $request = Request::create( '/webhooks/stripe', 'POST', [], [], [], [], $payload );
        $request->headers->set( StripeGateway::SIGNATURE_HEADER, $header );
        $request->headers->set( 'Content-Type', 'application/json' );

        // Confidence check: Stripe's own signature helper agrees.
        WebhookSignature::verifyHeader( $payload, $header, $secret, StripeSignatureVerifier::DEFAULT_TOLERANCE );

        return $request;
    }

    /**
     * @return StripeGateway
     */
    private function gateway(): StripeGateway
    {
        return new StripeGateway(
            $this->app->make( StripeClientFactory::class ),
            $this->app->make( StripeSignatureVerifier::class ),
            $this->app->make( 'config' ),
        );
    }
}
