<?php

declare( strict_types=1 );

namespace Tests\Feature\Http;

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\InboundWebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use LogicException;
use Money\Money;
use RuntimeException;
use Tests\TestCase;

final class WebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return void
     */
    public function test_it_returns_404_when_provider_is_not_registered(): void
    {
        $response = $this->postJson(
            '/ecommerce/webhooks/paypal',
            [ 'id' => 'evt_unknown' ],
        );

        $response->assertNotFound();
        $response->assertJsonPath( 'code', 'gateway_not_registered' );

        // Unknown providers are never stored (audit G1).
        $this->assertDatabaseCount( 'ecommerce_inbound_webhook_deliveries', 0 );
    }

    /**
     * @return void
     */
    public function test_made_up_provider_names_write_no_rows(): void
    {
        foreach ( [ 'bogus-1', 'bogus-2', 'bogus-3' ] as $provider ) {
            $this->postJson( '/ecommerce/webhooks/' . $provider, [ 'blob' => str_repeat( 'x', 10_000 ) ] )->assertNotFound();
        }

        $this->assertDatabaseCount( 'ecommerce_inbound_webhook_deliveries', 0 );
    }

    /**
     * @return void
     */
    public function test_an_unverified_delivery_keeps_the_hash_and_size_but_not_the_payload(): void
    {
        $this->registerFakeGateway( 'fake', verified: false );

        $body = json_encode( [ 'blob' => str_repeat( 'y', 5_000 ) ], JSON_THROW_ON_ERROR );

        $this->call( 'POST', '/ecommerce/webhooks/fake', [], [], [], [ 'CONTENT_TYPE' => 'application/json' ], $body )->assertStatus( 400 );

        $row = InboundWebhookDelivery::query()->sole();

        $this->assertSame( hash( 'sha256', $body ), $row->payload_hash );
        $this->assertSame( strlen( $body ), $row->payload_size );
        $this->assertTrue( $row->payload_truncated );
        $this->assertSame( InboundWebhookDelivery::UNVERIFIED_PAYLOAD_BYTES, strlen( $row->payload ) );
        $this->assertNull( $row->parsed );
    }

    /**
     * @return void
     */
    public function test_an_oversized_body_is_refused_before_anything_is_stored(): void
    {
        $this->registerFakeGateway( 'fake' );
        config()->set( 'artisanpack.ecommerce.webhooks.inbound_max_bytes', 2_048 );

        $this->postJson( '/ecommerce/webhooks/fake', [ 'id' => 'evt_big', 'blob' => str_repeat( 'z', 4_096 ) ] )
            ->assertStatus( 413 )
            ->assertJsonPath( 'code', 'payload_too_large' );

        $this->assertDatabaseCount( 'ecommerce_inbound_webhook_deliveries', 0 );
        $this->assertDatabaseCount( 'ecommerce_idempotency_records', 0 );
    }

    /**
     * @return void
     */
    public function test_junk_from_one_ip_does_not_starve_a_verified_delivery_from_another(): void
    {
        $this->registerFakeGateway( 'fake', verified: null );

        for ( $i = 0; $i < 1_000; $i++ ) {
            $this->withServerVariables( [ 'REMOTE_ADDR' => '203.0.113.7' ] )->postJson( '/ecommerce/webhooks/fake', [ 'id' => 'junk' ] );
        }

        $this->withServerVariables( [ 'REMOTE_ADDR' => '203.0.113.7' ] )->postJson( '/ecommerce/webhooks/fake', [ 'id' => 'junk' ] )->assertStatus( 429 );

        $this->withServerVariables( [ 'REMOTE_ADDR' => '198.51.100.20' ] )
            ->postJson( '/ecommerce/webhooks/fake', [ 'id' => 'evt_real', 'type' => 'payment.captured' ], [ 'X-Fake-Signature' => 'ok' ] )
            ->assertOk();

        // Only the first 120 junk requests got past the per-IP limit.
        $this->assertSame( 120, InboundWebhookDelivery::query()->where( 'verified', false )->count() );
    }

    /**
     * @return void
     */
    public function test_verified_deliveries_count_against_the_provider_allowance(): void
    {
        $this->registerFakeGateway( 'fake' );
        config()->set( 'artisanpack.ecommerce.rate_limits.webhook.inbound.per_provider', 2 );

        $this->postJson( '/ecommerce/webhooks/fake', [ 'id' => 'evt_1' ] )->assertOk();
        $this->postJson( '/ecommerce/webhooks/fake', [ 'id' => 'evt_2' ] )->assertOk();
        $this->postJson( '/ecommerce/webhooks/fake', [ 'id' => 'evt_3' ] )->assertStatus( 429 )->assertHeader( 'Retry-After' );
    }

    /**
     * @return void
     */
    public function test_it_dispatches_generic_and_provider_hooks_on_verified_delivery(): void
    {
        $this->registerFakeGateway( 'fake' );

        $genericCalls  = 0;
        $providerCalls = 0;
        addAction( 'ap.ecommerce.webhook_received', function ( string $provider ) use ( &$genericCalls ): void {
            if ( 'fake' === $provider ) {
                $genericCalls++;
            }
        } );
        addAction( 'ap.ecommerce.gateway.fake.webhook_received', function () use ( &$providerCalls ): void {
            $providerCalls++;
        } );

        $response = $this->postJson(
            '/ecommerce/webhooks/fake',
            [ 'id' => 'evt_fake_1', 'type' => 'payment.captured' ],
        );

        $response->assertOk();
        $response->assertJsonPath( 'received', true );
        $response->assertJsonPath( 'event_id', 'evt_fake_1' );
        $response->assertJsonMissing( [ 'duplicate' => true ] );

        $this->assertSame( 1, $genericCalls );
        $this->assertSame( 1, $providerCalls );

        $this->assertDatabaseHas( 'ecommerce_inbound_webhook_deliveries', [
            'provider'        => 'fake',
            'event_id'        => 'evt_fake_1',
            'event_type'      => 'payment.captured',
            'verified'        => true,
            'duplicate'       => false,
            'response_status' => 200,
        ] );
    }

    /**
     * @return void
     */
    public function test_a_replayed_event_is_ledgered_but_does_not_fire_the_hook_twice(): void
    {
        $this->registerFakeGateway( 'fake' );

        $calls = 0;
        addAction( 'ap.ecommerce.gateway.fake.webhook_received', function () use ( &$calls ): void {
            $calls++;
        } );

        $payload = [ 'id' => 'evt_replay', 'type' => 'payment.captured' ];

        $first = $this->postJson( '/ecommerce/webhooks/fake', $payload );
        $first->assertOk();
        $first->assertJsonMissing( [ 'duplicate' => true ] );

        $second = $this->postJson( '/ecommerce/webhooks/fake', $payload );
        $second->assertOk();
        $second->assertJsonPath( 'duplicate', true );

        $this->assertSame( 1, $calls );
        $this->assertSame( 2, InboundWebhookDelivery::query()->where( 'provider', 'fake' )->count() );
        $this->assertSame( 1, InboundWebhookDelivery::query()->where( 'provider', 'fake' )->where( 'duplicate', true )->count() );
    }

    /**
     * @return void
     */
    public function test_an_unverified_delivery_returns_400_and_never_dispatches(): void
    {
        $this->registerFakeGateway( 'fake', verified: false );

        $dispatched = false;
        addAction( 'ap.ecommerce.gateway.fake.webhook_received', function () use ( &$dispatched ): void {
            $dispatched = true;
        } );

        $response = $this->postJson( '/ecommerce/webhooks/fake', [ 'id' => 'evt_bad' ] );

        $response->assertStatus( 400 );
        $response->assertJsonPath( 'code', 'signature_mismatch' );

        $this->assertFalse( $dispatched );
        $this->assertDatabaseHas( 'ecommerce_inbound_webhook_deliveries', [
            'provider'        => 'fake',
            'verified'        => false,
            'error_code'      => 'signature_mismatch',
            'response_status' => 400,
        ] );
    }

    /**
     * @return void
     */
    public function test_a_failed_dispatch_releases_the_claim_so_the_provider_retry_reaches_every_hook(): void
    {
        $this->registerFakeGateway( 'fake' );

        $failNext = true;
        $received = 0;
        addAction( 'ap.ecommerce.gateway.fake.webhook_received', function () use ( &$failNext ): void {
            if ( $failNext ) {
                $failNext = false;

                throw new RuntimeException( 'listener crashed' );
            }
        } );
        addAction( 'ap.ecommerce.payment.webhookReceived', function () use ( &$received ): void {
            $received++;
        } );

        $payload = [ 'id' => 'evt_retry', 'type' => 'payment.captured' ];

        $this->withoutExceptionHandling();

        try {
            $this->postJson( '/ecommerce/webhooks/fake', $payload );
            $this->fail( 'The listener failure should surface so the provider retries.' );
        } catch ( RuntimeException $e ) {
            $this->assertSame( 'listener crashed', $e->getMessage() );
        }

        $this->assertSame( 0, $received );
        $this->assertDatabaseMissing( 'ecommerce_idempotency_records', [ 'idempotency_key' => 'evt_retry' ] );

        $this->postJson( '/ecommerce/webhooks/fake', $payload )->assertOk()->assertJsonMissing( [ 'duplicate' => true ] );
        $this->postJson( '/ecommerce/webhooks/fake', $payload )->assertOk()->assertJsonPath( 'duplicate', true );

        $this->assertSame( 1, $received );
    }

    /**
     * @return void
     */
    public function test_it_fires_payment_webhook_received_with_the_payload_and_provider(): void
    {
        $this->registerFakeGateway( 'fake' );

        $calls = [];
        addAction( 'ap.ecommerce.payment.webhookReceived', function ( array $payload, string $provider ) use ( &$calls ): void {
            $calls[] = [ $payload, $provider ];
        } );

        $payload = [ 'id' => 'evt_spec_1', 'type' => 'payment.captured' ];

        $this->postJson( '/ecommerce/webhooks/fake', $payload )->assertOk();
        $this->postJson( '/ecommerce/webhooks/fake', $payload )->assertOk();

        $this->assertSame( [ [ $payload, 'fake' ] ], $calls );
    }

    /**
     * @return void
     */
    public function test_an_unverified_delivery_never_fires_payment_webhook_received(): void
    {
        $this->registerFakeGateway( 'fake', verified: false );

        $fired = false;
        addAction( 'ap.ecommerce.payment.webhookReceived', function () use ( &$fired ): void {
            $fired = true;
        } );

        $this->postJson( '/ecommerce/webhooks/fake', [ 'id' => 'evt_bad' ] )->assertStatus( 400 );

        $this->assertFalse( $fired );
    }

    /**
     * Registers an in-memory fake gateway under `$key`. When `$verified`
     * is `true` the gateway returns a verified {@see WebhookResult}
     * echoing the request body's `id` / `type`; otherwise a
     * `signature_mismatch` rejection.
     *
     * @param  string  $key
     * @param  bool    $verified
     *
     * @return void
     */
    private function registerFakeGateway( string $key, ?bool $verified = true ): void
    {
        /** @var PaymentGatewayRegistry $registry */
        $registry = $this->app->make( PaymentGatewayRegistry::class );

        $registry->register( $key, new class( $key, $verified ) implements PaymentGateway {
            /**
             * `$verified` null: verified when `X-Fake-Signature: ok` is sent.
             */
            public function __construct( private readonly string $registryKey, private readonly ?bool $verified )
            {
            }

            public function key(): string
            {
                return $this->registryKey;
            }

            public function label(): string
            {
                return 'Fake gateway';
            }

            public function supportsRefunds(): bool
            {
                return false;
            }

            public function supportsPartialRefunds(): bool
            {
                return false;
            }

            public function supportsSavedInstruments(): bool
            {
                return false;
            }

            public function createPaymentSession( Cart $cart, array $context = [] ): PaymentSession
            {
                throw new LogicException( 'not used' );
            }

            public function retrievePaymentSession( string $reference ): PaymentSession
            {
                throw new LogicException( 'not used' );
            }

            public function capturePayment( Order $order, PaymentSession $session ): PaymentResult
            {
                throw new LogicException( 'not used' );
            }

            public function voidPendingPayment( Order $order ): void
            {
            }

            public function refund( Order $order, Money $amount, ?string $reason = null, array $context = [] ): RefundResult
            {
                throw new LogicException( 'not used' );
            }

            public function handleWebhook( Request $request ): WebhookResult
            {
                $verified = $this->verified ?? 'ok' === $request->headers->get( 'X-Fake-Signature' );

                if ( ! $verified ) {
                    return WebhookResult::unverified();
                }

                $body = (array) $request->json()->all();

                return WebhookResult::verified(
                    (string) ( $body[ 'type' ] ?? 'unknown' ),
                    (string) ( $body[ 'id' ] ?? 'evt_unknown' ),
                    $body,
                );
            }
        } );
    }
}
