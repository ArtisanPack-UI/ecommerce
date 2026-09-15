<?php

/**
 * Refund factory.
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

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 *
 * @since 1.0.0
 */
class RefundFactory extends Factory
{
    /**
     * @var class-string<Refund>
     */
    protected $model = Refund::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id'          => Order::factory(),
            'amount'            => 1_000,
            'currency'          => 'USD',
            'reason'            => $this->faker->sentence(),
            'gateway_reference' => strtoupper( $this->faker->bothify( 're_?????????????' ) ),
            'issued_by_user_id' => null,
        ];
    }
}
