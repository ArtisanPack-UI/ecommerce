<?php

/**
 * ContextAwareTaxProvider contract.
 *
 * Optional extension of {@see TaxProvider} for providers that honour the
 * full {@see TaxContext} produced by the `ap.ecommerce.tax.calculating`
 * filter — the destination, and the tax-inclusive pricing flag — rather
 * than re-reading store settings. {@see \ArtisanPackUI\Ecommerce\Services\TaxService}
 * calls {@see self::calculateWithContext()} when a provider implements it.
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

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\ValueObjects\TaxContext;
use ArtisanPackUI\Ecommerce\ValueObjects\TaxResult;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface ContextAwareTaxProvider extends TaxProvider
{
    /**
     * Calculates tax for `$cart` using every field of `$context`.
     *
     * @since 1.0.0
     *
     * @param  Cart        $cart     Cart being taxed.
     * @param  TaxContext  $context  Filtered calculation context.
     *
     * @return TaxResult
     */
    public function calculateWithContext( Cart $cart, TaxContext $context ): TaxResult;
}
