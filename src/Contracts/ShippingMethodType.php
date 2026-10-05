<?php

/**
 * ShippingMethodType contract.
 *
 * A configurable shipping-method driver — the `key` stored on a
 * `shipping_methods` row. Each row supplies its own `config` JSON, so the
 * same driver (e.g. `flat-rate`) can back many methods across zones.
 * Core ships `flat-rate`, `free-shipping`, `local-pickup`,
 * `weight-based`, and `price-based`; satellites register more against
 * {@see \ArtisanPackUI\Ecommerce\Registries\ShippingMethodTypeRegistry}.
 *
 * Parent plan §5.10.
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
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface ShippingMethodType
{
    /**
     * Machine-readable driver key; matches the registry key.
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
     * Prices this method for `$cart`, or returns `null` when the method is
     * not available (e.g. free-shipping threshold not met, cart heavier
     * than the last weight tier). Amounts MUST be in the cart currency.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart         Cart being quoted.
     * @param  Address               $destination  Destination address.
     * @param  array<string, mixed>  $config       The method row's `config` JSON.
     *
     * @return Money|null
     */
    public function calculate( Cart $cart, Address $destination, array $config ): ?Money;
}
