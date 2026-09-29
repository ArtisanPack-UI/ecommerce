<?php

/**
 * AbstractPromotionAction.
 *
 * Shared helpers for the core {@see PromotionAction} implementations:
 * percentage parsing (bcmath, never floats), configured-amount resolution
 * in the cart currency, and product / variant line matching.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Promotions\Actions;

use ArtisanPackUI\Ecommerce\Contracts\PromotionAction;
use ArtisanPackUI\Ecommerce\Services\CurrencyConverter;
use ArtisanPackUI\Ecommerce\Support\DiscountLedger;
use Illuminate\Support\Collection;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class AbstractPromotionAction implements PromotionAction
{
    /**
     * @since 1.0.0
     *
     * @param  CurrencyConverter  $converter  Converts configured amounts into the cart currency.
     */
    public function __construct( protected readonly CurrencyConverter $converter )
    {
    }

    /**
     * Parses a 0–100 percentage into a fractional decimal string, or null
     * when it is missing, non-numeric, or out of range.
     *
     * @since 1.0.0
     *
     * @param  mixed  $percent  Configured percentage (`10`, `"12.5"`).
     *
     * @return string|null
     */
    protected function fraction( mixed $percent ): ?string
    {
        if ( ! is_numeric( $percent ) || str_contains( strtolower( (string) $percent ), 'e' ) ) {
            return null;
        }

        $percent = (string) $percent;

        if ( bccomp( $percent, '0', 6 ) <= 0 || bccomp( $percent, '100', 6 ) > 0 ) {
            return null;
        }

        return bcdiv( $percent, '100', 12 );
    }

    /**
     * Configured amount in the ledger (cart) currency.
     *
     * @since 1.0.0
     *
     * @param  mixed           $configured  Base-currency int or currency map.
     * @param  DiscountLedger  $ledger      Ledger (supplies the currency).
     *
     * @return Money|null
     */
    protected function amount( mixed $configured, DiscountLedger $ledger ): ?Money
    {
        $money = $this->converter->fromConfigured( $configured, $ledger->currency()->getCode() );

        return ( null === $money || ! $money->isPositive() ) ? null : $money;
    }

    /**
     * Ledger lines matching the configured product / variant ids.
     *
     * @since 1.0.0
     *
     * @param  DiscountLedger       $ledger      Ledger.
     * @param  array<string, mixed> $config      Action config.
     * @param  string               $productKey  Config key holding product ids.
     * @param  string               $variantKey  Config key holding variant ids.
     *
     * @return Collection<int, array{id: int, product_id: int, variant_id: int|null, quantity: int, unit_price: Money, total: Money}>
     */
    protected function matchingLines( DiscountLedger $ledger, array $config, string $productKey = 'product_ids', string $variantKey = 'variant_ids' ): Collection
    {
        $productIds = array_map( 'intval', array_filter( (array) ( $config[ $productKey ] ?? [] ), 'is_numeric' ) );
        $variantIds = array_map( 'intval', array_filter( (array) ( $config[ $variantKey ] ?? [] ), 'is_numeric' ) );

        return $ledger->lines()->filter(
            static fn ( array $line ): bool => in_array( $line['product_id'], $productIds, true )
                || ( null !== $line['variant_id'] && in_array( $line['variant_id'], $variantIds, true ) ),
        );
    }
}
