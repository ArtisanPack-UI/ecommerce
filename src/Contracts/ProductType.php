<?php

/**
 * ProductType contract.
 *
 * Every product kind — simple, digital, variable, subscription, bundle, gift
 * card, license, etc. — plugs into the engine as an implementation of this
 * contract, registered against the {@see \ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry}
 * under a unique `type` key.
 *
 * Engine spec §4.1.
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

use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use Money\Money;

/**
 * ProductType contract.
 *
 * Implementations declare a stable registry key ({@see self::key()}), a
 * user-facing label + icon, and how the engine should validate, price,
 * fulfill, and snapshot lines of this product type.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface ProductType
{
    /**
     * Stable registry key (e.g. `simple`, `digital`, `subscription`).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string;

    /**
     * User-facing label (already translated by the implementation).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string;

    /**
     * Optional icon registry key surfaced in the admin UI.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function icon(): ?string;

    /**
     * Validates and sanitises the payload used to add a product of this
     * type to a cart. The returned array is persisted verbatim on
     * `cart_items.options`, so implementations MUST strip anything that
     * should not round-trip.
     *
     * @since 1.0.0
     *
     * @param  Product              $product  Product being added.
     * @param  array<string, mixed> $options  Raw request options.
     *
     * @return array<string, mixed>
     */
    public function validateCartOptions( Product $product, array $options ): array;

    /**
     * Prices a single cart line of this product type.
     *
     * @since 1.0.0
     *
     * @param  Product              $product   Product being priced.
     * @param  array<string, mixed> $options   Sanitised cart-line options from {@see self::validateCartOptions()}.
     * @param  int                  $quantity  Number of units in this line.
     * @param  string               $currency  Cart currency (ISO 4217).
     *
     * @return Money The line total (already multiplied by quantity).
     */
    public function priceLine( Product $product, array $options, int $quantity, string $currency ): Money;

    /**
     * Whether the type is fulfillment-relevant (produces a physical shipment).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function requiresFulfillment(): bool;

    /**
     * Whether the type is inventory-tracked at all.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isInventoryTracked(): bool;

    /**
     * Builds the `product_snapshot` JSON persisted on `order_items` so
     * historical orders survive product edits and deletions.
     *
     * @since 1.0.0
     *
     * @param  CartItem  $item  Cart line being snapshotted at placement.
     *
     * @return array<string, mixed>
     */
    public function buildOrderSnapshot( CartItem $item ): array;

    /**
     * Post-placement side-effects. Fired once per line inside the order
     * placement transaction (e.g. digital: issue download tokens; license:
     * mint keys; simple: no-op).
     *
     * @since 1.0.0
     *
     * @param  Order      $order      Placed order.
     * @param  OrderItem  $orderItem  Line to run side-effects for.
     *
     * @return void
     */
    public function onOrderPlaced( Order $order, OrderItem $orderItem ): void;
}
