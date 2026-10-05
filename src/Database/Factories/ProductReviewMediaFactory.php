<?php

/**
 * ProductReviewMedia factory.
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

use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Models\ProductReviewMedia;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductReviewMedia>
 *
 * @since 1.0.0
 */
class ProductReviewMediaFactory extends Factory
{
    /**
     * @var class-string<ProductReviewMedia>
     */
    protected $model = ProductReviewMedia::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'review_id' => ProductReview::factory(),
            'media_id'  => $this->faker->numberBetween( 1, 10_000 ),
        ];
    }
}
