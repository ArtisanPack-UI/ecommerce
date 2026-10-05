<?php

/**
 * CurrencyIsCondition.
 *
 * `currency-is` (audit I3): the cart (or order) is in one of `currencies`.
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
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Services\StoreCurrencies;
use ArtisanPackUI\Ecommerce\Support\ConfigField;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CurrencyIsCondition implements OrderAwarePromotionCondition, DescribesConfig
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'currency-is';

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
        return __( 'Currency is' );
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
        $codes = app( StoreCurrencies::class )->enabled();

        return [
            ConfigField::make( 'currencies', 'multiselect', __( 'Currencies' ), [ 'required' => true, 'options' => ConfigField::options( array_combine( $codes, $codes ) ) ] ),
        ];
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart.
     * @param  array<string, mixed>  $config  `{ currencies: string[] }`.
     *
     * @return bool
     */
    public function evaluate( Cart $cart, array $config ): bool
    {
        return $this->matches( (string) $cart->currency, $config );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order                 $order   Placed order.
     * @param  array<string, mixed>  $config  `{ currencies: string[] }`.
     *
     * @return bool
     */
    public function evaluateOrder( Order $order, array $config ): bool
    {
        return $this->matches( (string) $order->currency, $config );
    }

    /**
     * @since 1.0.0
     *
     * @param  string                $currency  Currency.
     * @param  array<string, mixed>  $config    Config.
     *
     * @return bool
     */
    protected function matches( string $currency, array $config ): bool
    {
        $wanted = array_map( static fn ( string $code ): string => strtoupper( trim( $code ) ), array_filter( (array) ( $config['currencies'] ?? [] ), 'is_string' ) );

        return '' !== $currency && in_array( strtoupper( $currency ), $wanted, true );
    }
}
