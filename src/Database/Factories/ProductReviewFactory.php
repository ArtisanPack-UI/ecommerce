<?php

/**
 * ProductReview factory.
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

use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductReview>
 *
 * @since 1.0.0
 */
class ProductReviewFactory extends Factory
{
    /**
     * @var class-string<ProductReview>
     */
    protected $model = ProductReview::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id'           => Product::factory(),
            'customer_id'          => null,
            'order_id'             => null,
            'author_name'          => $this->faker->name(),
            'author_email'         => $this->faker->safeEmail(),
            'rating'               => $this->faker->numberBetween( 1, 5 ),
            'title'                => $this->faker->sentence( 4 ),
            'body'                 => $this->faker->paragraph(),
            'is_verified_purchase' => false,
            'status'               => ProductReview::STATUS_PENDING,
            'approved_at'          => null,
            'reviewed_by_user_id'  => null,
        ];
    }

    /**
     * An approved, storefront-visible review.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function approved(): static
    {
        return $this->state( fn (): array => [
            'status'      => ProductReview::STATUS_APPROVED,
            'approved_at' => now(),
        ] );
    }
}
