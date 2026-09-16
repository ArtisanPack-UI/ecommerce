<?php

/**
 * PaymentGatewayContractTest.
 *
 * Abstract test class satellite packages extend to prove their
 * {@see \ArtisanPackUI\Ecommerce\Contracts\PaymentGateway} implementation
 * honors the four invariants the engine relies on:
 *
 * 1. **Refunds sum.** Multiple partial refunds against the same order MUST
 *    each report their own amount, and the sum of returned refund amounts
 *    MUST equal the total refunded — no rounding drift, no silent capping.
 * 2. **Webhook signature verification.** A tampered / unsigned inbound
 *    webhook MUST come back as {@see \ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult}
 *    with `$verified = false`; a well-signed one MUST come back verified
 *    with an event id present.
 * 3. **Retryable / terminal capture failures.** {@see \ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult}
 *    MUST distinguish declined-card (terminal) from provider-brownout
 *    (retryable) so the caller can retry safely or fail closed.
 * 4. **Currency mismatch throws.** `capturePayment()` and `refund()` MUST
 *    throw {@see \ArtisanPackUI\Ecommerce\Exceptions\PaymentCurrencyMismatchException}
 *    when the money passed in is not in the order's payment currency —
 *    the engine never silently converts on FX drift.
 *
 * Engine spec §4.2, parent plan §15.2.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Exceptions\PaymentCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Money\Money;
use Orchestra\Testbench\TestCase;

/**
 * Contract test for {@see PaymentGateway} implementations.
 *
 * Satellites extend this class and provide the four fixture hooks
 * ({@see self::gateway()}, {@see self::makeOrder()}, {@see self::signedWebhookRequest()},
 * {@see self::unsignedWebhookRequest()}) plus the two capture-outcome hooks
 * ({@see self::captureTerminalDeclineResult()}, {@see self::captureRetryableResult()}).
 * Satellites whose gateway does not support refunds override
 * {@see self::gatewaySupportsRefunds()} to `false` and the refund cases
 * are skipped.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class PaymentGatewayContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Multiple partial refunds against the same order MUST each report their
     * own amount, and their sum MUST equal the total refunded — no rounding
     * drift, no silent capping.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_partial_refunds_amounts_sum_to_the_total_refunded(): void
    {
        if ( ! $this->gatewaySupportsRefunds() ) {
            $this->markTestSkipped( 'Gateway does not support refunds.' );
        }

        $gateway = $this->gateway();
        $order   = $this->makeOrder( currency: 'USD', capturedAmount: 10_000 );

        $first  = $gateway->refund( $order, Money::USD( 3_000 ) );
        $second = $gateway->refund( $order, Money::USD( 2_500 ) );
        $third  = $gateway->refund( $order, Money::USD( 4_500 ) );

        $this->assertTrue( $first->success, 'First partial refund must succeed.' );
        $this->assertTrue( $second->success, 'Second partial refund must succeed.' );
        $this->assertTrue( $third->success, 'Third partial refund must succeed.' );

        $sum = $first->amount->add( $second->amount )->add( $third->amount );

        $this->assertTrue(
            $sum->equals( Money::USD( 10_000 ) ),
            'Sum of returned refund amounts must equal the total refunded (10000), got: ' . $sum->getAmount(),
        );
    }

    /**
     * A well-signed webhook MUST verify; a tampered / unsigned webhook MUST
     * NOT verify — the controller layer reads `$verified` alone to decide
     * whether to dispatch downstream events.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_handle_webhook_verifies_signed_requests_and_rejects_unsigned_ones(): void
    {
        $gateway = $this->gateway();

        $verified = $gateway->handleWebhook( $this->signedWebhookRequest() );
        $this->assertTrue(
            $verified->verified,
            'Signed webhook must verify (got errorCode: ' . ( $verified->errorCode ?? 'null' ) . ').',
        );
        $this->assertNotNull( $verified->eventId, 'Verified webhook must carry a provider event id for idempotent replay.' );

        $unverified = $gateway->handleWebhook( $this->unsignedWebhookRequest() );
        $this->assertFalse(
            $unverified->verified,
            'Unsigned webhook must NOT verify — spoofed requests would otherwise trigger downstream events.',
        );
        $this->assertNotNull( $unverified->errorCode, 'Unverified webhook must carry an errorCode.' );
    }

    /**
     * A terminal capture failure (declined card, insufficient funds) MUST
     * NOT be retryable; the caller uses this signal to surface the error
     * and let the customer choose another payment method.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_terminal_capture_failure_is_not_retryable(): void
    {
        $result = $this->captureTerminalDeclineResult();

        $this->assertFalse( $result->success, 'Terminal failure must have success=false.' );
        $this->assertFalse(
            $result->retryable,
            'A terminal capture failure MUST NOT be flagged retryable — the caller would retry a declined card and burn provider rate-limit budget.',
        );
        $this->assertNotNull( $result->errorCode, 'Terminal failure must carry an errorCode.' );
    }

    /**
     * A retryable capture failure (rate limit, network blip, provider
     * brownout) MUST be flagged retryable so the caller may safely retry.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_retryable_capture_failure_is_retryable(): void
    {
        $result = $this->captureRetryableResult();

        $this->assertFalse( $result->success, 'Retryable failure must still have success=false.' );
        $this->assertTrue(
            $result->retryable,
            'A retryable capture failure MUST be flagged retryable so the caller can back off and retry.',
        );
        $this->assertNotNull( $result->errorCode, 'Retryable failure must carry an errorCode.' );
    }

    /**
     * Refund calls whose amount is in a different currency from the order's
     * payment currency MUST throw {@see PaymentCurrencyMismatchException} —
     * the engine never silently converts on FX drift.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_refund_with_currency_mismatch_throws(): void
    {
        if ( ! $this->gatewaySupportsRefunds() ) {
            $this->markTestSkipped( 'Gateway does not support refunds.' );
        }

        $gateway = $this->gateway();
        $order   = $this->makeOrder( currency: 'USD', capturedAmount: 10_000 );

        $this->expectException( PaymentCurrencyMismatchException::class );

        $gateway->refund( $order, Money::EUR( 1_000 ) );
    }

    /**
     * Capture calls whose session amount is in a different currency from
     * the order's payment currency MUST throw
     * {@see PaymentCurrencyMismatchException}.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_capture_with_currency_mismatch_throws(): void
    {
        $gateway = $this->gateway();
        $order   = $this->makeOrder( currency: 'USD', capturedAmount: 10_000 );

        $session = new PaymentSession(
            gatewayKey: $gateway->key(),
            reference: 'session-in-eur',
            amount: Money::EUR( 10_000 ),
        );

        $this->expectException( PaymentCurrencyMismatchException::class );

        $gateway->capturePayment( $order, $session );
    }

    /**
     * The gateway under test.
     *
     * @since 1.0.0
     *
     * @return PaymentGateway
     */
    abstract protected function gateway(): PaymentGateway;

    /**
     * Builds a persisted order routed through the gateway under test in the
     * given payment currency, with the given captured amount.
     *
     * @since 1.0.0
     *
     * @param  string  $currency        ISO 4217 payment currency (e.g. `USD`).
     * @param  int     $capturedAmount  Amount captured for the order, in the currency's minor unit.
     *
     * @return Order
     */
    abstract protected function makeOrder( string $currency = 'USD', int $capturedAmount = 10_000 ): Order;

    /**
     * Returns an inbound request the gateway under test SHOULD accept as
     * signed. Satellites typically build this by signing a well-known
     * payload with the same shared secret their gateway is configured
     * against.
     *
     * @since 1.0.0
     *
     * @return Request
     */
    abstract protected function signedWebhookRequest(): Request;

    /**
     * Returns an inbound request the gateway under test MUST reject as
     * unverified — e.g. same payload as {@see self::signedWebhookRequest()}
     * with the signature header stripped or mutated.
     *
     * @since 1.0.0
     *
     * @return Request
     */
    abstract protected function unsignedWebhookRequest(): Request;

    /**
     * Returns a {@see PaymentResult} representing a terminal (do-not-retry)
     * capture failure the gateway under test would produce for a declined
     * card. Satellites either force the gateway into that path via a stub
     * client, or construct a `PaymentResult::terminalFailure()` directly
     * to prove they wire the flag through.
     *
     * @since 1.0.0
     *
     * @return PaymentResult
     */
    abstract protected function captureTerminalDeclineResult(): PaymentResult;

    /**
     * Returns a {@see PaymentResult} representing a retryable capture
     * failure the gateway under test would produce for a provider brownout.
     *
     * @since 1.0.0
     *
     * @return PaymentResult
     */
    abstract protected function captureRetryableResult(): PaymentResult;

    /**
     * Whether the gateway under test supports refunds. Defaults to `true`;
     * satellites for cash / offline providers override to `false` and the
     * refund-only cases are skipped.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    protected function gatewaySupportsRefunds(): bool
    {
        return true;
    }

    /**
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  Test application.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders( $app ): array
    {
        return [ EcommerceServiceProvider::class ];
    }

    /**
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  Test application.
     */
    protected function defineEnvironment( $app ): void
    {
        $app[ 'config' ]->set( 'app.key', 'base64:' . base64_encode( random_bytes( 32 ) ) );
        $app[ 'config' ]->set( 'database.default', 'testbench' );
        $app[ 'config' ]->set( 'database.connections.testbench', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ] );
    }
}
