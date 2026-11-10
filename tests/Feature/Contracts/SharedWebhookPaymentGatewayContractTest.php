<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Contracts\SharesWebhookEndpoint;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\CheckoutFakeGateway;

require_once __DIR__ . '/InMemoryPaymentGatewayContractTest.php';

/**
 * The in-memory reference gateway, sharing its webhook endpoint with an
 * `in-memory-card` sibling (#207).
 */
final class SharedWebhookInMemoryPaymentGateway extends InMemoryPaymentGateway implements SharesWebhookEndpoint
{
    /**
     * Keys declared on top of the gateway's own and its registered sibling.
     *
     * @var array<int, string>
     */
    public static array $extraKeys = [];

    public function webhookGatewayKeys(): array
    {
        return [ 'in-memory', 'in-memory-card', ...self::$extraKeys ];
    }
}

/**
 * Runs the shared {@see \ArtisanPackUI\Ecommerce\Testing\Contracts\PaymentGatewayContractTest}
 * suite against a gateway implementing {@see SharesWebhookEndpoint}, so its
 * shared-key check runs instead of being skipped.
 *
 * @since 1.3.0
 */
final class SharedWebhookPaymentGatewayContractTest extends InMemoryPaymentGatewayContractTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->app->make( PaymentGatewayRegistry::class );
        $registry->register( 'in-memory', new SharedWebhookInMemoryPaymentGateway() );
        $registry->register( 'in-memory-card', new CheckoutFakeGateway( 'in-memory-card' ) );
    }

    protected function tearDown(): void
    {
        SharedWebhookInMemoryPaymentGateway::$extraKeys = [];

        parent::tearDown();
    }

    public function test_the_shared_key_check_fails_for_an_unregistered_key(): void
    {
        SharedWebhookInMemoryPaymentGateway::$extraKeys = [ 'in-memory-ghost' ];

        try {
            $this->test_shared_webhook_gateway_keys_are_registered_and_include_its_own_key();
        } catch ( AssertionFailedError $exception ) {
            $this->assertStringContainsString( '"in-memory-ghost"', $exception->getMessage() );

            return;
        }

        $this->fail( 'The shared-key check must fail when a declared key is not registered.' );
    }

    protected function gateway(): PaymentGateway
    {
        return new SharedWebhookInMemoryPaymentGateway();
    }
}
