<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\FraudProvider;
use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Events\FraudBlocked;
use ArtisanPackUI\Ecommerce\Events\FraudChallenged;
use ArtisanPackUI\Ecommerce\Events\PaymentFailed;
use ArtisanPackUI\Ecommerce\Events\PaymentSucceeded;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Registries\FraudProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\FraudDecision;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentFinalization;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Money\Money;

uses( RefreshDatabase::class );

final class OrchestratorFakeGateway implements PaymentGateway
{
    /** @var array<int, string> */
    public array $calls = [];

    public bool $shouldThrowOnCapture = false;

    public ?PaymentResult $captureResult = null;

    public function __construct( public string $keyName = 'orch-fake' )
    {
    }

    public function key(): string
    {
        return $this->keyName;
    }

    public function label(): string
    {
        return 'Orchestrator fake';
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function supportsPartialRefunds(): bool
    {
        return true;
    }

    public function supportsSavedInstruments(): bool
    {
        return false;
    }

    public function createPaymentSession( Cart $cart, array $context = [] ): PaymentSession
    {
        $this->calls[] = 'createPaymentSession';

        return new PaymentSession(
            gatewayKey: $this->keyName,
            reference: 'ps_' . $cart->getKey(),
            amount: Money::USD( 5_000 ),
            clientSecret: 'cs_step_up_token',
        );
    }

    public function capturePayment( Order $order, PaymentSession $session ): PaymentResult
    {
        $this->calls[] = 'capturePayment';

        if ( $this->shouldThrowOnCapture ) {
            throw new RuntimeException( 'gateway timed out' );
        }

        return $this->captureResult ?? PaymentResult::success( $session->amount, 'ch_captured' );
    }

    public function voidPendingPayment( Order $order ): void
    {
        $this->calls[] = 'voidPendingPayment';
    }

    public function refund( Order $order, Money $amount, ?string $reason = null ): RefundResult
    {
        return RefundResult::success( $amount, 're_noop' );
    }

    public function handleWebhook( Request $request ): WebhookResult
    {
        return WebhookResult::unverified( 'not_implemented' );
    }
}

final class OrchestratorFakeFraudProvider implements FraudProvider
{
    /** @var array<int, array{cart_id:?int, country:string}> */
    public array $calls = [];

    public function __construct(
        public FraudDecision $decision,
        public string $keyName = 'orch-fake-fraud',
    ) {
    }

    public function key(): string
    {
        return $this->keyName;
    }

    public function label(): string
    {
        return 'Orchestrator fake fraud';
    }

    public function assess( Cart $cart, Address $shipping, PaymentSession $session ): FraudDecision
    {
        $this->calls[] = [
            'cart_id' => $cart->getKey(),
            'country' => $shipping->countryCode,
        ];

        return $this->decision;
    }
}

beforeEach( function (): void {
    $this->gateway = new OrchestratorFakeGateway();
    /** @var PaymentGatewayRegistry $gateways */
    $gateways = app( PaymentGatewayRegistry::class );
    $gateways->register( $this->gateway->key(), $this->gateway );

    config()->set( 'artisanpack.ecommerce.fraud.provider', 'orch-fake-fraud' );
} );

function orchRegisterFraud( FraudDecision $decision ): OrchestratorFakeFraudProvider
{
    $provider = new OrchestratorFakeFraudProvider( $decision );

    /** @var FraudProviderRegistry $registry */
    $registry = app( FraudProviderRegistry::class );
    $registry->register( $provider->key(), $provider );

    return $provider;
}

function orchMakeOrderAndCart(): array
{
    $cart  = Cart::factory()->create();
    $order = Order::factory()->create( [
        'payment_gateway_key' => 'orch-fake',
        'system_status'       => 'pending',
        'payment_status'      => 'pending',
        'total_amount'        => 5_000,
        'currency'            => 'USD',
    ] );

    return [ $order, $cart ];
}

function orchShipping(): Address
{
    return new Address(
        address1: '1 Test Way',
        city: 'Testville',
        countryCode: 'US',
        postalCode: '10001',
    );
}

it( 'approves → captures → dispatches PaymentSucceeded, marks order paid', function (): void {
    Event::fake( [ PaymentSucceeded::class, PaymentFailed::class, FraudBlocked::class, FraudChallenged::class ] );
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchMakeOrderAndCart();

    /** @var PaymentOrchestrator $orch */
    $orch = app( PaymentOrchestrator::class );

    $result = $orch->finalize( $order, $cart, orchShipping() );

    expect( $result )->toBeInstanceOf( PaymentFinalization::class );
    expect( $result->isCaptured() )->toBeTrue();
    expect( $result->payment?->success )->toBeTrue();

    $fresh = $order->fresh();
    expect( $fresh->payment_status )->toBe( 'paid' );
    expect( $fresh->system_status )->toBe( 'processing' );
    expect( $fresh->payment_reference )->toBe( 'ch_captured' );

    expect( $this->gateway->calls )->toEqual( [ 'createPaymentSession', 'capturePayment' ] );

    $timelineTypes = OrderTimelineEntry::query()->where( 'order_id', $order->id )->pluck( 'event_type' )->all();
    expect( $timelineTypes )->toContain( 'payment.authorized', 'payment.captured' );

    Event::assertDispatched( PaymentSucceeded::class );
    Event::assertNotDispatched( PaymentFailed::class );
    Event::assertNotDispatched( FraudBlocked::class );
    Event::assertNotDispatched( FraudChallenged::class );
} );

it( 'blocks → voids auth, marks order failed, records reasons on meta, dispatches events', function (): void {
    Event::fake( [ FraudBlocked::class, PaymentFailed::class, PaymentSucceeded::class ] );
    orchRegisterFraud( FraudDecision::block( 92, [ 'high_risk_address', 'unusual_device' ], 'radar_evt_1' ) );
    [ $order, $cart ] = orchMakeOrderAndCart();

    /** @var PaymentOrchestrator $orch */
    $orch = app( PaymentOrchestrator::class );

    $result = $orch->finalize( $order, $cart, orchShipping() );

    expect( $result->isBlocked() )->toBeTrue();
    expect( $result->fraud?->verdict )->toBe( FraudDecision::VERDICT_BLOCK );

    $fresh = $order->fresh();
    expect( $fresh->system_status )->toBe( 'failed' );
    expect( $fresh->payment_status )->toBe( 'failed' );
    expect( $fresh->meta[ 'fraud_decision' ][ 'verdict' ] )->toBe( 'block' );
    expect( $fresh->meta[ 'fraud_decision' ][ 'reasons' ] )->toBe( [ 'high_risk_address', 'unusual_device' ] );
    expect( $fresh->meta[ 'fraud_decision' ][ 'provider_reference' ] )->toBe( 'radar_evt_1' );

    expect( $this->gateway->calls )->toEqual( [ 'createPaymentSession', 'voidPendingPayment' ] );

    Event::assertDispatched( FraudBlocked::class );
    Event::assertDispatched( PaymentFailed::class );
    Event::assertNotDispatched( PaymentSucceeded::class );
} );

it( 'challenges → leaves order pending, dispatches FraudChallenged with step-up token, no capture', function (): void {
    Event::fake( [ FraudChallenged::class, PaymentSucceeded::class, PaymentFailed::class ] );
    orchRegisterFraud( FraudDecision::challenge( 60, [ '3ds_required' ] ) );
    [ $order, $cart ] = orchMakeOrderAndCart();

    /** @var PaymentOrchestrator $orch */
    $orch = app( PaymentOrchestrator::class );

    $result = $orch->finalize( $order, $cart, orchShipping() );

    expect( $result->isChallenged() )->toBeTrue();
    expect( $result->stepUpToken )->toBe( 'cs_step_up_token' );

    $fresh = $order->fresh();
    expect( $fresh->payment_status )->toBe( 'pending' );
    expect( $fresh->system_status )->toBe( 'pending' );

    // Only authorize should have happened — no capture, no void.
    expect( $this->gateway->calls )->toEqual( [ 'createPaymentSession' ] );

    $timelineTypes = OrderTimelineEntry::query()->where( 'order_id', $order->id )->pluck( 'event_type' )->all();
    expect( $timelineTypes )->toContain( 'payment.authorized', 'payment.fraud_challenged' );

    Event::assertDispatched(
        FraudChallenged::class,
        fn ( FraudChallenged $e ) => $e->cart->id === $cart->id && 'cs_step_up_token' === $e->session->clientSecret,
    );
    Event::assertNotDispatched( PaymentSucceeded::class );
    Event::assertNotDispatched( PaymentFailed::class );
} );

it( 'records a capture failure and dispatches PaymentFailed when the gateway throws', function (): void {
    Event::fake( [ PaymentFailed::class, PaymentSucceeded::class ] );
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ]                    = orchMakeOrderAndCart();
    $this->gateway->shouldThrowOnCapture = true;

    /** @var PaymentOrchestrator $orch */
    $orch = app( PaymentOrchestrator::class );

    $result = $orch->finalize( $order, $cart, orchShipping() );

    expect( $result->isFailed() )->toBeTrue();
    expect( $order->fresh()->payment_status )->toBe( 'pending' );

    $timelineTypes = OrderTimelineEntry::query()->where( 'order_id', $order->id )->pluck( 'event_type' )->all();
    expect( $timelineTypes )->toContain( 'payment.capture_failed' );

    Event::assertDispatched(
        PaymentFailed::class,
        fn ( PaymentFailed $e ) => 'gateway timed out' === $e->reason->getMessage(),
    );
    Event::assertNotDispatched( PaymentSucceeded::class );
} );

it( 'records a capture failure when the gateway returns success=false', function (): void {
    Event::fake( [ PaymentFailed::class, PaymentSucceeded::class ] );
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ]             = orchMakeOrderAndCart();
    $this->gateway->captureResult = PaymentResult::terminalFailure(
        Money::USD( 5_000 ),
        'card_declined',
        'The card was declined.',
    );

    /** @var PaymentOrchestrator $orch */
    $orch = app( PaymentOrchestrator::class );

    $result = $orch->finalize( $order, $cart, orchShipping() );

    expect( $result->isFailed() )->toBeTrue();
    expect( $result->payment?->success )->toBeFalse();
    Event::assertDispatched( PaymentFailed::class );
    Event::assertNotDispatched( PaymentSucceeded::class );
} );

it( 'is idempotent: repeat finalize on a paid order returns captured without re-charging', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchMakeOrderAndCart();

    /** @var PaymentOrchestrator $orch */
    $orch = app( PaymentOrchestrator::class );

    $first = $orch->finalize( $order, $cart, orchShipping() );
    expect( $first->isCaptured() )->toBeTrue();

    $callCount = count( $this->gateway->calls );

    Event::fake( [ PaymentSucceeded::class ] );

    $second = $orch->finalize( $order->fresh(), $cart, orchShipping() );

    expect( $second->isCaptured() )->toBeTrue();
    expect( $this->gateway->calls )->toHaveCount( $callCount ); // no additional gateway calls
    Event::assertNotDispatched( PaymentSucceeded::class );
} );

it( 'is idempotent: repeat finalize on a fraud-blocked order returns blocked without re-voiding', function (): void {
    orchRegisterFraud( FraudDecision::block( 90, [ 'ip_blocklist' ] ) );
    [ $order, $cart ] = orchMakeOrderAndCart();

    /** @var PaymentOrchestrator $orch */
    $orch = app( PaymentOrchestrator::class );

    $first = $orch->finalize( $order, $cart, orchShipping() );
    expect( $first->isBlocked() )->toBeTrue();

    $callCount = count( $this->gateway->calls );

    Event::fake( [ FraudBlocked::class ] );

    $second = $orch->finalize( $order->fresh(), $cart, orchShipping() );

    expect( $second->isBlocked() )->toBeTrue();
    expect( $second->fraud?->reasons )->toBe( [ 'ip_blocklist' ] );
    expect( $this->gateway->calls )->toHaveCount( $callCount ); // no additional gateway calls
    Event::assertNotDispatched( FraudBlocked::class );
} );

it( 'throws when the order has no payment_gateway_key', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    $cart  = Cart::factory()->create();
    $order = Order::factory()->create( [ 'payment_gateway_key' => null ] );

    /** @var PaymentOrchestrator $orch */
    $orch = app( PaymentOrchestrator::class );

    $orch->finalize( $order, $cart, orchShipping() );
} )->throws( RuntimeException::class, 'has no payment_gateway_key' );

it( 'throws when the configured fraud provider is not registered', function (): void {
    config()->set( 'artisanpack.ecommerce.fraud.provider', 'not-a-real-provider' );
    [ $order, $cart ] = orchMakeOrderAndCart();

    /** @var PaymentOrchestrator $orch */
    $orch = app( PaymentOrchestrator::class );

    $orch->finalize( $order, $cart, orchShipping() );
} )->throws( RuntimeException::class, 'not-a-real-provider' );

it( 'runs every provider in chain mode and blocks when any provider blocks', function (): void {
    Event::fake( [ FraudBlocked::class, PaymentSucceeded::class, PaymentFailed::class ] );

    $approve = new OrchestratorFakeFraudProvider( FraudDecision::approve(), 'chain-approve' );
    $block   = new OrchestratorFakeFraudProvider( FraudDecision::block( 90, [ 'unusual_device' ] ), 'chain-block' );

    /** @var FraudProviderRegistry $registry */
    $registry = app( FraudProviderRegistry::class );
    $registry->register( $approve->key(), $approve );
    $registry->register( $block->key(), $block );

    config()->set( 'artisanpack.ecommerce.fraud.provider', 'chain-approve, chain-block' );

    [ $order, $cart ] = orchMakeOrderAndCart();

    /** @var PaymentOrchestrator $orch */
    $orch = app( PaymentOrchestrator::class );

    $result = $orch->finalize( $order, $cart, orchShipping() );

    expect( $result->isBlocked() )->toBeTrue();
    expect( $approve->calls )->toHaveCount( 1 );
    expect( $block->calls )->toHaveCount( 1 );

    Event::assertDispatched( FraudBlocked::class );
} );

it( 'throws when a comma-listed provider in chain mode is not registered', function (): void {
    $approve = new OrchestratorFakeFraudProvider( FraudDecision::approve(), 'chain-registered' );

    /** @var FraudProviderRegistry $registry */
    $registry = app( FraudProviderRegistry::class );
    $registry->register( $approve->key(), $approve );

    config()->set( 'artisanpack.ecommerce.fraud.provider', 'chain-registered,chain-missing' );

    [ $order, $cart ] = orchMakeOrderAndCart();

    /** @var PaymentOrchestrator $orch */
    $orch = app( PaymentOrchestrator::class );

    $orch->finalize( $order, $cart, orchShipping() );
} )->throws( RuntimeException::class, 'chain-missing' );

it( 'runs every provider in chain mode and approves when all providers approve', function (): void {
    Event::fake( [ PaymentSucceeded::class, PaymentFailed::class, FraudBlocked::class, FraudChallenged::class ] );

    $one = new OrchestratorFakeFraudProvider( FraudDecision::approve( 1 ), 'chain-one' );
    $two = new OrchestratorFakeFraudProvider( FraudDecision::approve( 2 ), 'chain-two' );

    /** @var FraudProviderRegistry $registry */
    $registry = app( FraudProviderRegistry::class );
    $registry->register( $one->key(), $one );
    $registry->register( $two->key(), $two );

    config()->set( 'artisanpack.ecommerce.fraud.provider', 'chain-one,chain-two' );

    [ $order, $cart ] = orchMakeOrderAndCart();

    /** @var PaymentOrchestrator $orch */
    $orch = app( PaymentOrchestrator::class );

    $result = $orch->finalize( $order, $cart, orchShipping() );

    expect( $result->isCaptured() )->toBeTrue();
    expect( $one->calls )->toHaveCount( 1 );
    expect( $two->calls )->toHaveCount( 1 );

    Event::assertDispatched( PaymentSucceeded::class );
    Event::assertNotDispatched( FraudBlocked::class );
} );
