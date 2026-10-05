<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\FraudProvider;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Services\Fraud\ChainFraudProvider;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\FraudDecision;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use Money\Money;

final class ChainFakeProvider implements FraudProvider
{
    public int $calls = 0;

    public function __construct(
        public readonly string $keyName,
        public readonly FraudDecision $decision,
    ) {
    }

    public function key(): string
    {
        return $this->keyName;
    }

    public function label(): string
    {
        return $this->keyName;
    }

    public function assess( Cart $cart, Address $shipping, PaymentSession $session ): FraudDecision
    {
        $this->calls++;

        return $this->decision;
    }
}

function chainFixtures(): array
{
    return [
        new Cart(),
        new Address( '1 Navy Way', 'Arlington', 'US' ),
        new PaymentSession( 'stripe', 'pi_chain_1', Money::USD( 5000 ) ),
    ];
}

it( 'requires at least two providers', function (): void {
    new ChainFraudProvider( [ new ChainFakeProvider( 'only', FraudDecision::approve() ) ] );
} )->throws( InvalidArgumentException::class, 'at least two' );

it( 'rejects non-FraudProvider entries', function (): void {
    new ChainFraudProvider( [ new ChainFakeProvider( 'ok', FraudDecision::approve() ), new stdClass() ] );
} )->throws( InvalidArgumentException::class, 'must implement' );

it( 'runs every provider and returns the most conservative verdict', function (): void {
    $approve   = new ChainFakeProvider( 'approve-p', FraudDecision::approve( 10, [ 'ok' ] ) );
    $challenge = new ChainFakeProvider( 'challenge-p', FraudDecision::challenge( 42, [ '3ds_recommended' ], 'ch_1' ) );
    $block     = new ChainFakeProvider( 'block-p', FraudDecision::block( 99, [ 'signals_bad' ], 'ref_block' ) );

    $chain = new ChainFraudProvider( [ $approve, $challenge, $block ] );

    [ $cart, $shipping, $session ] = chainFixtures();

    $decision = $chain->assess( $cart, $shipping, $session );

    expect( $approve->calls )->toBe( 1 );
    expect( $challenge->calls )->toBe( 1 );
    expect( $block->calls )->toBe( 1 );
    expect( $decision->isBlock() )->toBeTrue();
    expect( $decision->score )->toBe( 99 );
    expect( $decision->reasons )->toContain( 'approve-p:ok', 'challenge-p:3ds_recommended', 'block-p:signals_bad' );
    expect( $decision->providerReference )->toContain( 'challenge-p=ch_1', 'block-p=ref_block' );
} );

it( 'folds challenge over approve when no block is present', function (): void {
    $approve   = new ChainFakeProvider( 'approve-p', FraudDecision::approve( 5 ) );
    $challenge = new ChainFakeProvider( 'challenge-p', FraudDecision::challenge( 20 ) );

    $chain = new ChainFraudProvider( [ $approve, $challenge ] );

    [ $cart, $shipping, $session ] = chainFixtures();

    $decision = $chain->assess( $cart, $shipping, $session );

    expect( $decision->isChallenge() )->toBeTrue();
    expect( $decision->score )->toBe( 20 );
} );

it( 'returns approve when every provider approves', function (): void {
    $one = new ChainFakeProvider( 'one', FraudDecision::approve( 1 ) );
    $two = new ChainFakeProvider( 'two', FraudDecision::approve( 2 ) );

    $chain = new ChainFraudProvider( [ $one, $two ] );

    [ $cart, $shipping, $session ] = chainFixtures();

    $decision = $chain->assess( $cart, $shipping, $session );

    expect( $decision->isApprove() )->toBeTrue();
    expect( $decision->score )->toBe( 2 );
    expect( $decision->providerReference )->toBeNull();
} );

it( 'reports its own key as chain', function (): void {
    $chain = new ChainFraudProvider( [
        new ChainFakeProvider( 'a', FraudDecision::approve() ),
        new ChainFakeProvider( 'b', FraudDecision::approve() ),
    ] );

    expect( $chain->key() )->toBe( 'chain' );
} );
