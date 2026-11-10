<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\AcceptsPaymentOptions;
use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use BadMethodCallException;
use Throwable;

require_once __DIR__ . '/InMemoryPaymentGatewayContractTest.php';

/**
 * The in-memory reference gateway, accepting payment options (#211).
 */
final class PaymentOptionsInMemoryPaymentGateway extends InMemoryPaymentGateway implements AcceptsPaymentOptions
{
    /**
     * What {@see self::paymentOptionRules()} returns.
     *
     * @var array<mixed>
     */
    public static array $rules = [ 'save' => [ 'sometimes', 'boolean' ] ];

    /**
     * The cart {@see self::paymentOptionRules()} was last asked about.
     */
    public static ?Cart $lastCart = null;

    /**
     * Like a gateway that saves payment methods, it only has options for a
     * signed-in owner's cart.
     */
    public function paymentOptionRules( Cart $cart ): array
    {
        self::$lastCart = $cart;

        return null === $cart->getAttribute( 'customer_id' ) ? [] : self::$rules;
    }

    public function paymentOptionsChanged( Cart $cart, PaymentSession $session, array $options ): bool
    {
        return false;
    }
}

/**
 * Runs the shared {@see \ArtisanPackUI\Ecommerce\Testing\Contracts\PaymentGatewayContractTest}
 * suite against a gateway implementing {@see AcceptsPaymentOptions}, so its
 * payment-option check runs instead of being skipped.
 *
 * @since 1.3.0
 */
final class PaymentOptionsPaymentGatewayContractTest extends InMemoryPaymentGatewayContractTest
{
    protected function tearDown(): void
    {
        PaymentOptionsInMemoryPaymentGateway::$rules    = [ 'save' => [ 'sometimes', 'boolean' ] ];
        PaymentOptionsInMemoryPaymentGateway::$lastCart = null;

        parent::tearDown();
    }

    public function test_the_payment_option_check_fails_for_rules_not_keyed_by_option(): void
    {
        PaymentOptionsInMemoryPaymentGateway::$rules = [ [ 'boolean' ] ];

        try {
            $this->test_payment_option_rules_are_keyed_validation_rules();
        } catch ( Throwable $exception ) {
            $this->assertStringContainsString( 'keyed by option name', $exception->getMessage() );

            return;
        }

        $this->fail( 'The payment-option check must fail for rules not keyed by option name.' );
    }

    public function test_the_payment_option_check_fails_for_a_rule_laravel_does_not_know(): void
    {
        PaymentOptionsInMemoryPaymentGateway::$rules = [ 'save' => [ 'not_a_real_rule' ] ];

        $this->expectException( BadMethodCallException::class );

        $this->test_payment_option_rules_are_keyed_validation_rules();
    }

    public function test_the_payment_option_check_asks_about_the_overridden_cart(): void
    {
        $this->test_payment_option_rules_are_keyed_validation_rules();

        $this->assertSame( 42, PaymentOptionsInMemoryPaymentGateway::$lastCart?->getAttribute( 'customer_id' ) );
    }

    protected function gateway(): PaymentGateway
    {
        return new PaymentOptionsInMemoryPaymentGateway();
    }

    protected function paymentOptionsCart(): Cart
    {
        return ( new Cart() )->forceFill( [ 'customer_id' => 42 ] );
    }
}
