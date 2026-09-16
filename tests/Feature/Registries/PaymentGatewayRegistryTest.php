<?php

declare( strict_types=1 );

namespace Tests\Feature\Registries;

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use InvalidArgumentException;
use stdClass;
use Tests\Feature\Contracts\InMemoryPaymentGateway;
use Tests\TestCase;

final class PaymentGatewayRegistryTest extends TestCase
{
    public function test_find_returns_null_for_an_unknown_key(): void
    {
        /** @var PaymentGatewayRegistry $registry */
        $registry = $this->app->make( PaymentGatewayRegistry::class );

        $this->assertNull( $registry->find( 'nope-not-real' ) );
    }

    public function test_find_resolves_a_registered_gateway(): void
    {
        /** @var PaymentGatewayRegistry $registry */
        $registry = $this->app->make( PaymentGatewayRegistry::class );

        $registry->register( 'in-memory', new InMemoryPaymentGateway() );

        $gateway = $registry->find( 'in-memory' );

        $this->assertInstanceOf( PaymentGateway::class, $gateway );
        $this->assertSame( 'in-memory', $gateway->key() );
    }

    public function test_all_returns_every_registered_gateway_keyed_by_key(): void
    {
        /** @var PaymentGatewayRegistry $registry */
        $registry = $this->app->make( PaymentGatewayRegistry::class );

        $registry->register( 'in-memory', new InMemoryPaymentGateway() );

        $all = $registry->all();

        $this->assertArrayHasKey( 'in-memory', $all );
        $this->assertInstanceOf( PaymentGateway::class, $all[ 'in-memory' ] );
    }

    public function test_register_rejects_a_class_name_that_does_not_implement_the_contract(): void
    {
        /** @var PaymentGatewayRegistry $registry */
        $registry = $this->app->make( PaymentGatewayRegistry::class );

        $this->expectException( InvalidArgumentException::class );

        /** @phpstan-ignore-next-line — intentional bad input for the guard. */
        $registry->register( 'bad', stdClass::class );
    }
}
