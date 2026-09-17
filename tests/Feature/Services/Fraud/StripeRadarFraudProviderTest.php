<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Services\Fraud\StripeRadarFraudProvider;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use Money\Money;
use Stripe\Exception\ApiConnectionException;

final class RadarStubPaymentIntentsService
{
    /** @var array<int, array{id: string, opts: array<string, mixed>}> */
    public array $calls = [];

    public function __construct( public readonly ?object $intent, public readonly ?Throwable $throw = null )
    {
    }

    public function retrieve( string $id, array $opts = [] ): object
    {
        $this->calls[] = [ 'id' => $id, 'opts' => $opts ];

        if ( null !== $this->throw ) {
            throw $this->throw;
        }

        return $this->intent;
    }
}

final class RadarStubStripeClient
{
    public function __construct( public RadarStubPaymentIntentsService $paymentIntents )
    {
    }
}

function radarBuildProvider( ?object $intent, ?Throwable $throw = null ): StripeRadarFraudProvider
{
    $service = new RadarStubPaymentIntentsService( $intent, $throw );
    $client  = new RadarStubStripeClient( $service );

    return new StripeRadarFraudProvider(
        static fn (): object => $client,
    );
}

function radarIntent( ?string $riskLevel, ?int $riskScore = null, ?string $chargeId = 'ch_123' ): object
{
    $outcome = null === $riskLevel ? null : (object) [
        'risk_level' => $riskLevel,
        'risk_score' => $riskScore,
    ];

    $charge = null === $chargeId ? null : (object) [
        'id'      => $chargeId,
        'outcome' => $outcome,
    ];

    return (object) [ 'latest_charge' => $charge ];
}

function radarFixtures(): array
{
    return [
        new Cart(),
        new Address( '1 Navy Way', 'Arlington', 'US' ),
        new PaymentSession( 'stripe', 'pi_radar_1', Money::USD( 4200 ) ),
    ];
}

it( 'approves when the session did not come from Stripe without touching Stripe', function (): void {
    // Use a resolver that would blow up if the provider tried to call Stripe.
    $provider = new StripeRadarFraudProvider(
        static function (): object {
            throw new RuntimeException( 'Radar should not touch Stripe for non-Stripe sessions.' );
        },
    );

    [ $cart, $shipping ] = radarFixtures();
    $paypalSession       = new PaymentSession( 'paypal', 'PAY-abc', Money::USD( 4200 ) );

    $decision = $provider->assess( $cart, $shipping, $paypalSession );

    expect( $decision->isApprove() )->toBeTrue();
    expect( $decision->reasons )->toBe( [ 'not_applicable' ] );
} );

it( 'blocks when Radar reports risk_level=highest', function (): void {
    $provider = radarBuildProvider( radarIntent( 'highest', 88 ) );

    [ $cart, $shipping, $session ] = radarFixtures();

    $decision = $provider->assess( $cart, $shipping, $session );

    expect( $decision->isBlock() )->toBeTrue();
    expect( $decision->score )->toBe( 88 );
    expect( $decision->reasons )->toBe( [ 'stripe_radar', 'risk_level:highest' ] );
    expect( $decision->providerReference )->toBe( 'ch_123' );
} );

it( 'challenges when Radar reports risk_level=elevated', function (): void {
    $provider = radarBuildProvider( radarIntent( 'elevated', 55 ) );

    [ $cart, $shipping, $session ] = radarFixtures();

    $decision = $provider->assess( $cart, $shipping, $session );

    expect( $decision->isChallenge() )->toBeTrue();
    expect( $decision->score )->toBe( 55 );
    expect( $decision->reasons )->toBe( [ 'stripe_radar', 'risk_level:elevated' ] );
} );

it( 'clamps an out-of-range Radar risk_score into [0, 100] instead of throwing', function (): void {
    $provider = radarBuildProvider( radarIntent( 'highest', 250 ) );

    [ $cart, $shipping, $session ] = radarFixtures();

    $decision = $provider->assess( $cart, $shipping, $session );

    expect( $decision->isBlock() )->toBeTrue();
    expect( $decision->score )->toBe( 100 );
} );

it( 'approves when Radar reports risk_level=normal', function (): void {
    $provider = radarBuildProvider( radarIntent( 'normal', 3 ) );

    [ $cart, $shipping, $session ] = radarFixtures();

    $decision = $provider->assess( $cart, $shipping, $session );

    expect( $decision->isApprove() )->toBeTrue();
    expect( $decision->score )->toBe( 3 );
    expect( $decision->reasons )->toBe( [ 'stripe_radar', 'risk_level:normal' ] );
} );

it( 'approves with no_charge reason when the PaymentIntent has no latest_charge yet', function (): void {
    $provider = radarBuildProvider( radarIntent( null, null, null ) );

    [ $cart, $shipping, $session ] = radarFixtures();

    $decision = $provider->assess( $cart, $shipping, $session );

    expect( $decision->isApprove() )->toBeTrue();
    expect( $decision->reasons )->toBe( [ 'no_charge' ] );
} );

it( 'approves with provider_error reason when Stripe throws', function (): void {
    $provider = radarBuildProvider( null, new ApiConnectionException( 'network down' ) );

    [ $cart, $shipping, $session ] = radarFixtures();

    $decision = $provider->assess( $cart, $shipping, $session );

    expect( $decision->isApprove() )->toBeTrue();
    expect( $decision->reasons )->toBe( [ 'provider_error' ] );
} );

it( 'reports its own key as stripe-radar', function (): void {
    $provider = new StripeRadarFraudProvider( static fn (): object => new stdClass() );
    expect( $provider->key() )->toBe( 'stripe-radar' );
} );
