<?php

/**
 * SimpleProductType.
 *
 * The reference implementation of a physical, non-configured product:
 * one SKU, one price per currency, ships from inventory. Engine spec
 * §4.1, §5 registry entry `simple`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\ProductTypes;

/**
 * The `simple` product type.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SimpleProductType extends AbstractProductType
{
    /**
     * Registry key.
     *
     * @since 1.0.0
     */
    public const KEY = 'simple';

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return __( 'Simple product' );
    }

    /**
     * @since 1.0.0
     *
     * @return string|null
     */
    public function icon(): ?string
    {
        return 'hero-cube';
    }

    /**
     * @since 1.0.0
     *
     * @return bool
     */
    public function requiresFulfillment(): bool
    {
        return true;
    }
}
