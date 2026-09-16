<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\ProductType;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\ProductTypes\SimpleProductType;
use ArtisanPackUI\Ecommerce\Testing\Contracts\ProductTypeContractTest;

/**
 * Verifies the reference {@see SimpleProductType} satisfies the shared
 * {@see ProductTypeContractTest} suite.
 *
 * @since 1.0.0
 */
final class SimpleProductTypeContractTest extends ProductTypeContractTest
{
    /**
     * @since 1.0.0
     *
     * @return ProductType
     */
    protected function productType(): ProductType
    {
        return new SimpleProductType();
    }

    /**
     * @since 1.0.0
     *
     * @return Product
     */
    protected function makeProduct(): Product
    {
        $product = Product::factory()->simple()->create();

        ProductPrice::factory()->forPriceable( $product )->create( [
            'currency'     => $this->sampleCurrency(),
            'price_amount' => $this->sampleUnitPriceMinor(),
        ] );

        return $product;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    protected function sampleCartOptions(): array
    {
        return [
            'stowaway_key' => 'ignored',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    protected function sampleSanitizedCartOptions(): array
    {
        return [];
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    protected function sampleCurrency(): string
    {
        return 'USD';
    }

    /**
     * @since 1.0.0
     *
     * @return int
     */
    protected function sampleUnitPriceMinor(): int
    {
        return 1_999;
    }
}
