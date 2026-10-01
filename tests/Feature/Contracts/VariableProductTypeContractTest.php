<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\ProductType;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\ProductTypes\VariableProductType;
use ArtisanPackUI\Ecommerce\Testing\Contracts\ProductTypeContractTest;

/**
 * Verifies the reference {@see VariableProductType} satisfies the shared
 * {@see ProductTypeContractTest} suite.
 *
 * @since 1.0.0
 */
final class VariableProductTypeContractTest extends ProductTypeContractTest
{
    /**
     * Variant created by the last {@see self::makeProduct()} call.
     *
     * @var int
     */
    private int $variantId = 0;

    /**
     * @since 1.0.0
     *
     * @return ProductType
     */
    protected function productType(): ProductType
    {
        return new VariableProductType();
    }

    /**
     * @since 1.0.0
     *
     * @return Product
     */
    protected function makeProduct(): Product
    {
        $product = Product::factory()->variable()->create();
        $variant = ProductVariant::factory()->create( [ 'product_id' => $product->id ] );

        ProductPrice::factory()->forPriceable( $variant )->create( [
            'currency'     => $this->sampleCurrency(),
            'price_amount' => $this->sampleUnitPriceMinor(),
        ] );

        $this->variantId = $variant->id;

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
            'variant_id'   => $this->variantId,
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
        return [ 'variant_id' => $this->variantId ];
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
