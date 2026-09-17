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

        $this->assertDatabaseHas( 'inbound_webhook_deliveries', [
            'provider'        => 'paypal',
            'verified'        => false,
            'error_code'      => 'gateway_not_registered',
            'response_status' => 404,
        ] );
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

        $this->assertDatabaseHas( 'inbound_webhook_deliveries', [
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
        $this->assertDatabaseHas( 'inbound_webhook_deliveries', [
            'provider'        => 'fake',
            'verified'        => false,
            'error_code'      => 'signature_mismatch',
            'response_status' => 400,
        ] );
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
    private function registerFakeGateway( string $key, bool $verified = true ): void
    {
        /** @var PaymentGatewayRegistry $registry */
        $registry = $this->app->make( PaymentGatewayRegistry::class );

        $registry->register( $key, new class( $key, $verified ) implements PaymentGateway {
            public function __construct( private readonly string $registryKey, private readonly bool $verified )
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

            public function capturePayment( Order $order, PaymentSession $session ): PaymentResult
            {
                throw new LogicException( 'not used' );
            }

            public function voidPendingPayment( Order $order ): void
            {
            }

            public function refund( Order $order, Money $amount, ?string $reason = null ): RefundResult
            {
                throw new LogicException( 'not used' );
            }

            public function handleWebhook( Request $request ): WebhookResult
            {
                if ( ! $this->verified ) {
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
