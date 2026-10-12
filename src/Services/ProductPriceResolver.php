<?php

/**
 * ProductPriceResolver.
 *
 * Resolves the effective per-currency price of a priceable (Product or
 * ProductVariant) at a given time, falling back to the store's base currency
 * (converted via the active {@see \ArtisanPackUI\Ecommerce\Contracts\CurrencyRateProvider}) when no explicit
 * row exists in the requested currency.
 *
 * Engine spec §3.2 (schema), §4.7 (rate provider contract).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Registries\CurrencyRateProviderRegistry;
use ArtisanPackUI\Ecommerce\ValueObjects\Currency as CurrencyVO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Money\Currency as MoneyCurrency;
use Money\Money;

/**
 * ProductPriceResolver.
 *
 * Lookup order for `resolve($priceable, $currency, $at)`:
 *   1. Active `product_prices` row for the priceable in `$currency` at `$at`.
 *   2. Otherwise: active row in the store's base currency, converted via the
 *      active {@see \ArtisanPackUI\Ecommerce\Contracts\CurrencyRateProvider} using the E8 rate.
 *   3. Otherwise: `null`. The caller decides how to surface a missing price.
 *
 * Windowing rules (§3.2): the row whose `[starts_at, ends_at]` contains `$at`
 * wins; otherwise the row with both timestamps `null` (the base price) is
 * used; when both apply, the scheduled row takes precedence.
 *
 * A priceable whose `prices` relation is already loaded (e.g.
 * `Product::query()->withDisplayData()`) is resolved from it without a
 * query, so listings price every product in one eager load.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ProductPriceResolver
{
    /**
     * Constructs the resolver.
     *
     * @since 1.0.0
     *
     * @param  CurrencyRateProviderRegistry  $rateProviders  Registry of FX-rate providers.
     * @param  ConfigRepository              $config         Config repository (for `base_currency` + active provider key).
     */
    public function __construct(
        private readonly CurrencyRateProviderRegistry $rateProviders,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Returns the effective price for `$priceable` in `$currency` at `$at`.
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant  $priceable  Priceable row.
     * @param  string                  $currency   Target ISO 4217 code.
     * @param  Carbon|null             $at         Reference time (defaults to now).
     *
     * @throws InvalidArgumentException When `$currency` is not a valid ISO 4217 code.
     *
     * @return Money|null
     */
    public function resolve( Product|ProductVariant $priceable, string $currency, ?Carbon $at = null ): ?Money
    {
        return $this->resolveWithCompareAt( $priceable, $currency, $at )['price'] ?? null;
    }

    /**
     * Like {@see self::resolve()}, with the same row's `compare_at` price
     * (null when the row has none). Both come from one row, converted
     * together when the base-currency row is used (#172).
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant  $priceable  Priceable row.
     * @param  string                  $currency   Target ISO 4217 code.
     * @param  Carbon|null             $at         Reference time (defaults to now).
     *
     * @throws InvalidArgumentException When `$currency` is not a valid ISO 4217 code.
     *
     * @return array{price: Money, compare_at: Money|null}|null
     */
    public function resolveWithCompareAt( Product|ProductVariant $priceable, string $currency, ?Carbon $at = null ): ?array
    {
        $currencyCode = CurrencyVO::of( $currency )->code();
        $at ??= Carbon::now();

        $direct = $this->activeRowFor( $priceable, $currencyCode, $at );

        if ( null !== $direct ) {
            return $this->rowToPair( $direct, $currencyCode, $currencyCode );
        }

        /** @var string $baseCode */
        $baseCode = strtoupper( (string) $this->config->get( 'artisanpack.ecommerce.base_currency', 'USD' ) );

        if ( $baseCode === $currencyCode ) {
            return null;
        }

        $baseRow = $this->activeRowFor( $priceable, $baseCode, $at );

        return null === $baseRow ? null : $this->rowToPair( $baseRow, $baseCode, $currencyCode );
    }

    /**
     * A row's price and compare-at price in `$toCode`.
     *
     * @since 1.0.0
     *
     * @param  ProductPrice  $row       Row.
     * @param  string        $fromCode  The row's currency.
     * @param  string        $toCode    Target currency.
     *
     * @return array{price: Money, compare_at: Money|null}
     */
    private function rowToPair( ProductPrice $row, string $fromCode, string $toCode ): array
    {
        $price     = $this->rowToMoney( $row, $fromCode );
        $compareAt = null === $row->compare_at_amount ? null : new Money( (int) $row->compare_at_amount, new MoneyCurrency( $fromCode ) );

        if ( $fromCode !== $toCode ) {
            $price     = $this->convert( $price, $fromCode, $toCode );
            $compareAt = null === $compareAt ? null : $this->convert( $compareAt, $fromCode, $toCode );
        }

        return [ 'price' => $price, 'compare_at' => $compareAt ];
    }

    /**
     * Converts `$amount` from `$fromCode` to `$toCode` via the active FX rate
     * provider. Amounts are multiplied by the E8 rate and divided back down
     * by `10^8`; the divide step uses banker's rounding to match the money
     * ledger's aggregate arithmetic.
     *
     * @since 1.0.0
     *
     * @param  Money   $amount    Source amount.
     * @param  string  $fromCode  Source ISO 4217 code.
     * @param  string  $toCode    Target ISO 4217 code.
     *
     * @return Money
     */
    private function convert( Money $amount, string $fromCode, string $toCode ): Money
    {
        // One conversion implementation engine-wide — including the
        // minor-unit (subunit) scaling between currencies like USD and JPY.
        return app( CurrencyConverter::class )->convert( $amount, $toCode );
    }

    /**
     * Applies §3.2 precedence to find the active row for `$priceable` in
     * `$currency` at `$at`, from the loaded `prices` relation when there is
     * one.
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant  $priceable  Priceable row.
     * @param  string                  $currency   ISO 4217 code.
     * @param  Carbon                  $at         Reference time.
     *
     * @return ProductPrice|null
     */
    private function activeRowFor( Product|ProductVariant $priceable, string $currency, Carbon $at ): ?ProductPrice
    {
        $rows = $priceable->relationLoaded( 'prices' )
            ? self::sortRows( $priceable->prices->filter( static fn ( ProductPrice $row ): bool => $currency === strtoupper( (string) $row->currency ) ) )
            : ProductPrice::query()
                ->where( 'priceable_type', $priceable->getMorphClass() )
                ->where( 'priceable_id', $priceable->getKey() )
                ->where( 'currency', $currency )
                ->orderByRaw( '(starts_at IS NULL) ASC' )
                ->orderBy( 'starts_at', 'desc' )
                ->orderByRaw( '(ends_at IS NULL) ASC' )
                ->orderBy( 'ends_at', 'asc' )
                ->orderBy( 'id', 'desc' )
                ->get();

        $base      = null;
        $scheduled = null;

        foreach ( $rows as $row ) {
            if ( null === $row->starts_at && null === $row->ends_at ) {
                $base ??= $row;
                continue;
            }

            if ( null !== $scheduled ) {
                continue;
            }

            $starts = null === $row->starts_at || $row->starts_at->lessThanOrEqualTo( $at );
            $ends   = null === $row->ends_at || $row->ends_at->greaterThanOrEqualTo( $at );

            if ( $starts && $ends ) {
                $scheduled = $row;
            }
        }

        return $scheduled ?? $base;
    }

    /**
     * Loaded price rows in the order {@see self::activeRowFor()} queries
     * them: scheduled rows first by latest start, then soonest end, then
     * newest.
     *
     * @since 1.0.0
     *
     * @param  Collection<int, ProductPrice>  $rows  Rows in one currency.
     *
     * @return Collection<int, ProductPrice>
     */
    private static function sortRows( Collection $rows ): Collection
    {
        return $rows->sort( static fn ( ProductPrice $a, ProductPrice $b ): int => [
            null === $a->starts_at,
            $b->starts_at?->getTimestamp() ?? 0,
            null === $a->ends_at,
            $a->ends_at?->getTimestamp() ?? 0,
            (int) $b->id,
        ] <=> [
            null === $b->starts_at,
            $a->starts_at?->getTimestamp() ?? 0,
            null === $b->ends_at,
            $b->ends_at?->getTimestamp() ?? 0,
            (int) $a->id,
        ] )->values();
    }

    /**
     * Rehydrates a {@see ProductPrice} row into a {@see Money} using an
     * explicit currency (rather than the row's `currency` column) so the
     * caller can preserve the source-currency context on a fallback lookup.
     *
     * @since 1.0.0
     *
     * @param  ProductPrice  $row       Row to hydrate.
     * @param  string        $currency  ISO 4217 code to attach.
     *
     * @return Money
     */
    private function rowToMoney( ProductPrice $row, string $currency ): Money
    {
        return new Money(
            (int) $row->price_amount,
            new MoneyCurrency( $currency ),
        );
    }
}
