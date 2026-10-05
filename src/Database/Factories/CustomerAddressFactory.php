<?php

/**
 * CustomerAddress factory.
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
use ArtisanPackUI\Ecommerce\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAddress>
 *
 * @since 1.0.0
 */
class CustomerAddressFactory extends Factory
{
    /**
     * @var class-string<CustomerAddress>
     */
    protected $model = CustomerAddress::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id'  => Customer::factory(),
            'first_name'   => $this->faker->firstName(),
            'last_name'    => $this->faker->lastName(),
            'address1'     => $this->faker->streetAddress(),
            'city'         => $this->faker->city(),
            'region'       => $this->faker->state(),
            'postal_code'  => $this->faker->postcode(),
            'country_code' => 'US',
        ];
    }

    /**
     * State: default shipping address.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function defaultShipping(): static
    {
        return $this->state( fn () => [ 'is_default_shipping' => true ] );
    }

    /**
     * State: default billing address.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function defaultBilling(): static
    {
        return $this->state( fn () => [ 'is_default_billing' => true ] );
    }
}
