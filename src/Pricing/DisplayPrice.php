<?php

/**
 * DisplayPrice.
 *
 * What a storefront shows for a product or variant (#172): the price, the
 * compare-at ("was") price and whether it's on sale, the from/to range of
 * a variable product, and the price with and without tax at a destination
 * with the tax label.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Pricing;

use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class DisplayPrice
{
    /**
     * @since 1.0.0
     *
     * @param  Money        $price             The price as stored (the lowest, for a range).
     * @param  Money|null   $compareAt         The compare-at price, when higher than `$price`.
     * @param  Money|null   $minPrice          Lowest variant price (variable products).
     * @param  Money|null   $maxPrice          Highest variant price (variable products).
     * @param  Money        $priceIncludingTax `$price` with tax.
     * @param  Money        $priceExcludingTax `$price` without tax.
     * @param  bool         $pricesIncludeTax  Whether the store enters prices with tax.
     * @param  string|null  $taxLabel          Tax label (`VAT`, `Sales tax`), when tax applies.
     */
    public function __construct(
        public readonly Money $price,
        public readonly ?Money $compareAt,
        public readonly ?Money $minPrice,
        public readonly ?Money $maxPrice,
        public readonly Money $priceIncludingTax,
        public readonly Money $priceExcludingTax,
        public readonly bool $pricesIncludeTax,
        public readonly ?string $taxLabel,
    ) {
    }

    /**
     * Whether a compare-at price is shown.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function onSale(): bool
    {
        return null !== $this->compareAt && $this->compareAt->greaterThan( $this->price );
    }

    /**
     * Whether the variants cost different amounts ("From $19").
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isRange(): bool
    {
        return null !== $this->minPrice && null !== $this->maxPrice && ! $this->minPrice->equals( $this->maxPrice );
    }

    /**
     * The REST shape: amounts as `{ amount, currency }`.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $money = static fn ( ?Money $value ): ?array => null === $value ? null : [ 'amount' => (int) $value->getAmount(), 'currency' => $value->getCurrency()->getCode() ];

        return [
            'price'               => $money( $this->price ),
            'compare_at'          => $money( $this->compareAt ),
            'on_sale'             => $this->onSale(),
            'is_range'            => $this->isRange(),
            'min_price'           => $money( $this->minPrice ),
            'max_price'           => $money( $this->maxPrice ),
            'price_including_tax' => $money( $this->priceIncludingTax ),
            'price_excluding_tax' => $money( $this->priceExcludingTax ),
            'prices_include_tax'  => $this->pricesIncludeTax,
            'tax_label'           => $this->taxLabel,
        ];
    }
}
