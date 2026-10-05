<?php

/**
 * StoreCurrencies.
 *
 * The currencies the store sells in (engine issue #178): the base currency
 * plus `artisanpack.ecommerce.currency.enabled` (also editable as the
 * `currency.enabled` setting). Storefronts list these in a currency
 * switcher; carts may only be priced in one of them.
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

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Money\Currencies\ISOCurrencies;
use Money\Currency;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class StoreCurrencies
{
    /**
     * @since 1.0.0
     *
     * @param  ConfigRepository  $config  Config.
     */
    public function __construct( protected ConfigRepository $config )
    {
    }

    /**
     * The store's base currency.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function base(): string
    {
        return strtoupper( (string) $this->config->get( 'artisanpack.ecommerce.base_currency', 'USD' ) );
    }

    /**
     * Every enabled currency, base first, uppercased, valid ISO 4217 codes
     * only, without duplicates.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function enabled(): array
    {
        $configured = $this->config->get( 'artisanpack.ecommerce.currency.enabled', [] );
        $configured = is_array( $configured ) ? $configured : explode( ',', (string) $configured );
        $iso        = new ISOCurrencies();

        $codes = array_map( static fn ( mixed $code ): string => strtoupper( trim( (string) $code ) ), [ $this->base(), ...$configured ] );
        $codes = array_filter( $codes, static fn ( string $code ): bool => 3 === strlen( $code ) && $iso->contains( new Currency( $code ) ) );

        return array_values( array_unique( $codes ) );
    }

    /**
     * Whether `$code` is one of the enabled currencies.
     *
     * @since 1.0.0
     *
     * @param  string  $code  ISO 4217 code.
     *
     * @return bool
     */
    public function isEnabled( string $code ): bool
    {
        return in_array( strtoupper( trim( $code ) ), $this->enabled(), true );
    }
}
