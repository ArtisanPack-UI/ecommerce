<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\AcceptsPaymentOptions;
use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
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

    public function paymentOptionRules( Cart $cart ): array
    {
        return self::$rules;
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
        PaymentOptionsInMemoryPaymentGateway::$rules = [ 'save' => [ 'sometimes', 'boolean' ] ];

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

        $this->expectException( Throwable::class );

        $this->test_payment_option_rules_are_keyed_validation_rules();
    }

    protected function gateway(): PaymentGateway
    {
        return new PaymentOptionsInMemoryPaymentGateway();
    }
}
