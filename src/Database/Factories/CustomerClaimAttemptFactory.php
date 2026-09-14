<?php

/**
 * CustomerClaimAttempt factory.
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
use ArtisanPackUI\Ecommerce\Models\CustomerClaimAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CustomerClaimAttempt>
 *
 * @since 1.0.0
 */
class CustomerClaimAttemptFactory extends Factory
{
    /**
     * @var class-string<CustomerClaimAttempt>
     */
    protected $model = CustomerClaimAttempt::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id'  => Customer::factory(),
            'order_number' => strtoupper( $this->faker->bothify( '??####' ) ),
            'ip_address'   => $this->faker->ipv4(),
            'was_success'  => false,
            'created_at'   => Carbon::now(),
        ];
    }

    /**
     * State: attempt succeeded.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function success(): static
    {
        return $this->state( fn () => [ 'was_success' => true ] );
    }
}
