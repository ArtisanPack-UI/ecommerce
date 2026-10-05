<?php

/**
 * Order factory.
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
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 *
 * @since 1.0.0
 */
class OrderFactory extends Factory
{
    /**
     * @var class-string<Order>
     */
    protected $model = Order::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $currency = 'USD';
        $subtotal = 5_000;
        $total    = 5_000;

        return [
            'order_number'            => strtoupper( Str::random( 12 ) ),
            'customer_id'             => null,
            'email'                   => $this->faker->unique()->safeEmail(),
            'phone'                   => null,
            'system_status'           => 'pending',
            'substatus_id'            => null,
            'payment_status'          => 'pending',
            'fulfillment_status'      => 'unfulfilled',
            'currency'                => $currency,
            'base_currency'           => $currency,
            'fx_rate_to_base_e8'      => 100_000_000,
            'subtotal_amount'         => $subtotal,
            'subtotal_currency'       => $currency,
            'discount_amount'         => 0,
            'discount_currency'       => $currency,
            'tax_amount'              => 0,
            'tax_currency'            => $currency,
            'shipping_amount'         => 0,
            'shipping_currency'       => $currency,
            'total_amount'            => $total,
            'total_currency'          => $currency,
            'total_refunded_amount'   => 0,
            'total_refunded_currency' => $currency,
            'shipping_address'        => null,
            'billing_address'         => null,
            'is_claimed'              => true,
            'meta'                    => [],
            'placed_at'               => now(),
        ];
    }

    /**
     * State: order belongs to a customer.
     *
     * @since 1.0.0
     *
     * @param  Customer|int  $customer
     *
     * @return static
     */
    public function forCustomer( Customer|int $customer ): static
    {
        return $this->state( fn () => [
            'customer_id' => $customer instanceof Customer ? $customer->id : $customer,
            'is_claimed'  => true,
        ] );
    }

    /**
     * State: order is an unclaimed guest order.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function guest(): static
    {
        return $this->state( fn () => [
            'customer_id' => null,
            'is_claimed'  => false,
        ] );
    }

    /**
     * State: apply a system status.
     *
     * @since 1.0.0
     *
     * @param  string  $systemStatus
     *
     * @return static
     */
    public function withSystemStatus( string $systemStatus ): static
    {
        return $this->state( fn () => [ 'system_status' => $systemStatus ] );
    }
}
