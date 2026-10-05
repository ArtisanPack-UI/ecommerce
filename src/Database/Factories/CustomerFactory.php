<?php

/**
 * Customer factory.
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
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 *
 * @since 1.0.0
 */
class CustomerFactory extends Factory
{
    /**
     * @var class-string<Customer>
     */
    protected $model = Customer::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'    => null,
            'email'      => $this->faker->unique()->safeEmail(),
            'first_name' => $this->faker->firstName(),
            'last_name'  => $this->faker->lastName(),
            'meta'       => [],
        ];
    }

    /**
     * State: customer is linked to a user id.
     *
     * @since 1.0.0
     *
     * @param  int  $userId
     *
     * @return static
     */
    public function forUser( int $userId ): static
    {
        return $this->state( fn () => [ 'user_id' => $userId ] );
    }

    /**
     * State: guest customer (no user_id).
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function guest(): static
    {
        return $this->state( fn () => [ 'user_id' => null ] );
    }
}
