<?php

/**
 * BackInStockSubscription factory.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Database\Factories;

use ArtisanPackUI\Ecommerce\Models\BackInStockSubscription;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<BackInStockSubscription>
 *
 * @since 1.4.0
 */
class BackInStockSubscriptionFactory extends Factory
{
    /**
     * @var class-string<BackInStockSubscription>
     */
    protected $model = BackInStockSubscription::class;

    /**
     * @since 1.4.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email'              => mb_strtolower( $this->faker->unique()->safeEmail() ),
            'customer_id'        => null,
            'product_id'         => Product::factory(),
            'product_variant_id' => null,
            'locale'             => null,
            'notified_at'        => null,
            'unsubscribed_at'    => null,
        ];
    }

    /**
     * A subscription for `$customer`, with their email.
     *
     * @since 1.4.0
     *
     * @param  Customer  $customer  Customer.
     *
     * @return static
     */
    public function forCustomer( Customer $customer ): static
    {
        return $this->state( [ 'customer_id' => $customer->id, 'email' => mb_strtolower( (string) $customer->email ) ] );
    }

    /**
     * A subscription for one variant (and its product).
     *
     * @since 1.4.0
     *
     * @param  ProductVariant  $variant  Variant.
     *
     * @return static
     */
    public function forVariant( ProductVariant $variant ): static
    {
        return $this->state( [ 'product_id' => $variant->product_id, 'product_variant_id' => $variant->id ] );
    }

    /**
     * Already notified.
     *
     * @since 1.4.0
     *
     * @return static
     */
    public function notified(): static
    {
        return $this->state( [ 'notified_at' => Carbon::now() ] );
    }

    /**
     * Unsubscribed.
     *
     * @since 1.4.0
     *
     * @return static
     */
    public function unsubscribed(): static
    {
        return $this->state( [ 'unsubscribed_at' => Carbon::now() ] );
    }
}
