<?php

declare( strict_types=1 );

namespace Tests\Feature\Gateways\Stripe;

use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StripeWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return void
     */
    public function test_it_accepts_a_signed_webhook_and_returns_200(): void
    {
        $secret  = 'whsec_test_secret';
        $payload = json_encode( [
            'id'          => 'evt_ctrl_1',
            'type'        => 'payment_intent.succeeded',
            'object'      => 'event',
            'api_version' => '2024-06-20',
            'data'        => [ 'object' => [ 'id' => 'pi_ctrl_1', 'object' => 'payment_intent' ] ],
        ], JSON_THROW_ON_ERROR );

        $timestamp = time();
        $signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

        $response = $this->call(
            method: 'POST',
            uri: '/ecommerce/webhooks/stripe',
            server: [
                'HTTP_' . str_replace( '-', '_', strtoupper( StripeGateway::SIGNATURE_HEADER ) ) => sprintf( 't=%d,v1=%s', $timestamp, $signature ),
                'CONTENT_TYPE'                                                                   => 'application/json',
            ],
            content: $payload,
        );

        $response->assertStatus( 200 );
        $response->assertJsonPath( 'received', true );
        $response->assertJsonPath( 'event_id', 'evt_ctrl_1' );
    }

    /**
     * @return void
     */
    public function test_it_rejects_an_unsigned_webhook_with_400(): void
    {
        $response = $this->postJson( '/ecommerce/webhooks/stripe', [ 'id' => 'evt_missing' ] );

        $response->assertStatus( 400 );
        $response->assertJsonPath( 'code', 'signature_missing' );
    }

    /**
     * Stripe redelivers events at-least-once. A second delivery of the same
     * event id MUST return 200 without dispatching the downstream
     * `ap.ecommerce.gateway.stripe.webhook_received` action a second time.
     *
     * @return void
     */
    public function test_a_duplicate_delivery_returns_200_without_dispatching_twice(): void
    {
        $secret  = 'whsec_test_secret';
        $payload = json_encode( [
            'id'          => 'evt_dup_1',
            'type'        => 'payment_intent.succeeded',
            'object'      => 'event',
            'api_version' => '2024-06-20',
            'data'        => [ 'object' => [ 'id' => 'pi_dup_1', 'object' => 'payment_intent' ] ],
        ], JSON_THROW_ON_ERROR );

        $timestamp = time();
        $signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
        $server    = [
            'HTTP_' . str_replace( '-', '_', strtoupper( StripeGateway::SIGNATURE_HEADER ) ) => sprintf( 't=%d,v1=%s', $timestamp, $signature ),
            'CONTENT_TYPE'                                                                   => 'application/json',
        ];

        $dispatches = 0;
        addAction( 'ap.ecommerce.gateway.stripe.webhook_received', function () use ( &$dispatches ): void {
            $dispatches++;
        } );

        $first = $this->call( 'POST', '/ecommerce/webhooks/stripe', server: $server, content: $payload );
        $first->assertStatus( 200 );
        $first->assertJsonMissing( [ 'duplicate' => true ] );

        $second = $this->call( 'POST', '/ecommerce/webhooks/stripe', server: $server, content: $payload );
        $second->assertStatus( 200 );
        $second->assertJsonPath( 'duplicate', true );

        $this->assertSame( 1, $dispatches, 'Downstream action must fire exactly once across duplicate deliveries.' );
    }

    protected function defineEnvironment( $app ): void
    {
        parent::defineEnvironment( $app );

        $app[ 'config' ]->set( 'artisanpack.ecommerce.gateways.stripe.enabled', true );
        $app[ 'config' ]->set( 'artisanpack.ecommerce.gateways.stripe.secret_key', 'sk_test_dummy' );
        $app[ 'config' ]->set( 'artisanpack.ecommerce.gateways.stripe.webhook_secret', 'whsec_test_secret' );
    }
}
