<?php

/**
 * CustomerNote factory.
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

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerNote>
 *
 * @since 1.0.0
 */
class CustomerNoteFactory extends Factory
{
    /**
     * @var class-string<CustomerNote>
     */
    protected $model = CustomerNote::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id'    => Customer::factory(),
            'author_user_id' => null,
            'body'           => $this->faker->sentence(),
        ];
    }
}
