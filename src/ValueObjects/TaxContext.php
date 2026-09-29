<?php

/**
 * TaxContext value object.
 *
 * Carries the inputs of a tax calculation through the
 * `ap.ecommerce.tax.calculating` and `ap.ecommerce.tax.rates` filters so
 * listeners can change the destination, the pricing mode, or swap the
 * provider entirely (engine spec §6.5).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\ValueObjects;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class TaxContext
{
    /**
     * @since 1.0.0
     *
     * @param  Address      $destination       Destination address being taxed.
     * @param  string       $providerKey       Registry key of the provider that will run.
     * @param  bool         $pricesIncludeTax  Whether line prices are tax-inclusive.
     * @param  string|null  $taxClassKey       Tax class being resolved (set during rate resolution).
     */
    public function __construct(
        public readonly Address $destination,
        public readonly string $providerKey,
        public readonly bool $pricesIncludeTax,
        public readonly ?string $taxClassKey = null,
    ) {
    }

    /**
     * Returns a copy scoped to `$taxClassKey`.
     *
     * @since 1.0.0
     *
     * @param  string  $taxClassKey  Tax class key.
     *
     * @return self
     */
    public function forClass( string $taxClassKey ): self
    {
        return new self( $this->destination, $this->providerKey, $this->pricesIncludeTax, $taxClassKey );
    }
}
