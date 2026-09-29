<?php

/**
 * TaxResult value object.
 *
 * Normalized output of {@see \ArtisanPackUI\Ecommerce\Contracts\TaxProvider::calculate()}.
 * Shape per engine spec §4.5: `total`, a per-rate `breakdown`, and a
 * `perLine` map keyed by cart-item id. `shipping` carries the portion of
 * `total` levied on the cart's shipping charge.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\ValueObjects;

use Money\Currency;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class TaxResult
{
    /**
     * @since 1.0.0
     *
     * @param  Money                                                                                $total              Total tax owed (lines + shipping).
     * @param  array<int, array{label: string, rate_ubps: int, amount: Money, is_compound: bool}>  $breakdown          One entry per applied rate.
     * @param  array<int, Money>                                                                    $perLine            Tax per cart item, keyed by cart-item id.
     * @param  Money                                                                                $shipping           Tax levied on the shipping charge.
     * @param  bool                                                                                 $pricesIncludeTax   Whether amounts were back-calculated from tax-inclusive prices.
     */
    public function __construct(
        public readonly Money $total,
        public readonly array $breakdown,
        public readonly array $perLine,
        public readonly Money $shipping,
        public readonly bool $pricesIncludeTax = false,
    ) {
    }

    /**
     * An empty result in `$currency` — no rates matched.
     *
     * @since 1.0.0
     *
     * @param  string             $currency  ISO 4217 code.
     * @param  array<int, int>    $lineIds   Cart-item ids to zero-fill.
     * @param  bool               $pricesIncludeTax
     *
     * @return self
     */
    public static function zero( string $currency, array $lineIds = [], bool $pricesIncludeTax = false ): self
    {
        $zero = new Money( 0, new Currency( $currency ) );

        return new self(
            total: $zero,
            breakdown: [],
            perLine: array_fill_keys( $lineIds, $zero ),
            shipping: $zero,
            pricesIncludeTax: $pricesIncludeTax,
        );
    }

    /**
     * Serializes the result for JSON payloads.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total'              => (int) $this->total->getAmount(),
            'currency'           => $this->total->getCurrency()->getCode(),
            'shipping'           => (int) $this->shipping->getAmount(),
            'prices_include_tax' => $this->pricesIncludeTax,
            'breakdown'          => array_map( static fn ( array $row ): array => [
                'label'       => $row['label'],
                'rate_ubps'   => $row['rate_ubps'],
                'amount'      => (int) $row['amount']->getAmount(),
                'is_compound' => $row['is_compound'],
            ], $this->breakdown ),
            'per_line' => array_map( static fn ( Money $m ): int => (int) $m->getAmount(), $this->perLine ),
        ];
    }
}
