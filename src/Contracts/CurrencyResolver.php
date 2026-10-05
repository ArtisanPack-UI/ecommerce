<?php

/**
 * CurrencyResolver contract.
 *
 * Decides which of the store's enabled currencies a request should see
 * (engine issue #178). The default,
 * {@see \ArtisanPackUI\Ecommerce\Services\SessionCurrencyResolver}, reads the
 * shopper's choice from the session, cookie, or `X-Currency` header and falls
 * back to the base currency. Geo-IP or locale satellites bind their own, or
 * adjust the result through the `ap.ecommerce.currency.resolved` filter.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface CurrencyResolver
{
    /**
     * The ISO 4217 code `$request` should be priced in. MUST be one of the
     * store's enabled currencies.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Current request.
     *
     * @return string
     */
    public function resolve( Request $request ): string;
}
