<?php

/**
 * MinSubtotalCondition.
 *
 * `min-subtotal`: passes when the sum of line totals is at least `amount`
 * (base-currency int or `{CUR: int}` map, converted like shipping amounts).
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
use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Services\CurrencyConverter;
use ArtisanPackUI\Ecommerce\Support\ConfigField;
use Money\Currency;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class MinSubtotalCondition implements PromotionCondition, DescribesConfig
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'min-subtotal';

    /**
     * @since 1.0.0
     *
     * @param  CurrencyConverter  $converter  Converts the configured threshold.
     */
    public function __construct( private readonly CurrencyConverter $converter )
    {
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
        return __( 'Minimum subtotal' );
    }

    /**
     * Fields this condition's `config` takes (engine issue #149).
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [
            ConfigField::make( 'amount', 'money', __( 'Minimum subtotal' ), [ 'required' => true ] ),
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
        $threshold = $this->converter->fromConfigured( $config['amount'] ?? null, (string) $cart->currency );

        if ( null === $threshold ) {
            return false;
        }

        $items    = $cart->relationLoaded( 'items' ) ? $cart->items : $cart->items()->get();
        $subtotal = new Money( (int) $items->sum( 'line_total_amount' ), new Currency( strtoupper( (string) $cart->currency ) ) );

        return $items->isNotEmpty() && $subtotal->greaterThanOrEqual( $threshold );
    }
}
