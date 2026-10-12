<?php

/**
 * PriceDisplayResolver.
 *
 * Builds the {@see DisplayPrice} every storefront shows (#172). The price
 * comes from the product type (so bundles and grouped products price as
 * they do in the cart) and runs through `ap.ecommerce.pricing.priceDisplay`;
 * the compare-at price comes from the same price row. A variable product
 * shows its cheapest variant with the from/to range. Tax is worked out by
 * the active tax provider for one unit at the destination (default: the
 * store's country), so tax-inclusive and tax-exclusive stores both get
 * both amounts.
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

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Services\ProductPriceResolver;
use ArtisanPackUI\Ecommerce\Services\TaxService;
use ArtisanPackUI\Ecommerce\Support\TaxLabel;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use InvalidArgumentException;
use Money\Money;
use RuntimeException;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PriceDisplayResolver
{
    /**
     * @since 1.0.0
     *
     * @param  ProductPriceResolver  $prices  Price rows.
     * @param  TaxService            $taxes   Active tax provider.
     */
    public function __construct(
        private readonly ProductPriceResolver $prices,
        private readonly TaxService $taxes,
    ) {
    }

    /**
     * The display price of `$subject` in `$currency`, or null when it has
     * no price in that currency.
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant  $subject      Product or variant.
     * @param  string                  $currency     ISO 4217 code.
     * @param  Address|null            $destination  Where tax is worked out for (default: the store's country).
     * @param  Customer|null           $customer     Shopper, for the `priceDisplay` filter.
     *
     * @return DisplayPrice|null
     */
    public function for( Product|ProductVariant $subject, string $currency, ?Address $destination = null, ?Customer $customer = null ): ?DisplayPrice
    {
        $currency = strtoupper( $currency );
        $product  = $subject instanceof ProductVariant ? $subject->product : $subject;

        if ( null === $product ) {
            return null;
        }

        $variants = $subject instanceof Product ? self::variantsOf( $product ) : new EloquentCollection();
        $min      = null;
        $max      = null;

        if ( $variants->isNotEmpty() ) {
            $cheapest = null;

            foreach ( $variants as $variant ) {
                $price = $this->unitPrice( $product, $variant, $currency, $customer );

                if ( null === $price ) {
                    continue;
                }

                if ( null === $min || $price->lessThan( $min ) ) {
                    $min      = $price;
                    $cheapest = $variant;
                }

                $max = null === $max || $price->greaterThan( $max ) ? $price : $max;
            }

            if ( null === $cheapest ) {
                return null;
            }

            $price     = $min;
            $compareAt = $this->compareAt( $cheapest, $currency );
        } else {
            $price = $this->unitPrice( $product, $subject instanceof ProductVariant ? $subject : null, $currency, $customer );

            if ( null === $price ) {
                return null;
            }

            $compareAt = $this->compareAt( $subject, $currency );
        }

        [ $including, $excluding, $inclusive, $label ] = $this->withTax( $product, $subject instanceof ProductVariant ? $subject : null, $price, $destination );

        return new DisplayPrice(
            $price,
            null !== $compareAt && $compareAt->greaterThan( $price ) ? $compareAt : null,
            $min,
            $max,
            $including,
            $excluding,
            $inclusive,
            $label,
        );
    }

    /**
     * A product's variants in position order: the loaded relation when
     * there is one (`Product::withDisplayData()`), else a query.
     *
     * @since 1.0.0
     *
     * @param  Product  $product  Product.
     *
     * @return EloquentCollection<int, ProductVariant>
     */
    protected static function variantsOf( Product $product ): EloquentCollection
    {
        if ( ! $product->relationLoaded( 'variants' ) ) {
            return $product->variants()->orderBy( 'position' )->get();
        }

        return $product->variants->sortBy( [ [ 'position', 'asc' ], [ 'id', 'asc' ] ] )->values();
    }

    /**
     * One unit's price through the product type and the `priceDisplay`
     * filter.
     *
     * @since 1.0.0
     *
     * @param  Product              $product   Product.
     * @param  ProductVariant|null  $variant   Variant.
     * @param  string               $currency  Currency.
     * @param  Customer|null        $customer  Shopper.
     *
     * @return Money|null
     */
    protected function unitPrice( Product $product, ?ProductVariant $variant, string $currency, ?Customer $customer ): ?Money
    {
        if ( $product->typeIsMissing() ) {
            return null;
        }

        try {
            $price = $product->productType()->priceLine( $product, array_filter( [ 'variant_id' => $variant?->id ] ), 1, $currency );
        } catch ( RuntimeException | InvalidArgumentException ) {
            return null;
        }

        $filtered = applyFilters( 'ap.ecommerce.pricing.priceDisplay', $price, $variant ?? $product, $customer );

        return $filtered instanceof Money && $filtered->getCurrency()->equals( $price->getCurrency() ) && ! $filtered->isNegative() ? $filtered : $price;
    }

    /**
     * The compare-at price of `$priceable`'s current price row.
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant  $priceable  Priceable.
     * @param  string                  $currency   Currency.
     *
     * @return Money|null
     */
    protected function compareAt( Product|ProductVariant $priceable, string $currency ): ?Money
    {
        try {
            return $this->prices->resolveWithCompareAt( $priceable, $currency )['compare_at'] ?? null;
        } catch ( InvalidArgumentException ) {
            return null;
        }
    }

    /**
     * `$price` with and without tax at `$destination`, whether the store's
     * prices include tax, and the tax label.
     *
     * @since 1.0.0
     *
     * @param  Product              $product      Product.
     * @param  ProductVariant|null  $variant      Variant.
     * @param  Money                $price        Unit price.
     * @param  Address|null         $destination  Destination.
     *
     * @return array{0: Money, 1: Money, 2: bool, 3: string|null}
     */
    protected function withTax( Product $product, ?ProductVariant $variant, Money $price, ?Address $destination ): array
    {
        $inclusive   = (bool) config( 'artisanpack.ecommerce.tax.prices_include_tax', false );
        $destination ??= $this->storeAddress();

        if ( null === $destination ) {
            return [ $price, $price, $inclusive, null ];
        }

        $currency = $price->getCurrency()->getCode();
        $cart     = new Cart();
        $cart->forceFill( [ 'currency' => $currency, 'discount_amount' => 0, 'shipping_amount' => 0 ] );

        $line = new CartItem();
        $line->forceFill( [
            'id'                  => 1,
            'product_id'          => $product->id,
            'product_variant_id'  => $variant?->id,
            'quantity'            => 1,
            'unit_price_amount'   => (int) $price->getAmount(),
            'unit_price_currency' => $currency,
            'line_total_amount'   => (int) $price->getAmount(),
            'discount_amount'     => 0,
        ] );
        $line->setRelation( 'product', $product );
        $cart->setRelation( 'items', new EloquentCollection( [ $line ] ) );

        try {
            $result = $this->taxes->calculate( $cart, $destination );
        } catch ( Throwable ) {
            return [ $price, $price, $inclusive, null ];
        }

        $tax       = $result->perLine[1] ?? $result->total;
        $inclusive = $result->pricesIncludeTax;
        $label     = $tax->isPositive() ? ( $result->breakdown[0]['label'] ?? TaxLabel::for() ) : null;

        return $inclusive
            ? [ $price, $price->subtract( $tax ), true, $label ]
            : [ $price->add( $tax ), $price, false, $label ];
    }

    /**
     * The store's own country as a destination, if configured.
     *
     * @since 1.0.0
     *
     * @return Address|null
     */
    protected function storeAddress(): ?Address
    {
        $country = strtoupper( (string) config( 'artisanpack.ecommerce.store.country', '' ) );

        return 2 === strlen( $country ) ? Address::fromArray( [ 'country_code' => $country ] ) : null;
    }
}
