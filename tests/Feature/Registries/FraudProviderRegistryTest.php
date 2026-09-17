<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\FraudProvider;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Registries\FraudProviderRegistry;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\FraudDecision;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;

final class RegistryTestApproveProvider implements FraudProvider
{
    public function __construct( public string $keyName = 'always-approve-test' )
    {
    }

    public function key(): string
    {
        return $this->keyName;
    }

    public function label(): string
    {
        return 'Always approve';
    }

    public function assess( Cart $cart, Address $shipping, PaymentSession $session ): FraudDecision
    {
        return FraudDecision::approve();
    }
}

it( 'registers and resolves a fraud provider', function (): void {
    /** @var FraudProviderRegistry $registry */
    $registry = app( FraudProviderRegistry::class );

    $registry->register( 'always-approve-test', new RegistryTestApproveProvider() );

    expect( $registry->has( 'always-approve-test' ) )->toBeTrue();
    expect( $registry->get( 'always-approve-test' ) )->toBeInstanceOf( RegistryTestApproveProvider::class );
    expect( $registry->keys() )->toContain( 'always-approve-test' );
} );

it( 'throws in testing environment when registering the same key twice', function (): void {
    /** @var FraudProviderRegistry $registry */
    $registry = app( FraudProviderRegistry::class );

    $registry->register( 'dup', new RegistryTestApproveProvider( 'dup' ) );
    $registry->register( 'dup', new RegistryTestApproveProvider( 'dup' ) );
} )->throws( InvalidArgumentException::class, 'already registered' );

it( 'refuses to resolve a provider whose self-reported key mismatches', function (): void {
    /** @var FraudProviderRegistry $registry */
    $registry = app( FraudProviderRegistry::class );

    // Provider's key() returns 'other', but registered under 'mismatched'.
    $registry->register( 'mismatched', new RegistryTestApproveProvider( 'other' ) );

    $registry->get( 'mismatched' );
} )->throws( RuntimeException::class, 'reports its own key as' );

it( 'throws on lookup of an unregistered key', function (): void {
    /** @var FraudProviderRegistry $registry */
    $registry = app( FraudProviderRegistry::class );

    $registry->get( 'no-such-provider' );
} )->throws( RuntimeException::class, 'No FraudProvider registered' );
