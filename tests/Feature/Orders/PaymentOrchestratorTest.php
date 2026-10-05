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

    public bool $shouldThrowOnVoid = false;

    public bool $shouldThrowOnCapture = false;

    public ?PaymentResult $captureResult = null;

    /**
     * Provider-side sessions by reference, with their current status.
     *
     * @var array<string, PaymentSession>
     */
    public array $sessions = [];

    public function __construct( public string $keyName = 'orch-fake' )
    {
    }

    /** Seeds a session at the "provider", as a client-side confirm would leave it. */
    public function seedSession( string $reference, int $amount, ?string $status, string $currency = 'USD' ): PaymentSession
    {
        return $this->sessions[ $reference ] = new PaymentSession(
            gatewayKey: $this->keyName,
            reference: $reference,
            amount: new Money( $amount, new \Money\Currency( $currency ) ),
            clientSecret: 'cs_' . $reference,
            status: $status,
        );
    }

    public function retrievePaymentSession( string $reference ): PaymentSession
    {
        $this->calls[] = 'retrievePaymentSession';

        return $this->sessions[ $reference ] ?? throw new RuntimeException( "No session {$reference}." );
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

        return $this->sessions[ 'ps_' . $cart->getKey() ] = new PaymentSession(
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

        $result = $this->captureResult ?? PaymentResult::success( $session->amount, 'ch_captured' );

        if ( $result->success && isset( $this->sessions[ $session->reference ] ) ) {
            $this->seedSession( $session->reference, (int) $session->amount->getAmount(), PaymentSession::STATUS_SUCCEEDED, $session->amount->getCurrency()->getCode() );
        }

        return $result;
    }

    public function voidPendingPayment( Order $order ): void
    {
        $this->calls[] = 'voidPendingPayment';

        if ( $this->shouldThrowOnVoid ) {
            throw new RuntimeException( 'void refused' );
        }
    }

    public function refund( Order $order, Money $amount, ?string $reason = null, array $context = [] ): RefundResult
    {
        $this->calls[] = 'refund';

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
    // The session reference stays (refunds and voids use it); the charge id goes to meta.
    expect( $fresh->payment_reference )->toBe( 'ps_' . $cart->id );
    expect( $fresh->meta['payment']['capture_reference'] )->toBe( 'ch_captured' );

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

it( 'fires payment.charging before capture, then payment.succeeded and order.paid with the paid order', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchMakeOrderAndCart();
    $fired            = [];

    addAction( 'ap.ecommerce.payment.charging', function ( PaymentGateway $gateway, Money $amount, Order $charging ) use ( &$fired ): void {
        $fired[] = [ 'charging', $gateway->key(), (int) $amount->getAmount(), $charging->id, $this->gateway->calls ];
    } );
    addAction( 'ap.ecommerce.payment.succeeded', function () use ( &$fired ): void {
        $fired[] = [ 'succeeded' ];
    } );
    addAction( 'ap.ecommerce.order.paid', function ( Order $paid, PaymentResult $result ) use ( &$fired ): void {
        $fired[] = [ 'paid', $paid->id, $paid->payment_status, $result->gatewayReference ];
    } );

    app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() );

    expect( $fired )->toBe( [
        [ 'charging', 'orch-fake', 5_000, $order->id, [ 'createPaymentSession' ] ],
        [ 'succeeded' ],
        [ 'paid', $order->id, 'paid', 'ch_captured' ],
    ] );
} );

it( 'fires payment.failed with the thrown exception when the gateway throws, and never order.paid', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ]                    = orchMakeOrderAndCart();
    $this->gateway->shouldThrowOnCapture = true;
    $failed                              = null;
    $paid                                = false;

    addAction( 'ap.ecommerce.payment.failed', function ( PaymentGateway $gateway, Throwable $reason, Order $failedOrder ) use ( &$failed ): void {
        $failed = [ $gateway->key(), $reason->getMessage(), $failedOrder->id ];
    } );
    addAction( 'ap.ecommerce.order.paid', function () use ( &$paid ): void {
        $paid = true;
    } );

    app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() );

    expect( $failed )->toBe( [ 'orch-fake', 'gateway timed out', $order->id ] );
    expect( $paid )->toBeFalse();
} );

it( 'fires payment.failed with a decline reason when the gateway returns success=false', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ]             = orchMakeOrderAndCart();
    $this->gateway->captureResult = PaymentResult::terminalFailure( Money::USD( 5_000 ), 'card_declined', 'The card was declined.' );
    $failed                       = null;

    addAction( 'ap.ecommerce.payment.failed', function ( PaymentGateway $gateway, Throwable $reason, Order $failedOrder ) use ( &$failed ): void {
        $failed = [ $gateway->key(), $reason->getMessage(), $failedOrder->id ];
    } );

    app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() );

    expect( $failed[0] )->toBe( 'orch-fake' );
    expect( $failed[1] )->toContain( 'The card was declined.' );
    expect( $failed[2] )->toBe( $order->id );
} );

it( 'does not fire order.paid again on a repeat finalize of a paid order', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchMakeOrderAndCart();
    $paid             = 0;

    addAction( 'ap.ecommerce.order.paid', function () use ( &$paid ): void {
        $paid++;
    } );

    $orch = app( PaymentOrchestrator::class );
    $orch->finalize( $order, $cart, orchShipping() );
    $orch->finalize( $order->fresh(), $cart, orchShipping() );

    expect( $paid )->toBe( 1 );
} );

it( 'lists every registered gateway as available for a cart by default', function (): void {
    $available = app( PaymentGatewayRegistry::class )->availableFor( Cart::factory()->create() );

    expect( array_keys( $available ) )->toBe( [ 'orch-fake' ] );
    expect( $available['orch-fake'] )->toBe( $this->gateway );
} );

it( 'lets payment.availableGateways hide a gateway for a cart', function (): void {
    $other = new OrchestratorFakeGateway( 'orch-other' );
    app( PaymentGatewayRegistry::class )->register( $other->key(), $other );
    $cart     = Cart::factory()->create();
    $received = null;

    addFilter( 'ap.ecommerce.payment.availableGateways', function ( array $gateways, Cart $filtered ) use ( &$received ): array {
        $received = [ array_keys( $gateways ), $filtered->id ];
        unset( $gateways['orch-fake'] );

        return $gateways;
    } );

    $available = app( PaymentGatewayRegistry::class )->availableFor( $cart );

    expect( $received )->toBe( [ [ 'orch-fake', 'orch-other' ], $cart->id ] );
    expect( array_keys( $available ) )->toBe( [ 'orch-other' ] );
} );

it( 'drops payment.availableGateways entries that are not registered under their key', function (): void {
    addFilter( 'ap.ecommerce.payment.availableGateways', function ( array $gateways ): array {
        $gateways['injected'] = new OrchestratorFakeGateway( 'injected' );
        $gateways['bogus']    = 'not a gateway';

        return $gateways;
    } );

    $available = app( PaymentGatewayRegistry::class )->availableFor( Cart::factory()->create() );

    expect( array_keys( $available ) )->toBe( [ 'orch-fake' ] );
} );

it( 'still returns captured and dispatches PaymentSucceeded when a post-capture listener throws', function (): void {
    Event::fake( [ PaymentSucceeded::class ] );
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchMakeOrderAndCart();
    $paidRan          = false;

    addAction( 'ap.ecommerce.payment.succeeded', function (): void {
        throw new RuntimeException( 'crm is down' );
    } );
    addAction( 'ap.ecommerce.order.paid', function () use ( &$paidRan ): void {
        $paidRan = true;

        throw new RuntimeException( 'erp is down' );
    } );

    $result = app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() );

    expect( $result->isCaptured() )->toBeTrue();
    expect( $paidRan )->toBeTrue();
    expect( $order->fresh()->payment_status )->toBe( 'paid' );
    Event::assertDispatched( PaymentSucceeded::class );
} );

it( 'still blocks the order when the fraud-path void is refused', function (): void {
    Event::fake( [ FraudBlocked::class, PaymentFailed::class, PaymentSucceeded::class ] );
    orchRegisterFraud( FraudDecision::block( 92, [ 'high_risk_address' ] ) );
    [ $order, $cart ]                 = orchMakeOrderAndCart();
    $this->gateway->shouldThrowOnVoid = true;

    $result = app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() );

    expect( $result->isBlocked() )->toBeTrue()
        ->and( $order->fresh()->system_status )->toBe( 'failed' )
        ->and( $this->gateway->calls )->toEqual( [ 'createPaymentSession', 'voidPendingPayment' ] );

    Event::assertDispatched( FraudBlocked::class );
} );

/*
 * Audit C1/C2/C3/C7, D4 — sessions confirmed client-side, claims, amounts,
 * status machine, and stock.
 */

function orchPinnedOrder( OrchestratorFakeGateway $gateway, ?string $status = PaymentSession::STATUS_AUTHORIZED, int $amount = 5_000, array $overrides = [] ): array
{
    [ $order, $cart ] = orchMakeOrderAndCart();
    $order->update( array_merge( [ 'payment_reference' => 'pi_confirmed_' . $order->id ], $overrides ) );
    $gateway->seedSession( (string) $order->fresh()->payment_reference, $amount, $status );

    return [ $order->fresh(), $cart ];
}

it( 'captures the session the shopper confirmed instead of creating a new one', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchPinnedOrder( $this->gateway );

    $result = app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() );

    expect( $result->isCaptured() )->toBeTrue()
        ->and( $this->gateway->calls )->toBe( [ 'retrievePaymentSession', 'capturePayment' ] )
        ->and( $order->fresh()->payment_reference )->toBe( 'pi_confirmed_' . $order->id );
} );

it( 'creates one session and captures once across two finalizes of the same order', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchMakeOrderAndCart();
    $orch             = app( PaymentOrchestrator::class );

    $first  = $orch->finalize( Order::query()->find( $order->id ), $cart, orchShipping() );
    $second = $orch->finalize( Order::query()->find( $order->id ), $cart, orchShipping() );

    expect( $first->isCaptured() )->toBeTrue()
        ->and( $second->isCaptured() )->toBeTrue()
        ->and( array_count_values( $this->gateway->calls ) )->toBe( [ 'createPaymentSession' => 1, 'capturePayment' => 1 ] );
} );

it( 'refuses a finalize while another attempt holds the order', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchPinnedOrder( $this->gateway, PaymentSession::STATUS_AUTHORIZED, 5_000, [
        'payment_status' => 'processing',
        'meta'           => [ 'finalize_attempt' => [ 'id' => 'other', 'at' => now()->toIso8601String() ] ],
    ] );

    expect( fn () => app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() ) )
        ->toThrow( ArtisanPackUI\Ecommerce\Exceptions\PaymentInProgressException::class );

    expect( $this->gateway->calls )->toBe( [] );
} );

it( 'resumes an attempt whose claim expired after a crash', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchPinnedOrder( $this->gateway, PaymentSession::STATUS_AUTHORIZED, 5_000, [
        'payment_status' => 'processing',
        'meta'           => [ 'finalize_attempt' => [ 'id' => 'crashed', 'at' => now()->subMinutes( 10 )->toIso8601String() ] ],
    ] );

    expect( app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() )->isCaptured() )->toBeTrue();
} );

it( 'never charges a cancelled or voided order', function ( array $state ): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchPinnedOrder( $this->gateway, PaymentSession::STATUS_AUTHORIZED, 5_000, $state );

    expect( fn () => app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() ) )
        ->toThrow( ArtisanPackUI\Ecommerce\Exceptions\PaymentNotAllowedException::class );

    expect( $this->gateway->calls )->toBe( [] );
} )->with( [
    'cancelled' => [ [ 'system_status' => 'cancelled', 'payment_status' => 'voided' ] ],
    'voided'    => [ [ 'payment_status' => 'voided' ] ],
] );

it( 'does not capture again when a retry follows a capture whose database write failed', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchPinnedOrder( $this->gateway );

    // The capture lands at the provider, then the transition write throws.
    $fail = static function (): void {
        throw new RuntimeException( 'database went away' );
    };
    Order::saving( static function ( Order $saving ) use ( &$fail ): void {
        if ( 'paid' === $saving->payment_status && null !== $fail ) {
            $fail();
        }
    } );

    expect( fn () => app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() ) )->toThrow( RuntimeException::class, 'database went away' );
    expect( $order->fresh()->payment_status )->toBe( 'pending' );

    $fail = null;

    $retry = app( PaymentOrchestrator::class )->finalize( $order->fresh(), $cart, orchShipping() );

    expect( $retry->isCaptured() )->toBeTrue()
        ->and( array_count_values( $this->gateway->calls )['capturePayment'] )->toBe( 1 )
        ->and( $order->fresh()->payment_status )->toBe( 'paid' );
} );

it( 'refunds a capture that lands after the order was cancelled', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchPinnedOrder( $this->gateway );

    // The order is cancelled while the capture is in flight.
    addAction( 'ap.ecommerce.payment.charging', static function ( $gateway, $amount, Order $charging ): void {
        Order::query()->whereKey( $charging->id )->update( [ 'system_status' => 'cancelled' ] );
    } );

    $result = app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() );

    expect( $result->isFailed() )->toBeTrue()
        ->and( $this->gateway->calls )->toContain( 'capturePayment', 'refund' )
        ->and( $order->fresh()->system_status )->toBe( 'cancelled' )
        ->and( $order->fresh()->payment_status )->toBe( 'refunded' );
} );

it( 'returns the step-up token when the confirmed session needs 3DS, then captures once it is completed', function (): void {
    $fraud            = orchRegisterFraud( FraudDecision::challenge( 60, [ 'elevated' ] ) );
    [ $order, $cart ] = orchPinnedOrder( $this->gateway, PaymentSession::STATUS_REQUIRES_ACTION );
    $orch             = app( PaymentOrchestrator::class );

    $challenged = $orch->finalize( $order, $cart, orchShipping() );

    expect( $challenged->isChallenged() )->toBeTrue()
        ->and( $challenged->stepUpToken )->toBe( 'cs_pi_confirmed_' . $order->id )
        ->and( $order->fresh()->payment_status )->toBe( 'pending' )
        ->and( $fraud->calls )->toBe( [] );

    // The shopper completes 3DS; the same session is now authorized.
    $this->gateway->seedSession( 'pi_confirmed_' . $order->id, 5_000, PaymentSession::STATUS_AUTHORIZED );

    $resumed = $orch->resume( $order->fresh() );

    expect( $resumed->isCaptured() )->toBeTrue()
        ->and( $resumed->fraud->reasons )->toContain( 'step_up_completed' )
        ->and( array_count_values( $this->gateway->calls ) )->toBe( [ 'retrievePaymentSession' => 2, 'capturePayment' => 1 ] );
} );

it( 'holds a confirmed payment for review on a fraud challenge until an admin accepts it', function (): void {
    orchRegisterFraud( FraudDecision::challenge( 60, [ 'elevated' ] ) );
    [ $order, $cart ] = orchPinnedOrder( $this->gateway );
    $orch             = app( PaymentOrchestrator::class );

    $held = $orch->finalize( $order, $cart, orchShipping() );

    expect( $held->isChallenged() )->toBeTrue()
        ->and( $held->stepUpToken )->toBeNull()
        ->and( $order->fresh()->meta['fraud_decision']['verdict'] )->toBe( 'challenge' );

    // A shopper retrying can't get past it.
    expect( $orch->resume( $order->fresh() )->isChallenged() )->toBeTrue();
    expect( $this->gateway->calls )->not->toContain( 'capturePayment' );

    expect( $orch->resume( $order->fresh(), [ PaymentOrchestrator::ACCEPT_CHALLENGE => true ] )->isCaptured() )->toBeTrue();
} );

it( 'refuses a session whose amount differs from the order total and voids it', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchPinnedOrder( $this->gateway, PaymentSession::STATUS_AUTHORIZED, 4_999 );

    expect( fn () => app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() ) )
        ->toThrow( ArtisanPackUI\Ecommerce\Exceptions\PaymentAmountMismatchException::class );

    expect( $this->gateway->calls )->toContain( 'voidPendingPayment' )->not->toContain( 'capturePayment' )
        ->and( $order->fresh()->payment_status )->toBe( 'pending' )
        ->and( $order->fresh()->payment_reference )->toBeNull();
} );

it( 'never marks paid a capture that moved a different amount', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ]             = orchPinnedOrder( $this->gateway );
    $this->gateway->captureResult = PaymentResult::success( Money::USD( 4_000 ), 'ch_short' );

    $result = app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() );

    expect( $result->isFailed() )->toBeTrue()
        ->and( $order->fresh()->payment_status )->toBe( 'pending' )
        ->and( $order->fresh()->meta['payment_review']['reason'] )->toBe( 'captured_amount_mismatch' );
} );

it( 'moves the order through the status machine on capture and on a fraud block', function (): void {
    Event::fake( [ ArtisanPackUI\Ecommerce\Events\OrderStatusChanged::class ] );
    $fraud            = orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchPinnedOrder( $this->gateway );

    app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() );

    Event::assertDispatched( ArtisanPackUI\Ecommerce\Events\OrderStatusChanged::class, fn ( $event ): bool => 'pending' === $event->from && 'processing' === $event->to );
    expect( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'order.status_changed' )->exists() )->toBeTrue();

    $fraud->decision           = FraudDecision::block( 99 );
    [ $blocked, $blockedCart ] = orchPinnedOrder( $this->gateway );

    app( PaymentOrchestrator::class )->finalize( $blocked, $blockedCart, orchShipping() );

    Event::assertDispatched( ArtisanPackUI\Ecommerce\Events\OrderStatusChanged::class, fn ( $event ): bool => $event->order->id === $blocked->id && 'failed' === $event->to );
} );

it( 'commits the order\'s stock reservations when the payment is captured', function (): void {
    orchRegisterFraud( FraudDecision::approve() );
    [ $order, $cart ] = orchPinnedOrder( $this->gateway );
    $item             = ArtisanPackUI\Ecommerce\Models\InventoryItem::factory()->create( [ 'quantity_on_hand' => 10 ] );
    app( ArtisanPackUI\Ecommerce\Services\InventoryService::class )->reserve( $item, $order, 3, null, false );

    expect( $item->fresh()->quantity_reserved )->toBe( 3 );

    app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() );

    expect( $item->fresh()->quantity_on_hand )->toBe( 7 )
        ->and( $item->fresh()->quantity_reserved )->toBe( 0 )
        ->and( ArtisanPackUI\Ecommerce\Models\InventoryReservation::query()->count() )->toBe( 0 );
} );

it( 'refunds instead of voiding when a fraud block lands on an already captured payment', function (): void {
    orchRegisterFraud( FraudDecision::block( 99 ) );
    [ $order, $cart ] = orchPinnedOrder( $this->gateway, PaymentSession::STATUS_SUCCEEDED );

    expect( app( PaymentOrchestrator::class )->finalize( $order, $cart, orchShipping() )->isBlocked() )->toBeTrue()
        ->and( $this->gateway->calls )->toContain( 'refund' )->not->toContain( 'voidPendingPayment' );
} );
