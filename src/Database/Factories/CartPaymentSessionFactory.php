<?php

/**
 * CartPaymentSession factory.
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

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartPaymentSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CartPaymentSession>
 *
 * @since 1.4.0
 */
class CartPaymentSessionFactory extends Factory
{
    /**
     * @var class-string<CartPaymentSession>
     */
    protected $model = CartPaymentSession::class;

    /**
     * @since 1.4.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id'         => Cart::factory(),
            'gateway_key'     => 'manual',
            'reference'       => 'ps_' . $this->faker->unique()->bothify( '????????####' ),
            'amount'          => $this->faker->numberBetween( 500, 50_000 ),
            'currency'        => 'USD',
            'status'          => CartPaymentSession::STATUS_ACTIVE,
            'provider_status' => null,
        ];
    }

    /**
     * A session a newer one replaced.
     *
     * @since 1.4.0
     *
     * @return static
     */
    public function superseded(): static
    {
        return $this->state( [ 'status' => CartPaymentSession::STATUS_SUPERSEDED, 'superseded_at' => Carbon::now() ] );
    }

    /**
     * A confirmed session no order took yet.
     *
     * @since 1.4.0
     *
     * @return static
     */
    public function stranded(): static
    {
        return $this->state( [ 'status' => CartPaymentSession::STATUS_STRANDED, 'provider_status' => 'succeeded', 'reason' => 'totals_changed', 'stranded_at' => Carbon::now() ] );
    }
}
