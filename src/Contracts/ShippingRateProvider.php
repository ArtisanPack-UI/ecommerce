<?php

/**
 * ShippingRateProvider contract.
 *
 * Returns real-time shipping rates for a cart (Shippo, EasyPost, carrier
 * APIs). Satellites register implementations against
 * {@see \ArtisanPackUI\Ecommerce\Registries\ShippingRateProviderRegistry};
 * a store attaches one to a zone by adding a shipping method whose key is
 * `provider:{key}`. The core
 * {@see \ArtisanPackUI\Ecommerce\Shipping\ZoneShippingRateProvider} is the
 * reference implementation.
 *
 * Engine spec §4.3, parent plan §5.10.
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
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingRate;
use Illuminate\Support\Collection;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface ShippingRateProvider
{
    /**
     * Machine-readable provider key; matches the registry key.
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
     * Returns every rate available for `$cart` shipped to `$destination`.
     *
     * Implementations MUST return rates in the cart's currency, MUST NOT
     * return negative amounts, MUST return an empty collection for an
     * empty cart, and MUST NOT mutate the cart.
     *
     * @since 1.0.0
     *
     * @param  Cart     $cart         Cart being quoted.
     * @param  Address  $destination  Destination address.
     *
     * @return Collection<int, ShippingRate>
     */
    public function getRatesForCart( Cart $cart, Address $destination ): Collection;
}
