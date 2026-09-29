<?php

/**
 * TaxRate factory.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Database\Factories;

use ArtisanPackUI\Ecommerce\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRate>
 *
 * @since 1.0.0
 */
class TaxRateFactory extends Factory
{
    /**
     * @var class-string<TaxRate>
     */
    protected $model = TaxRate::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tax_class_key'       => 'standard',
            'country_code'        => 'US',
            'region_code'         => null,
            'postal_pattern'      => null,
            'rate_ubps'           => 50_000_000,
            'is_compound'         => false,
            'priority'            => 0,
            'label'               => 'Sales tax',
            'is_shipping_taxable' => false,
            'is_active'           => true,
        ];
    }

    /**
     * Levied on base + prior taxes.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function compound(): static
    {
        return $this->state( [ 'is_compound' => true ] );
    }

    /**
     * Disabled rate.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function inactive(): static
    {
        return $this->state( [ 'is_active' => false ] );
    }
}
