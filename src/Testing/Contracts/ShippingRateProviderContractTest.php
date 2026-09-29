<?php

/**
 * ShippingRateProviderContractTest.
 *
 * Abstract suite satellites extend to prove their
 * {@see \ArtisanPackUI\Ecommerce\Contracts\ShippingRateProvider} honours:
 *
 * 1. **Key format.** `key()` follows engine spec §2.5.
 * 2. **Rates are quotable.** Every rate is a {@see ShippingRate} with a
 *    non-empty method key + label, a non-negative amount in the cart
 *    currency, and a unique `id()`.
 * 3. **Non-empty cart gets at least one rate** for the fixture destination.
 * 4. **Empty cart → empty collection.**
 * 5. **No mutation** of the cart or its lines, in storage or in memory.
 *
 * Engine spec §4.3, parent plan §6.1 / §15.2.
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

use ArtisanPackUI\Ecommerce\Contracts\ShippingRateProvider;
use ArtisanPackUI\Ecommerce\Testing\Contracts\Concerns\InteractsWithEcommerceCarts;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

/**
 * Contract test for {@see ShippingRateProvider} implementations.
 *
 * Satellites implement {@see self::provider()} and override
 * {@see self::seedServiceableDestination()} to stub their carrier API (or
 * seed zones) so {@see self::destination()} is serviceable.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class ShippingRateProviderContractTest extends TestCase
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
    public function test_rates_are_non_negative_in_the_cart_currency_with_unique_ids(): void
    {
        $this->seedServiceableDestination();

        foreach ( [ 'USD', 'EUR' ] as $currency ) {
            $rates = $this->provider()->getRatesForCart(
                $this->makePersistedCart( [ [ 'unit' => 3_000, 'qty' => 2, 'product' => [ 'weight' => '0.500', 'weight_unit' => 'kg' ] ] ], $currency ),
                $this->destination(),
            );

            $this->assertNotEmpty( $rates, 'A serviceable destination must yield at least one rate.' );

            foreach ( $rates as $rate ) {
                $this->assertInstanceOf( ShippingRate::class, $rate );
                $this->assertNotSame( '', trim( $rate->methodKey ), 'Rates must carry a method key.' );
                $this->assertNotSame( '', trim( $rate->label ), 'Rates must carry a customer-facing label.' );
                $this->assertSame( $currency, $rate->amount->getCurrency()->getCode(), 'Rates must be in the cart currency.' );
                $this->assertFalse( $rate->amount->isNegative(), 'Rates must not be negative.' );
            }

            $ids = $rates->map( fn ( ShippingRate $rate ): string => $rate->id() )->all();
            $this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'Rate ids must be unique so a client can select one.' );
        }
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_empty_cart_returns_no_rates(): void
    {
        $this->seedServiceableDestination();

        $this->assertCount( 0, $this->provider()->getRatesForCart( $this->makePersistedCart( [] ), $this->destination() ) );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_quoting_does_not_mutate_the_cart(): void
    {
        $this->seedServiceableDestination();

        $cart   = $this->makePersistedCart( [ [ 'unit' => 1_500 ] ] );
        $stored = $this->cartSnapshot( $cart );
        $memory = $this->memorySnapshot( $cart );

        $this->provider()->getRatesForCart( $cart, $this->destination() );

        $this->assertCartUntouched( $cart, $stored, $memory );
    }

    /**
     * The provider under test.
     *
     * @since 1.0.0
     *
     * @return ShippingRateProvider
     */
    abstract protected function provider(): ShippingRateProvider;

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
     * Arranges for {@see self::destination()} to be serviceable. Default no-op.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function seedServiceableDestination(): void
    {
    }
}
