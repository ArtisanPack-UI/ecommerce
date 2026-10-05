<?php

/**
 * Cart factory.
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

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cart>
 *
 * @since 1.0.0
 */
class CartFactory extends Factory
{
    /**
     * @var class-string<Cart>
     */
    protected $model = Cart::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $currency = 'USD';

        return [
            'token'             => Str::random( 40 ),
            'customer_id'       => null,
            'currency'          => $currency,
            'email'             => null,
            'subtotal_currency' => $currency,
            'discount_currency' => $currency,
            'tax_currency'      => $currency,
            'shipping_currency' => $currency,
            'total_currency'    => $currency,
            'meta'              => [],
        ];
    }

    /**
     * State: cart is anchored to a specific currency (applies to every paired currency column).
     *
     * @since 1.0.0
     *
     * @param  string  $currency
     *
     * @return static
     */
    public function currency( string $currency ): static
    {
        return $this->state( fn () => [
            'currency'          => $currency,
            'subtotal_currency' => $currency,
            'discount_currency' => $currency,
            'tax_currency'      => $currency,
            'shipping_currency' => $currency,
            'total_currency'    => $currency,
        ] );
    }

    /**
     * State: cart belongs to a customer.
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
        ] );
    }

    /**
     * State: cart is a guest cart (no customer_id).
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function guest(): static
    {
        return $this->state( fn () => [ 'customer_id' => null ] );
    }
}
