<?php

/**
 * SessionCurrencyResolver.
 *
 * The default {@see CurrencyResolver}: the shopper's choice from the session
 * (`currency.session_key`), else the `currency.cookie` cookie, else the
 * `X-Currency` header (for API clients), else the base currency. A choice
 * that isn't an enabled currency is ignored. The answer then runs through
 * the `ap.ecommerce.currency.resolved` filter `(string $currency, Request
 * $request)`; a filter returning a currency that isn't enabled is ignored.
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

use ArtisanPackUI\Ecommerce\Contracts\CurrencyResolver;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SessionCurrencyResolver implements CurrencyResolver
{
    /**
     * @since 1.0.0
     *
     * @param  StoreCurrencies   $currencies  Enabled currencies.
     * @param  ConfigRepository  $config      Config.
     */
    public function __construct(
        protected StoreCurrencies $currencies,
        protected ConfigRepository $config,
    ) {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Current request.
     *
     * @return string
     */
    public function resolve( Request $request ): string
    {
        $chosen = null;

        foreach ( $this->candidates( $request ) as $candidate ) {
            if ( is_string( $candidate ) && $this->currencies->isEnabled( $candidate ) ) {
                $chosen = strtoupper( trim( $candidate ) );

                break;
            }
        }

        $chosen ??= $this->currencies->base();
        $filtered = applyFilters( 'ap.ecommerce.currency.resolved', $chosen, $request );

        return is_string( $filtered ) && $this->currencies->isEnabled( $filtered ) ? strtoupper( trim( $filtered ) ) : $chosen;
    }

    /**
     * Remembers the shopper's choice in the session (when the request has
     * one). Storefronts without sessions set the cookie themselves.
     *
     * @since 1.0.0
     *
     * @param  Request  $request   Current request.
     * @param  string   $currency  Chosen currency.
     *
     * @return string The stored currency, or the base currency when `$currency` isn't enabled.
     */
    public function remember( Request $request, string $currency ): string
    {
        $currency = $this->currencies->isEnabled( $currency ) ? strtoupper( trim( $currency ) ) : $this->currencies->base();

        if ( $request->hasSession() ) {
            $request->session()->put( $this->sessionKey(), $currency );
        }

        return $currency;
    }

    /**
     * Where a choice may come from, in order.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Current request.
     *
     * @return array<int, mixed>
     */
    protected function candidates( Request $request ): array
    {
        return [
            $request->hasSession() ? $request->session()->get( $this->sessionKey() ) : null,
            $request->cookie( (string) $this->config->get( 'artisanpack.ecommerce.currency.cookie', 'ecommerce_currency' ) ),
            $request->header( 'X-Currency' ),
        ];
    }

    /**
     * Session key holding the choice.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function sessionKey(): string
    {
        return (string) $this->config->get( 'artisanpack.ecommerce.currency.session_key', 'ecommerce.currency' );
    }
}
