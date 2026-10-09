<?php

/**
 * ManualTaxProvider.
 *
 * Reference {@see \ArtisanPackUI\Ecommerce\Contracts\TaxProvider} backed by the `tax_rates` table. Deliberately
 * not a full tax engine — it covers stores with a handful of jurisdictions.
 * Stores with real nexus complexity swap in a satellite provider.
 *
 * Rate resolution per tax class + destination:
 *
 * 1. Active rates for the class whose country / region / postal pattern
 *    match the destination.
 * 2. Within each `priority` level only the most specific match survives
 *    (postal > region > country; lowest id breaks ties), so a county rate
 *    and a state rate at the same priority don't double-tax.
 * 3. Non-compound rates are all levied on the line base; compound rates
 *    are then levied, in priority order, on base + every tax so far.
 *
 * Tax-inclusive stores (`artisanpack.ecommerce.tax.prices_include_tax`)
 * back-calculate: net = gross ÷ ((1 + Σ non-compound) × Π(1 + compound)),
 * tax = gross − net, then each rate's share is computed on net with the
 * rounding residual assigned to the largest share so the breakdown always
 * sums to the total. Every step uses bcmath decimal strings; no floats.
 *
 * Engine spec §4.5 / §6.5, parent plan §5.11.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Tax;

use ArtisanPackUI\Ecommerce\Contracts\ContextAwareTaxProvider;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\TaxClass;
use ArtisanPackUI\Ecommerce\Models\TaxRate;
use ArtisanPackUI\Ecommerce\Support\TaxRateMath;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\TaxContext;
use ArtisanPackUI\Ecommerce\ValueObjects\TaxResult;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Money\Currency;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ManualTaxProvider implements ContextAwareTaxProvider
{
    /**
     * Registry key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'manual';

    /**
     * @since 1.0.0
     *
     * @param  ConfigRepository  $config  Config repository (pricing mode + default classes).
     */
    public function __construct( private readonly ConfigRepository $config )
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
        return __( 'Manual tax rates' );
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart     $cart         Cart being taxed.
     * @param  Address  $destination  Destination address.
     *
     * @return TaxResult
     */
    public function calculate( Cart $cart, Address $destination ): TaxResult
    {
        return $this->calculateWithContext( $cart, new TaxContext(
            $destination,
            self::KEY,
            (bool) $this->config->get( 'artisanpack.ecommerce.tax.prices_include_tax', false ),
        ) );
    }

    /**
     * Calculates tax honouring the context's destination and pricing mode.
     *
     * @since 1.0.0
     *
     * @param  Cart        $cart     Cart being taxed.
     * @param  TaxContext  $context  Calculation context.
     *
     * @return TaxResult
     */
    public function calculateWithContext( Cart $cart, TaxContext $context ): TaxResult
    {
        $currency    = new Currency( strtoupper( (string) $cart->currency ) );
        $inclusive   = $context->pricesIncludeTax;
        $destination = $context->destination;

        $items = $cart->relationLoaded( 'items' ) ? $cart->items : $cart->items()->get();
        $items->loadMissing( 'product' );

        $zero      = new Money( 0, $currency );
        $bases     = $this->lineBases( $cart, $items->all(), $currency, $context->lineDiscounts );
        $rateCache = [];
        $perLine   = [];
        $breakdown = [];

        foreach ( $items as $item ) {
            $perLine[ $item->id ] = $zero;
            $product              = $item->product;

            if ( null === $product || ! $product->is_taxable ) {
                continue;
            }

            $classKey = $product->tax_class_key ?: $this->defaultClass();
            $rates    = $rateCache[ $classKey ] ??= $this->resolveRates( $context->forClass( $classKey ) );

            [ $lineTax, $shares ] = $this->taxFor( $bases[ $item->id ], $rates, $inclusive );

            $perLine[ $item->id ] = $lineTax;
            $this->accumulate( $breakdown, $rates, $shares );
        }

        $shippingTax = $zero;
        $shipping    = new Money( (int) $cart->shipping_amount, $currency );

        if ( $shipping->isPositive() ) {
            $shippingClass = (string) $this->config->get( 'artisanpack.ecommerce.tax.shipping_tax_class', TaxClass::DEFAULT_KEY );
            $rates         = array_values( array_filter(
                $rateCache[ $shippingClass ] ??= $this->resolveRates( $context->forClass( $shippingClass ) ),
                static fn ( TaxRate $rate ): bool => $rate->is_shipping_taxable,
            ) );

            [ $shippingTax, $shares ] = $this->taxFor( $shipping, $rates, $inclusive );
            $this->accumulate( $breakdown, $rates, $shares );
        }

        $total = array_reduce( $perLine, static fn ( Money $carry, Money $line ): Money => $carry->add( $line ), $shippingTax );

        return new TaxResult(
            total: $total,
            breakdown: array_values( $breakdown ),
            perLine: $perLine,
            shipping: $shippingTax,
            pricesIncludeTax: $inclusive,
        );
    }

    /**
     * Resolves the ordered list of rates that apply for `$context`'s class
     * and destination. Filterable via `ap.ecommerce.tax.rates`.
     *
     * @since 1.0.0
     *
     * @param  TaxContext  $context  Context scoped to one tax class.
     *
     * @return array<int, TaxRate>
     */
    public function resolveRates( TaxContext $context ): array
    {
        $candidates = app( TaxRateCandidates::class )
            ->for( $context->taxClassKey, $context->destination->countryCode )
            ->filter( static fn ( TaxRate $rate ): bool => $rate->matches( $context->destination ) );

        $byPriority = [];

        foreach ( $candidates as $rate ) {
            $current = $byPriority[ $rate->priority ] ?? null;

            if ( null === $current || $rate->specificity() > $current->specificity() ) {
                $byPriority[ $rate->priority ] = $rate;
            }
        }

        ksort( $byPriority );

        $rates = (array) applyFilters( 'ap.ecommerce.tax.rates', array_values( $byPriority ), $context );

        return array_values( array_filter( $rates, static fn ( mixed $rate ): bool => $rate instanceof TaxRate ) );
    }

    /**
     * The taxable base of each line: its total less its share of the
     * discount. Discounts a promotion gave a specific line (`$lineDiscounts`,
     * from the promotion ledger) come off that line only; whatever cart-level
     * discount is left is spread across the lines in proportion to what they
     * still cost. Without per-line discounts the whole cart discount is
     * spread that way.
     *
     * @since 1.0.0
     *
     * @param  Cart             $cart           Cart.
     * @param  array<int, CartItem>  $items     Lines.
     * @param  Currency         $currency       Cart currency.
     * @param  array<int, int>  $lineDiscounts  Discount per line id.
     *
     * @return array<int, Money>
     */
    protected function lineBases( Cart $cart, array $items, Currency $currency, array $lineDiscounts = [] ): array
    {
        $bases = [];

        foreach ( $items as $item ) {
            $own                = min( max( 0, (int) ( $lineDiscounts[ $item->id ] ?? 0 ) ), max( 0, (int) $item->line_total_amount ) );
            $bases[ $item->id ] = new Money( max( 0, (int) $item->line_total_amount ) - $own, $currency );
        }

        $sum       = array_sum( array_map( static fn ( Money $m ): int => (int) $m->getAmount(), $bases ) );
        $remainder = max( 0, (int) $cart->discount_amount - array_sum( array_map( static fn ( mixed $amount ): int => max( 0, (int) $amount ), $lineDiscounts ) ) );
        $remainder = min( $remainder, $sum );

        if ( 0 === $remainder || 0 === $sum ) {
            return $bases;
        }

        $shares = ( new Money( $remainder, $currency ) )->allocate(
            array_map( static fn ( Money $m ): int => (int) $m->getAmount(), $bases ),
        );

        // Money::allocate() preserves the ratio array's keys (cart-item ids).
        foreach ( $shares as $id => $share ) {
            $bases[ $id ] = $bases[ $id ]->subtract( $share );
        }

        return $bases;
    }

    /**
     * Calculates tax on `$amount` for an ordered rate list.
     *
     * @since 1.0.0
     *
     * @param  Money                $amount     Line base (net when exclusive, gross when inclusive).
     * @param  array<int, TaxRate>  $rates      Ordered applicable rates.
     * @param  bool                 $inclusive  Whether `$amount` already includes tax.
     *
     * @return array{0: Money, 1: array<int, Money>}  Total tax and per-rate shares (same order as `$rates`).
     */
    protected function taxFor( Money $amount, array $rates, bool $inclusive ): array
    {
        $zero = new Money( 0, $amount->getCurrency() );

        if ( [] === $rates || $amount->isZero() ) {
            return [ $zero, array_fill( 0, count( $rates ), $zero ) ];
        }

        if ( ! $inclusive ) {
            $shares = $this->exclusiveShares( $amount, $rates );

            return [ Money::sum( $zero, ...$shares ), $shares ];
        }

        $nonCompound = '0';
        $compound    = '1';

        foreach ( $rates as $rate ) {
            if ( $rate->is_compound ) {
                $compound = bcmul( $compound, TaxRateMath::onePlus( $rate->rate_ubps ), TaxRateMath::SCALE );
            } else {
                $nonCompound = bcadd( $nonCompound, TaxRateMath::toFraction( $rate->rate_ubps ), TaxRateMath::SCALE );
            }
        }

        $divisor = bcmul( bcadd( '1', $nonCompound, TaxRateMath::SCALE ), $compound, TaxRateMath::SCALE );
        $net     = $amount->divide( $divisor );
        $tax     = $amount->subtract( $net );
        $shares  = $this->exclusiveShares( $net, $rates );

        return [ $tax, $this->reconcile( $shares, $tax ) ];
    }

    /**
     * Per-rate tax on a net amount, compounding where flagged.
     *
     * @since 1.0.0
     *
     * @param  Money                $net    Net (pre-tax) amount.
     * @param  array<int, TaxRate>  $rates  Ordered applicable rates.
     *
     * @return array<int, Money>
     */
    protected function exclusiveShares( Money $net, array $rates ): array
    {
        $shares = [];
        $base   = $net;

        foreach ( $rates as $index => $rate ) {
            if ( ! $rate->is_compound ) {
                $shares[ $index ] = $net->multiply( TaxRateMath::toFraction( $rate->rate_ubps ) );
                $base             = $base->add( $shares[ $index ] );
            }
        }

        foreach ( $rates as $index => $rate ) {
            if ( $rate->is_compound ) {
                $shares[ $index ] = $base->multiply( TaxRateMath::toFraction( $rate->rate_ubps ) );
                $base             = $base->add( $shares[ $index ] );
            }
        }

        ksort( $shares );

        return $shares;
    }

    /**
     * Pushes the rounding residual between `Σ $shares` and `$total` onto
     * the largest share so an inclusive breakdown sums exactly.
     *
     * @since 1.0.0
     *
     * @param  array<int, Money>  $shares  Per-rate shares.
     * @param  Money              $total   Exact tax total.
     *
     * @return array<int, Money>
     */
    protected function reconcile( array $shares, Money $total ): array
    {
        $sum      = Money::sum( new Money( 0, $total->getCurrency() ), ...$shares );
        $residual = $total->subtract( $sum );

        if ( $residual->isZero() || [] === $shares ) {
            return $shares;
        }

        $largest = array_keys( $shares, Money::max( ...$shares ) )[0] ?? array_key_first( $shares );

        $shares[ $largest ] = $shares[ $largest ]->add( $residual );

        return $shares;
    }

    /**
     * Adds per-rate shares into the running breakdown keyed by rate id.
     *
     * @since 1.0.0
     *
     * @param  array<int, array{label: string, rate_ubps: int, amount: Money, is_compound: bool}>  $breakdown  Running breakdown (by reference).
     * @param  array<int, TaxRate>                                                                  $rates      Applied rates.
     * @param  array<int, Money>                                                                    $shares     Shares in `$rates` order.
     *
     * @return void
     */
    protected function accumulate( array &$breakdown, array $rates, array $shares ): void
    {
        foreach ( $rates as $index => $rate ) {
            $share = $shares[ $index ];

            if ( isset( $breakdown[ $rate->id ] ) ) {
                $breakdown[ $rate->id ]['amount'] = $breakdown[ $rate->id ]['amount']->add( $share );

                continue;
            }

            $breakdown[ $rate->id ] = [
                'label'       => $rate->label,
                'rate_ubps'   => $rate->rate_ubps,
                'amount'      => $share,
                'is_compound' => $rate->is_compound,
            ];
        }
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    protected function defaultClass(): string
    {
        return (string) $this->config->get( 'artisanpack.ecommerce.tax.default_class', TaxClass::DEFAULT_KEY );
    }
}
