<?php

/**
 * FraudProviderContractTest.
 *
 * Abstract suite satellites extend to prove their
 * {@see \ArtisanPackUI\Ecommerce\Contracts\FraudProvider} honours:
 *
 * 1. **Key format.** `key()` follows engine spec §2.5.
 * 2. **Well-formed decision.** Verdict is `approve|challenge|block`, score
 *    is 0–100, reasons are strings.
 * 3. **Fixture verdicts.** The provider returns `approve` for the low-risk
 *    fixture, and — when it can escalate at all — a non-approve verdict
 *    for the high-risk fixture.
 * 4. **No mutation** of the cart or its lines.
 *
 * Engine spec §4.17, parent plan §8.4 / §15.2.
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

use ArtisanPackUI\Ecommerce\Contracts\FraudProvider;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Testing\Contracts\Concerns\InteractsWithEcommerceCarts;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\FraudDecision;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Money\Currency;
use Money\Money;
use Orchestra\Testbench\TestCase;

/**
 * Contract test for {@see FraudProvider} implementations.
 *
 * Satellites implement {@see self::provider()} (typically wired to a
 * stubbed API client that inspects the session reference) and
 * {@see self::lowRiskSession()}. Providers that can escalate also
 * implement {@see self::highRiskSession()}; the default returns null,
 * which skips that case (e.g. `always-approve`).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class FraudProviderContractTest extends TestCase
{
    use InteractsWithEcommerceCarts;
    use RefreshDatabase;

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_key_follows_the_registry_key_format(): void
    {
        $this->assertMatchesRegularExpression( $this->registryKeyPattern, $this->provider()->key() );
        $this->assertNotSame( '', trim( $this->provider()->label() ) );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_low_risk_fixture_is_approved_with_a_well_formed_decision(): void
    {
        $cart     = $this->makePersistedCart( [ [ 'unit' => 2_000 ] ] );
        $decision = $this->provider()->assess( $cart, $this->shippingAddress(), $this->lowRiskSession( $cart ) );

        $this->assertWellFormed( $decision );
        $this->assertSame( FraudDecision::VERDICT_APPROVE, $decision->verdict, 'Low-risk fixture must be approved.' );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_high_risk_fixture_is_escalated(): void
    {
        $cart    = $this->makePersistedCart( [ [ 'unit' => 2_000 ] ] );
        $session = $this->highRiskSession( $cart );

        if ( null === $session ) {
            $this->markTestSkipped( 'Provider never escalates (no high-risk fixture).' );
        }

        $decision = $this->provider()->assess( $cart, $this->shippingAddress(), $session );

        $this->assertWellFormed( $decision );
        $this->assertNotSame( FraudDecision::VERDICT_APPROVE, $decision->verdict, 'High-risk fixture must challenge or block.' );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_assessment_does_not_mutate_the_cart(): void
    {
        $cart    = $this->makePersistedCart( [ [ 'unit' => 2_000 ] ] );
        $session = $this->lowRiskSession( $cart );
        $stored  = $this->cartSnapshot( $cart );
        $memory  = $this->memorySnapshot( $cart );

        $this->provider()->assess( $cart, $this->shippingAddress(), $session );

        $this->assertCartUntouched( $cart, $stored, $memory );
    }

    /**
     * The provider under test.
     *
     * @since 1.0.0
     *
     * @return FraudProvider
     */
    abstract protected function provider(): FraudProvider;

    /**
     * Payment session the provider should approve.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart being assessed.
     *
     * @return PaymentSession
     */
    protected function lowRiskSession( Cart $cart ): PaymentSession
    {
        return $this->paymentSessionFor( $cart, 'pi_low_risk' );
    }

    /**
     * Payment session the provider should challenge or block, or null when
     * the provider never escalates.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart being assessed.
     *
     * @return PaymentSession|null
     */
    protected function highRiskSession( Cart $cart ): ?PaymentSession
    {
        return null;
    }

    /**
     * Builds a session for `$cart` with the given provider reference.
     *
     * @since 1.0.0
     *
     * @param  Cart    $cart       Cart.
     * @param  string  $reference  Provider reference.
     *
     * @return PaymentSession
     */
    protected function paymentSessionFor( Cart $cart, string $reference ): PaymentSession
    {
        return new PaymentSession(
            gatewayKey: 'stripe',
            reference: $reference,
            amount: new Money( (int) $cart->items->sum( 'line_total_amount' ), new Currency( (string) $cart->currency ) ),
        );
    }

    /**
     * Shipping address used by every case.
     *
     * @since 1.0.0
     *
     * @return Address
     */
    protected function shippingAddress(): Address
    {
        return new Address( address1: '1 Main St', city: 'Chicago', countryCode: 'US', regionCode: 'IL', postalCode: '60601' );
    }

    /**
     * @since 1.0.0
     *
     * @param  FraudDecision  $decision  Decision to check.
     *
     * @return void
     */
    private function assertWellFormed( FraudDecision $decision ): void
    {
        $this->assertContains( $decision->verdict, [ FraudDecision::VERDICT_APPROVE, FraudDecision::VERDICT_CHALLENGE, FraudDecision::VERDICT_BLOCK ] );
        $this->assertGreaterThanOrEqual( 0, $decision->score );
        $this->assertLessThanOrEqual( 100, $decision->score );

        foreach ( $decision->reasons as $reason ) {
            $this->assertIsString( $reason );
        }
    }
}
