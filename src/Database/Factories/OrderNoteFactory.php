<?php

/**
 * OrderNote factory.
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
use ArtisanPackUI\Ecommerce\Models\OrderNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderNote>
 *
 * @since 1.0.0
 */
class OrderNoteFactory extends Factory
{
    /**
     * @var class-string<OrderNote>
     */
    protected $model = OrderNote::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id'            => Order::factory(),
            'author_user_id'      => null,
            'body'                => $this->faker->sentence(),
            'is_customer_visible' => false,
        ];
    }

    /**
     * State: note is visible to the shopper on the storefront order view.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function customerVisible(): static
    {
        return $this->state( fn () => [ 'is_customer_visible' => true ] );
    }
}
