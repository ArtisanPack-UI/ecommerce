<?php

declare( strict_types=1 );

namespace Tests\Feature\Gateways\Stripe;

use ArtisanPackUI\Ecommerce\Exceptions\PaymentCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeClientFactory;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeGateway;
use ArtisanPackUI\Ecommerce\Gateways\Stripe\StripeSignatureVerifier;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Money\Money;
use Tests\TestCase;

final class StripeGatewayCurrencyGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The gateway MUST throw before touching Stripe when the session amount
     * does not match the order's payment currency — the engine never
     * silently converts on FX drift.
     *
     * @return void
     */
    public function test_capture_with_currency_mismatch_throws(): void
    {
        $order = Order::factory()->create( [
            'currency'            => 'USD',
            'payment_gateway_key' => StripeGateway::KEY,
        ] );

        $session = new PaymentSession(
            gatewayKey: StripeGateway::KEY,
            reference: 'pi_never_called',
            amount: Money::EUR( 1_000 ),
        );

        $this->expectException( PaymentCurrencyMismatchException::class );

        $this->gateway()->capturePayment( $order, $session );
    }

    /**
     * @return void
     */
    public function test_refund_with_currency_mismatch_throws(): void
    {
        $order = Order::factory()->create( [
            'currency'            => 'USD',
            'payment_gateway_key' => StripeGateway::KEY,
        ] );

        $this->expectException( PaymentCurrencyMismatchException::class );

        $this->gateway()->refund( $order, Money::EUR( 500 ) );
    }

    /**
     * @return void
     */
    public function test_refund_without_payment_reference_returns_failure(): void
    {
        $order = Order::factory()->create( [
            'currency'            => 'USD',
            'payment_gateway_key' => StripeGateway::KEY,
            'payment_reference'   => null,
        ] );

        $result = $this->gateway()->refund( $order, Money::USD( 1_000 ) );

        $this->assertFalse( $result->success );
        $this->assertSame( 'missing_payment_reference', $result->errorCode );
    }

    /**
     * @return void
     */
    public function test_void_without_payment_reference_is_a_no_op(): void
    {
        $order = Order::factory()->create( [
            'currency'            => 'USD',
            'payment_gateway_key' => StripeGateway::KEY,
            'payment_reference'   => null,
        ] );

        // MUST NOT throw and MUST NOT touch Stripe.
        $this->gateway()->voidPendingPayment( $order );

        $this->assertTrue( true );
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
