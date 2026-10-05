<?php

/**
 * RefreshFxRatesCommand.
 *
 * `ecommerce:refresh-fx-rates` (engine spec §11.6, issue #178): fetches the
 * rate from the base currency to every other enabled currency through the
 * active {@see CurrencyRateProvider}, so storefront prices are converted at
 * a fresh, cached rate. Providers that implement
 * {@see RefreshableRateProvider} fetch anew; others are simply warmed.
 * Scheduled daily.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Console\Commands;

use ArtisanPackUI\Ecommerce\Contracts\CurrencyRateProvider;
use ArtisanPackUI\Ecommerce\Contracts\RefreshableRateProvider;
use ArtisanPackUI\Ecommerce\Registries\CurrencyRateProviderRegistry;
use ArtisanPackUI\Ecommerce\Services\StoreCurrencies;
use Illuminate\Console\Command;
use Money\Currency;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class RefreshFxRatesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ecommerce:refresh-fx-rates
        {--currency=* : Only refresh these currencies (default: every enabled currency).}';

    /**
     * @var string
     */
    protected $description = 'Fetch and cache exchange rates from the base currency to each enabled currency.';

    /**
     * @since 1.0.0
     *
     * @param  StoreCurrencies               $currencies  Enabled currencies.
     * @param  CurrencyRateProviderRegistry  $providers   Rate providers.
     *
     * @return int
     */
    public function handle( StoreCurrencies $currencies, CurrencyRateProviderRegistry $providers ): int
    {
        /** @var CurrencyRateProvider $provider */
        $provider = $providers->get( (string) config( 'artisanpack.ecommerce.currency.provider', 'config' ) );
        $base     = $currencies->base();
        $only     = array_map( 'strtoupper', (array) $this->option( 'currency' ) );
        $targets  = array_values( array_filter(
            $currencies->enabled(),
            static fn ( string $code ): bool => $code !== $base && ( [] === $only || in_array( $code, $only, true ) ),
        ) );

        if ( [] === $targets ) {
            $this->info( 'No other currencies are enabled; nothing to refresh.' );

            return self::SUCCESS;
        }

        $rows   = [];
        $failed = 0;

        foreach ( $targets as $code ) {
            try {
                $rate = $provider instanceof RefreshableRateProvider
                    ? $provider->refreshRateE8( new Currency( $base ), new Currency( $code ) )
                    : $provider->getRateE8( new Currency( $base ), new Currency( $code ) );

                $rows[] = [ "{$base} → {$code}", bcdiv( (string) $rate, '100000000', 8 ), 'ok' ];
            } catch ( Throwable $exception ) {
                $failed++;
                $rows[] = [ "{$base} → {$code}", '-', $exception->getMessage() ];
            }
        }

        $this->table( [ 'Pair', 'Rate', 'Result' ], $rows );

        if ( $failed > 0 ) {
            $this->error( sprintf( '%d of %d rate(s) could not be refreshed through "%s".', $failed, count( $targets ), $provider->key() ) );

            return self::FAILURE;
        }

        $this->info( sprintf( 'Refreshed %d rate(s) through "%s".', count( $targets ), $provider->key() ) );

        return self::SUCCESS;
    }
}
