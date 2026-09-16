<?php

/**
 * ProductTypeContractTest.
 *
 * Abstract test class satellite packages extend to prove their
 * {@see \ArtisanPackUI\Ecommerce\Contracts\ProductType} implementation
 * upholds the invariants the engine relies on: stable registry key, a
 * non-empty label, a `variant_id`-shaped snapshot payload, and a
 * `priceLine()` that multiplies unit price by quantity.
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

use ArtisanPackUI\Ecommerce\Contracts\ProductType;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Money\Money;
use Orchestra\Testbench\TestCase;

/**
 * Contract test for {@see ProductType} implementations.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class ProductTypeContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_declares_a_non_empty_stable_registry_key(): void
    {
        $type = $this->productType();

        $this->assertNotSame( '', $type->key(), 'ProductType::key() must be a non-empty string.' );
        $this->assertSame( $type->key(), $type->key(), 'ProductType::key() must be stable across calls.' );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_it_declares_a_non_empty_label(): void
    {
        $this->assertNotSame( '', $this->productType()->label(), 'ProductType::label() must be a non-empty string.' );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_icon_returns_string_or_null(): void
    {
        $icon = $this->productType()->icon();

        $this->assertTrue(
            null === $icon || is_string( $icon ),
            'ProductType::icon() must return a string or null.',
        );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_validate_cart_options_returns_sanitized_array(): void
    {
        $type    = $this->productType();
        $product = $this->makeProduct();

        $sanitized = $type->validateCartOptions( $product, $this->sampleCartOptions() );

        $this->assertIsArray( $sanitized, 'ProductType::validateCartOptions() must return an array.' );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_price_line_multiplies_unit_price_by_quantity(): void
    {
        $type     = $this->productType();
        $product  = $this->makeProduct();
        $options  = $type->validateCartOptions( $product, $this->sampleCartOptions() );
        $currency = $this->sampleCurrency();

        $one = $type->priceLine( $product, $options, 1, $currency );
        $two = $type->priceLine( $product, $options, 2, $currency );

        $this->assertInstanceOf( Money::class, $one, 'ProductType::priceLine() must return a Money instance.' );
        $this->assertSame( $currency, $one->getCurrency()->getCode(), 'Returned Money must use the requested currency.' );
        $this->assertSame( $this->sampleUnitPriceMinor(), (int) $one->getAmount(), 'Unit price at quantity 1 must match sampleUnitPriceMinor().' );
        $this->assertSame( $this->sampleUnitPriceMinor() * 2, (int) $two->getAmount(), 'Line total at quantity 2 must be unit price × 2.' );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_build_order_snapshot_records_type_key(): void
    {
        $type = $this->productType();

        $item          = new CartItem();
        $item->options = $type->validateCartOptions( $this->makeProduct(), $this->sampleCartOptions() );

        $snapshot = $type->buildOrderSnapshot( $item );

        $this->assertIsArray( $snapshot, 'ProductType::buildOrderSnapshot() must return an array.' );
        $this->assertArrayHasKey( 'type', $snapshot, 'Snapshot must carry the ProductType::key() under the "type" key.' );
        $this->assertSame( $type->key(), $snapshot[ 'type' ], 'Snapshot "type" must equal ProductType::key().' );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_behavioural_flags_are_booleans(): void
    {
        $type = $this->productType();

        $this->assertIsBool( $type->requiresFulfillment() );
        $this->assertIsBool( $type->isInventoryTracked() );
    }

    /**
     * Provides the concrete {@see ProductType} under test.
     *
     * @since 1.0.0
     *
     * @return ProductType
     */
    abstract protected function productType(): ProductType;

    /**
     * Builds a persisted {@see Product} the product type accepts.
     *
     * Satellites should return a product whose currency, pricing, and
     * variant setup are compatible with the values returned by
     * {@see self::sampleCartOptions()}, {@see self::sampleCurrency()}, and
     * {@see self::sampleUnitPriceMinor()}.
     *
     * @since 1.0.0
     *
     * @return Product
     */
    abstract protected function makeProduct(): Product;

    /**
     * Cart-line options the product type considers valid.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    abstract protected function sampleCartOptions(): array;

    /**
     * Currency the product type is priced in for these fixtures.
     *
     * @since 1.0.0
     *
     * @return string
     */
    abstract protected function sampleCurrency(): string;

    /**
     * Expected unit price (minor units) at {@see self::sampleCurrency()}.
     *
     * @since 1.0.0
     *
     * @return int
     */
    abstract protected function sampleUnitPriceMinor(): int;

    /**
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  Test application.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders( $app ): array
    {
        return [ EcommerceServiceProvider::class ];
    }

    /**
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  Test application.
     */
    protected function defineEnvironment( $app ): void
    {
        $app[ 'config' ]->set( 'app.key', 'base64:' . base64_encode( random_bytes( 32 ) ) );
        $app[ 'config' ]->set( 'database.default', 'testbench' );
        $app[ 'config' ]->set( 'database.connections.testbench', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ] );
    }
}
