<?php

/**
 * TaxProvider contract.
 *
 * Calculates the tax owed on a cart for a destination address. The core
 * engine ships {@see \ArtisanPackUI\Ecommerce\Tax\ManualTaxProvider}
 * (reads `tax_rates`); satellites (`ecommerce-tax-stripe`,
 * `ecommerce-tax-taxjar`, `ecommerce-tax-avalara`) register their own
 * against {@see \ArtisanPackUI\Ecommerce\Registries\TaxProviderRegistry}.
 * Exactly one provider is active at a time — the key in
 * `artisanpack.ecommerce.tax.provider`.
 *
 * Engine spec §4.5, parent plan §5.11.
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
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\TaxResult;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface TaxProvider
{
    /**
     * Machine-readable provider key (e.g. `manual`, `stripe-tax`). Matches
     * the key the provider is registered under.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string;

    /**
     * Human-readable label for admin settings.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string;

    /**
     * Calculates tax for `$cart` shipped to `$destination`.
     *
     * Implementations MUST return amounts in the cart's currency, MUST
     * return one `perLine` entry per cart item (zero for non-taxable
     * lines), and MUST NOT mutate the cart.
     *
     * @since 1.0.0
     *
     * @param  Cart     $cart         Cart being taxed.
     * @param  Address  $destination  Destination (shipping) address.
     *
     * @return TaxResult
     */
    public function calculate( Cart $cart, Address $destination ): TaxResult;
}
