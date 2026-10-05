<?php

/**
 * CustomerLifetimeValueOverCondition.
 *
 * `customer-lifetime-value-over` (audit I3): the signed-in customer has
 * spent more than `amount` with the store — their `total_spent` (paid
 * orders net of refunds, in the base currency). Guests never match.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Promotions\Conditions;

use ArtisanPackUI\Ecommerce\Contracts\DescribesConfig;
use ArtisanPackUI\Ecommerce\Contracts\OrderAwarePromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Services\CurrencyConverter;
use ArtisanPackUI\Ecommerce\Services\StoreCurrencies;
use ArtisanPackUI\Ecommerce\Support\ConfigField;
use Money\Currency;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerLifetimeValueOverCondition implements OrderAwarePromotionCondition, DescribesConfig
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'customer-lifetime-value-over';

    /**
     * @since 1.0.0
     *
     * @param  CurrencyConverter  $converter   Converts the configured threshold.
     * @param  StoreCurrencies    $currencies  Base currency.
     */
    public function __construct(
        private readonly CurrencyConverter $converter,
        private readonly StoreCurrencies $currencies,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return __( 'Customer lifetime spend over' );
    }

    /**
     * Fields this condition's `config` takes.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [
            ConfigField::make( 'amount', 'money', __( 'Spent more than' ), [ 'required' => true ] ),
        ];
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart.
     * @param  array<string, mixed>  $config  `{ amount: int|map }`.
     *
     * @return bool
     */
    public function evaluate( Cart $cart, array $config ): bool
    {
        return $this->matches( null === $cart->customer_id ? null : Customer::query()->find( $cart->customer_id ), $config );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order                 $order   Placed order.
     * @param  array<string, mixed>  $config  `{ amount: int|map }`.
     *
     * @return bool
     */
    public function evaluateOrder( Order $order, array $config ): bool
    {
        return $this->matches( null === $order->customer_id ? null : Customer::query()->find( $order->customer_id ), $config );
    }

    /**
     * @since 1.0.0
     *
     * @param  Customer|null         $customer  Customer.
     * @param  array<string, mixed>  $config    Config.
     *
     * @return bool
     */
    protected function matches( ?Customer $customer, array $config ): bool
    {
        if ( null === $customer ) {
            return false;
        }

        $currency  = strtoupper( (string) ( $customer->total_spent_currency ?? $this->currencies->base() ) );
        $threshold = $this->converter->fromConfigured( $config['amount'] ?? null, $currency );

        if ( null === $threshold || $threshold->isNegative() ) {
            return false;
        }

        return ( new Money( (int) $customer->total_spent_amount, new Currency( $currency ) ) )->greaterThan( $threshold );
    }
}
