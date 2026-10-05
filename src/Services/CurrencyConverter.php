<?php

/**
 * CurrencyConverter.
 *
 * Converts a {@see Money} amount into another currency through the active
 * {@see CurrencyRateProvider} (`artisanpack.ecommerce.currency.provider`).
 * Uses the same E8 multiply / divide arithmetic as
 * {@see ProductPriceResolver} so configured amounts (shipping rates,
 * promotion thresholds) convert identically to catalog prices.
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

use ArtisanPackUI\Ecommerce\Contracts\CurrencyRateProvider;
use ArtisanPackUI\Ecommerce\Registries\CurrencyRateProviderRegistry;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CurrencyConverter
{
    /**
     * @since 1.0.0
     *
     * @param  CurrencyRateProviderRegistry  $rateProviders  Registry of FX-rate providers.
     * @param  ConfigRepository              $config         Config repository.
     */
    public function __construct(
        private readonly CurrencyRateProviderRegistry $rateProviders,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * The store's base currency code.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function baseCurrency(): string
    {
        return strtoupper( (string) $this->config->get( 'artisanpack.ecommerce.base_currency', 'USD' ) );
    }

    /**
     * Converts `$amount` into `$toCode`. Returns `$amount` untouched when
     * the currencies already match.
     *
     * @since 1.0.0
     *
     * @param  Money   $amount  Source amount.
     * @param  string  $toCode  Target ISO 4217 code.
     *
     * @return Money
     */
    public function convert( Money $amount, string $toCode ): Money
    {
        $toCode   = strtoupper( $toCode );
        $fromCode = $amount->getCurrency()->getCode();

        if ( $fromCode === $toCode ) {
            return $amount;
        }

        /** @var CurrencyRateProvider $provider */
        $provider = $this->rateProviders->get(
            (string) $this->config->get( 'artisanpack.ecommerce.currency.provider', 'config' ),
        );

        $rateE8 = $provider->getRateE8( new Currency( $fromCode ), new Currency( $toCode ) );

        // Rates are major-unit to major-unit; amounts are minor units. Fold
        // the subunit difference into the divisor so USD 500 (¢) → JPY at
        // 150 is ¥750, not ¥75,000 — and KWD's three decimals scale the
        // other way. Banker's-style HALF_UP on the single divide step.
        $isoCurrencies = new ISOCurrencies();
        $subunitShift  = $isoCurrencies->subunitFor( new Currency( $toCode ) ) - $isoCurrencies->subunitFor( new Currency( $fromCode ) );
        $converted     = $amount->multiply( (string) $rateE8 )->divide( bcpow( '10', (string) ( 8 - $subunitShift ) ) );

        return new Money( $converted->getAmount(), new Currency( $toCode ) );
    }

    /**
     * Resolves a configured amount into `$currency`.
     *
     * Configured amounts are either an integer in the store base currency,
     * or a map of explicit per-currency amounts (`{"USD": 500, "EUR": 450}`).
     * An explicit entry for `$currency` wins; otherwise the base-currency
     * entry (or the scalar) is converted.
     *
     * @since 1.0.0
     *
     * @param  mixed   $configured  Integer minor units or a currency → minor-units map.
     * @param  string  $currency    Target ISO 4217 code.
     *
     * @return Money|null Null when `$configured` is missing or unusable.
     */
    public function fromConfigured( mixed $configured, string $currency ): ?Money
    {
        $currency = strtoupper( $currency );

        if ( is_array( $configured ) ) {
            $map = array_change_key_case( $configured, CASE_UPPER );

            if ( isset( $map[ $currency ] ) && is_numeric( $map[ $currency ] ) ) {
                return new Money( (int) $map[ $currency ], new Currency( $currency ) );
            }

            $base = $this->baseCurrency();

            if ( ! isset( $map[ $base ] ) || ! is_numeric( $map[ $base ] ) ) {
                return null;
            }

            return $this->convert( new Money( (int) $map[ $base ], new Currency( $base ) ), $currency );
        }

        if ( ! is_numeric( $configured ) ) {
            return null;
        }

        return $this->convert( new Money( (int) $configured, new Currency( $this->baseCurrency() ) ), $currency );
    }
}
