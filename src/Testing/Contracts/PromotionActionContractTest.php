<?php

/**
 * PromotionActionContractTest.
 *
 * Abstract suite satellites extend to prove their
 * {@see \ArtisanPackUI\Ecommerce\Contracts\PromotionAction} honours:
 *
 * 1. **Key format.** `key()` follows engine spec §2.5.
 * 2. **Has an effect** on the applicable fixture: a positive discount,
 *    free shipping, or a free item.
 * 3. **Never throws** on an empty cart or malformed config, and grants no
 *    discount for either.
 * 4. **No mutation** of the cart or its lines, in storage or in memory.
 *
 * The suite also re-asserts the ledger's own guarantees under stacking
 * (bounded, non-negative, in currency). {@see DiscountLedger} enforces
 * those itself, so they document the contract rather than police it.
 *
 * Engine spec §4.11, parent plan §5.9 / §15.2.
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

use ArtisanPackUI\Ecommerce\Contracts\PromotionAction;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Support\DiscountLedger;
use ArtisanPackUI\Ecommerce\Testing\Contracts\Concerns\InteractsWithEcommerceCarts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;
use stdClass;

/**
 * Contract test for {@see PromotionAction} implementations.
 *
 * Satellites implement {@see self::action()} and {@see self::applicableCase()}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class PromotionActionContractTest extends TestCase
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
        $this->assertMatchesRegularExpression( $this->registryKeyPattern, $this->action()->key() );
        $this->assertNotSame( '', trim( $this->action()->label() ) );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_applicable_fixture_produces_an_effect(): void
    {
        [ $cart, $config ] = $this->applicableCase();
        $ledger            = new DiscountLedger( $cart );

        $this->action()->apply( $cart, $ledger, $config );

        $this->assertTrue(
            $ledger->total()->isPositive() || $ledger->hasFreeShipping() || [] !== $ledger->freeItems(),
            'Applicable fixture must yield a discount, free shipping, or a free item.',
        );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_discounts_stay_bounded_and_in_currency_when_stacked(): void
    {
        [ $cart, $config ] = $this->applicableCase();
        $ledger            = new DiscountLedger( $cart );
        $subtotal          = (int) $cart->items->sum( 'line_total_amount' );

        for ( $i = 0; $i < 5; $i++ ) {
            $this->action()->apply( $cart, $ledger, $config );
        }

        $this->assertLessThanOrEqual( $subtotal, (int) $ledger->total()->getAmount(), 'Ledger total must never exceed the subtotal.' );
        $this->assertSame( strtoupper( (string) $cart->currency ), $ledger->total()->getCurrency()->getCode() );

        $lines = $cart->items->keyBy( 'id' );

        foreach ( $ledger->lineDiscounts() as $lineId => $discount ) {
            $this->assertFalse( $discount->isNegative(), 'Line discounts must not be negative.' );
            $this->assertLessThanOrEqual( (int) $lines[ $lineId ]->line_total_amount, (int) $discount->getAmount(), 'A line cannot be discounted past its total.' );
        }
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_empty_cart_and_malformed_config_are_harmless(): void
    {
        [ $cart, $config ] = $this->applicableCase();

        $empty       = $this->makePersistedCart( [] );
        $emptyLedger = new DiscountLedger( $empty );
        $this->action()->apply( $empty, $emptyLedger, $config );
        $this->assertTrue( $emptyLedger->total()->isZero(), 'An empty cart cannot be discounted.' );

        foreach ( [ [], [ 'garbage' => new stdClass() ], array_map( static fn (): string => 'not-a-value', $config ) ] as $candidate ) {
            $ledger = new DiscountLedger( $cart );
            $this->action()->apply( $cart, $ledger, $candidate );
            $this->assertTrue( $ledger->total()->isZero(), 'Missing or malformed config must not grant a discount.' );
        }
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_applying_does_not_mutate_the_cart(): void
    {
        [ $cart, $config ] = $this->applicableCase();
        $stored            = $this->cartSnapshot( $cart );
        $memory            = $this->memorySnapshot( $cart );

        $this->action()->apply( $cart, new DiscountLedger( $cart ), $config );

        $this->assertCartUntouched( $cart, $stored, $memory );
    }

    /**
     * The action under test.
     *
     * @since 1.0.0
     *
     * @return PromotionAction
     */
    abstract protected function action(): PromotionAction;

    /**
     * A cart + config the action must have an effect on.
     *
     * @since 1.0.0
     *
     * @return array{0: Cart, 1: array<string, mixed>}
     */
    abstract protected function applicableCase(): array;
}
