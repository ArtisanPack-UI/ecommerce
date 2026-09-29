<?php

/**
 * TaxProviderContractTest.
 *
 * Abstract suite satellites extend to prove their
 * {@see \ArtisanPackUI\Ecommerce\Contracts\TaxProvider} honours the
 * invariants the checkout relies on:
 *
 * 1. **Key format.** `key()` follows engine spec §2.5.
 * 2. **Currency.** Every amount in the result is in the cart currency.
 * 3. **One line entry per cart item**, keyed by cart-item id.
 * 4. **Totals reconcile.** `total` = Σ `perLine` + `shipping`, and when a
 *    breakdown is returned, Σ breakdown amounts = `total`.
 * 5. **No negative tax.**
 * 6. **Empty cart → zero.**
 * 7. **Actually taxes.** The seeded fixture levies positive tax (opt-out
 *    via `expectsTaxForFixture()`).
 * 8. **No mutation.** The cart and its lines are unchanged in storage and
 *    in memory.
 * 9. **Deterministic.** The same cart + destination yields the same total.
 *
 * Engine spec §4.5, parent plan §15.2.
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

use ArtisanPackUI\Ecommerce\Contracts\TaxProvider;
use ArtisanPackUI\Ecommerce\Testing\Contracts\Concerns\InteractsWithEcommerceCarts;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\TaxResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Money\Money;
use Orchestra\Testbench\TestCase;

/**
 * Contract test for {@see TaxProvider} implementations.
 *
 * Satellites implement {@see self::provider()} and may override
 * {@see self::destination()} and {@see self::seedTaxableJurisdiction()}
 * (e.g. to stub an HTTP client) so the taxable cases actually levy tax.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class TaxProviderContractTest extends TestCase
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
    public function test_result_is_in_the_cart_currency_with_one_entry_per_line(): void
    {
        $this->seedTaxableJurisdiction();

        foreach ( [ 'USD', 'EUR' ] as $currency ) {
            $cart   = $this->makePersistedCart( [ [ 'unit' => 2_500, 'qty' => 2 ], [ 'unit' => 999 ] ], $currency );
            $result = $this->provider()->calculate( $cart, $this->destination() );

            $this->assertSame( $currency, $result->total->getCurrency()->getCode(), 'Total must be in the cart currency.' );
            $this->assertSame( $currency, $result->shipping->getCurrency()->getCode(), 'Shipping tax must be in the cart currency.' );
            $this->assertEqualsCanonicalizing(
                $cart->items->pluck( 'id' )->all(),
                array_keys( $result->perLine ),
                'perLine must hold exactly one entry per cart item, keyed by cart-item id.',
            );

            foreach ( [ ...array_values( $result->perLine ), ...array_column( $result->breakdown, 'amount' ) ] as $amount ) {
                $this->assertSame( $currency, $amount->getCurrency()->getCode() );
            }
        }
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_totals_reconcile_and_are_never_negative(): void
    {
        $this->seedTaxableJurisdiction();

        $cart   = $this->makePersistedCart( [ [ 'unit' => 1_999, 'qty' => 3 ], [ 'unit' => 12_345 ] ], 'USD', [ 'shipping_amount' => 795 ] );
        $result = $this->provider()->calculate( $cart, $this->destination() );

        $this->assertFalse( $result->total->isNegative(), 'Tax total must not be negative.' );

        $lineSum = $this->sum( $result, array_values( $result->perLine ) )->add( $result->shipping );
        $this->assertTrue( $lineSum->equals( $result->total ), 'total must equal Σ perLine + shipping.' );

        foreach ( $result->perLine as $line ) {
            $this->assertFalse( $line->isNegative(), 'Per-line tax must not be negative.' );
        }

        if ( [] !== $result->breakdown ) {
            $breakdownSum = $this->sum( $result, array_column( $result->breakdown, 'amount' ) );
            $this->assertTrue( $breakdownSum->equals( $result->total ), 'Σ breakdown amounts must equal total.' );
        }
    }

    /**
     * A taxable fixture must actually be taxed — otherwise a provider that
     * always returns zero would satisfy every other invariant. Providers
     * that can't arrange a taxable fixture override
     * {@see self::expectsTaxForFixture()} to skip this case.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_seeded_jurisdiction_is_taxed(): void
    {
        if ( ! $this->expectsTaxForFixture() ) {
            $this->markTestSkipped( 'Provider cannot arrange a taxable fixture.' );
        }

        $this->seedTaxableJurisdiction();

        $result = $this->provider()->calculate( $this->makePersistedCart( [ [ 'unit' => 10_000 ] ] ), $this->destination() );

        $this->assertTrue( $result->total->isPositive(), 'The seeded jurisdiction must levy some tax.' );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_empty_cart_owes_no_tax(): void
    {
        $this->seedTaxableJurisdiction();

        $result = $this->provider()->calculate( $this->makePersistedCart( [] ), $this->destination() );

        $this->assertTrue( $result->total->isZero() );
        $this->assertSame( [], $result->perLine );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_calculation_is_deterministic_and_does_not_mutate_the_cart(): void
    {
        $this->seedTaxableJurisdiction();

        $cart   = $this->makePersistedCart( [ [ 'unit' => 4_200, 'qty' => 2 ] ] );
        $stored = $this->cartSnapshot( $cart );
        $memory = $this->memorySnapshot( $cart );

        $first  = $this->provider()->calculate( $cart, $this->destination() );
        $second = $this->provider()->calculate( $cart, $this->destination() );

        $this->assertTrue( $first->total->equals( $second->total ), 'Same input must yield the same tax.' );
        $this->assertCartUntouched( $cart, $stored, $memory );
    }

    /**
     * The provider under test.
     *
     * @since 1.0.0
     *
     * @return TaxProvider
     */
    abstract protected function provider(): TaxProvider;

    /**
     * Destination used by every case.
     *
     * @since 1.0.0
     *
     * @return Address
     */
    protected function destination(): Address
    {
        return new Address( address1: '1 Main St', city: 'Chicago', countryCode: 'US', regionCode: 'IL', postalCode: '60601' );
    }

    /**
     * Whether {@see self::seedTaxableJurisdiction()} makes the fixture
     * taxable. Defaults to true.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    protected function expectsTaxForFixture(): bool
    {
        return true;
    }

    /**
     * Arranges for {@see self::destination()} to be taxable. Default no-op.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function seedTaxableJurisdiction(): void
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  TaxResult           $result   Result (for the zero value).
     * @param  array<int, Money>   $amounts  Amounts to sum.
     *
     * @return Money
     */
    private function sum( TaxResult $result, array $amounts ): Money
    {
        return array_reduce( $amounts, static fn ( Money $carry, Money $m ): Money => $carry->add( $m ), $result->total->multiply( 0 ) );
    }
}
