<?php

/**
 * MissingProductType.
 *
 * Null-object placeholder returned by {@see \ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry::get()}
 * when the requested key isn't registered — engine spec §16.6.
 *
 * The pattern guards the storefront when a satellite is uninstalled
 * while rows referencing its type key are still in the database: the
 * catalog keeps rendering (labels + read-only surfaces), while
 * price/checkout code paths throw a `RuntimeException` so a customer
 * cannot silently buy something the engine no longer knows how to
 * fulfill.
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

use ArtisanPackUI\Ecommerce\Contracts\ProductType;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use Money\Money;
use RuntimeException;

/**
 * Placeholder returned when a product's `type` key is not registered.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class MissingProductType implements ProductType
{
    /**
     * Constructs a placeholder carrying the missing registry key.
     *
     * @since 1.0.0
     *
     * @param  string  $missingKey  The key that was requested but not registered.
     */
    public function __construct( private readonly string $missingKey )
    {
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return $this->missingKey;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return __( 'Unknown product type (:key)', [ 'key' => $this->missingKey ] );
    }

    /**
     * @since 1.0.0
     *
     * @return string|null
     */
    public function icon(): ?string
    {
        return 'hero-question-mark-circle';
    }

    /**
     * @since 1.0.0
     *
     * @return bool
     */
    public function isMissing(): bool
    {
        return true;
    }

    /**
     * @since 1.0.0
     *
     * @param  Product              $product  Product being added.
     * @param  array<string, mixed> $options  Raw request options.
     *
     * @throws RuntimeException Always — missing types cannot enter the cart.
     *
     * @return array<string, mixed>
     */
    public function validateCartOptions( Product $product, array $options ): array
    {
        throw new RuntimeException(
            sprintf(
                'Product type "%s" is not registered; refusing to add product #%d to a cart.',
                $this->missingKey,
                (int) $product->getKey(),
            ),
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  Product              $product   Product being priced.
     * @param  array<string, mixed> $options   Sanitised options.
     * @param  int                  $quantity  Line quantity.
     * @param  string               $currency  ISO 4217 code.
     *
     * @throws RuntimeException Always — missing types cannot be priced.
     *
     * @return Money
     */
    public function priceLine( Product $product, array $options, int $quantity, string $currency ): Money
    {
        throw new RuntimeException(
            sprintf( 'Product type "%s" is not registered; refusing to price a line.', $this->missingKey ),
        );
    }

    /**
     * Missing types default to non-fulfillment so historical orders don't
     * accidentally get routed for shipping. The engine should never call
     * fulfillment on a missing-type line, but preferring `false` here
     * makes the failure mode "line is skipped" rather than "the whole
     * order tries to ship".
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function requiresFulfillment(): bool
    {
        return false;
    }

    /**
     * @since 1.0.0
     *
     * @return bool
     */
    public function isInventoryTracked(): bool
    {
        return false;
    }

    /**
     * @since 1.0.0
     *
     * @param  CartItem  $item  Cart line being snapshotted.
     *
     * @return array<string, mixed>
     */
    public function buildOrderSnapshot( CartItem $item ): array
    {
        return [
            'type'    => $this->missingKey,
            'missing' => true,
            'options' => (array) ( $item->options ?? [] ),
        ];
    }

    /**
     * @since 1.0.0
     *
     * @param  Order      $order      Placed order.
     * @param  OrderItem  $orderItem  Line to run side-effects for.
     *
     * @return void
     */
    public function onOrderPlaced( Order $order, OrderItem $orderItem ): void
    {
        // No-op. The placeholder must not touch orders — a satellite may
        // reappear later and take over its rows without a data migration.
    }
}
