<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Services\Fraud\AlwaysApproveFraudProvider;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use Money\Money;

it( 'always returns an approve verdict with the always_approve reason', function (): void {
    $provider = new AlwaysApproveFraudProvider();
    $cart     = new Cart();
    $shipping = new Address( '1 Navy Way', 'Arlington', 'US' );
    $session  = new PaymentSession( 'stripe', 'pi_test_123', Money::USD( 1000 ) );

    $decision = $provider->assess( $cart, $shipping, $session );

    expect( $provider->key() )->toBe( 'always-approve' );
    expect( $decision->isApprove() )->toBeTrue();
    expect( $decision->reasons )->toBe( [ 'always_approve' ] );
    expect( $decision->score )->toBe( 0 );
    expect( $decision->providerReference )->toBeNull();
} );
