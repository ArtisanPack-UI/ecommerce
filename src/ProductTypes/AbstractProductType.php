<?php

/**
 * AbstractProductType.
 *
 * Shared plumbing for the core product-type implementations. Subclasses
 * declare {@see self::key()}, {@see self::label()}, and any behavioural
 * flag overrides; the base handles cart-option sanitising, per-currency
 * price lookup, and snapshot construction against the domain models.
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
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\ValueObjects\Currency as CurrencyVO;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Money\Currency as MoneyCurrency;
use Money\Money;
use RuntimeException;

/**
 * Base implementation of {@see ProductType}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class AbstractProductType implements ProductType
{
    /**
     * @since 1.0.0
     *
     * @return string|null
     */
    public function icon(): ?string
    {
        return null;
    }

    /**
     * Strips options down to the keys the type actually cares about. The
     * default keeps `variant_id` (used by every physical-goods type) and
     * discards everything else so `cart_items.options` does not become a
     * dumping ground for arbitrary request payload.
     *
     * @since 1.0.0
     *
     * @param  Product              $product  Product being added.
     * @param  array<string, mixed> $options  Raw request options.
     *
     * @return array<string, mixed>
     */
    public function validateCartOptions( Product $product, array $options ): array
    {
        $out = [];

        if ( array_key_exists( 'variant_id', $options ) && null !== $options[ 'variant_id' ] ) {
            $variantId = $this->normaliseVariantId( $options[ 'variant_id' ] );

            if ( null === $variantId ) {
                throw new InvalidArgumentException(
                    'variant_id must be a positive integer.',
                );
            }

            $out[ 'variant_id' ] = $variantId;
        }

        return $out;
    }

    /**
     * Prices a cart line by looking up the active row on `product_prices`
     * (variant first, then product), multiplied by `$quantity`.
     *
     * @since 1.0.0
     *
     * @param  Product              $product   Product being priced.
     * @param  array<string, mixed> $options   Sanitised cart-line options.
     * @param  int                  $quantity  Line quantity.
     * @param  string               $currency  Cart currency (ISO 4217).
     *
     * @throws InvalidArgumentException When `$quantity` is not positive.
     * @throws RuntimeException         When no price row exists in `$currency`.
     *
     * @return Money
     */
    public function priceLine( Product $product, array $options, int $quantity, string $currency ): Money
    {
        if ( $quantity < 1 ) {
            throw new InvalidArgumentException( 'Line quantity must be a positive integer.' );
        }

        $currencyCode = CurrencyVO::of( $currency )->code();
        $variant      = $this->resolveVariant( $product, $options );

        $priceable = $variant ?? $product;
        $unit      = $this->activePriceFor( $priceable, $currencyCode, Carbon::now() );

        if ( null === $unit ) {
            throw new RuntimeException(
                sprintf(
                    'No active %s price exists for %s #%d.',
                    $currencyCode,
                    $priceable::class,
                    $priceable->getKey(),
                ),
            );
        }

        return $unit->multiply( (string) $quantity );
    }

    /**
     * @since 1.0.0
     *
     * @return bool
     */
    public function isInventoryTracked(): bool
    {
        return true;
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
            'type'    => $this->key(),
            'options' => (array) ( $item->options ?? [] ),
        ];
    }

    /**
     * Default post-placement side-effect: nothing. Types with side-effects
     * (digital, license, subscription) override this.
     *
     * @since 1.0.0
     *
     * @param  Order      $order      Placed order.
     * @param  OrderItem  $orderItem  Line to run side-effects for.
     *
     * @return void
     */
    public function onOrderPlaced( Order $order, OrderItem $orderItem ): void
    {
        // No-op by default.
    }

    /**
     * Loads the selected variant when one was chosen at add-to-cart time.
     *
     * @since 1.0.0
     *
     * @param  Product              $product  Owning product.
     * @param  array<string, mixed> $options  Sanitised cart-line options.
     *
     * @throws RuntimeException When the referenced variant does not belong to `$product`.
     *
     * @return ProductVariant|null
     */
    protected function resolveVariant( Product $product, array $options ): ?ProductVariant
    {
        if ( empty( $options[ 'variant_id' ] ) ) {
            return null;
        }

        $variantId = (int) $options[ 'variant_id' ];
        $variant   = ProductVariant::query()->find( $variantId );

        if ( null === $variant || $variant->product_id !== $product->getKey() ) {
            throw new RuntimeException(
                sprintf( 'Variant #%d does not belong to product #%d.', $variantId, (int) $product->getKey() ),
            );
        }

        return $variant;
    }

    /**
     * Returns the active price row for `$priceable` in `$currency` at `$at`.
     *
     * Rules: the row whose `[starts_at, ends_at]` window contains `$at`
     * wins; otherwise the row with both timestamps `null` (the base
     * price) is used; when both apply, the scheduled row takes precedence
     * (spec §3.2).
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant  $priceable  Priceable row.
     * @param  string                  $currency   ISO 4217 code.
     * @param  Carbon                  $at         Reference time.
     *
     * @return Money|null
     */
    protected function activePriceFor( Product|ProductVariant $priceable, string $currency, Carbon $at ): ?Money
    {
        // Order deterministically so ties don't depend on insertion order
        // when multiple rows apply. Precedence rules for `$scheduled`:
        //   1. The most-recently-starting window that contains `$at`.
        //   2. Then the earliest-ending window (tighter windows win).
        //   3. Finally the highest `id` as a stable tie-breaker.
        // Precedence for `$base` (both timestamps null): highest `id`
        // wins so the newest saved base price is authoritative.
        $rows = ProductPrice::query()
            ->where( 'priceable_type', $priceable->getMorphClass() )
            ->where( 'priceable_id', $priceable->getKey() )
            ->where( 'currency', $currency )
            ->orderByRaw( '(starts_at IS NULL) ASC' )
            ->orderBy( 'starts_at', 'desc' )
            ->orderByRaw( '(ends_at IS NULL) ASC' )
            ->orderBy( 'ends_at', 'asc' )
            ->orderBy( 'id', 'desc' )
            ->get();

        $base      = null;
        $scheduled = null;

        foreach ( $rows as $row ) {
            if ( null === $row->starts_at && null === $row->ends_at ) {
                $base ??= $row;
                continue;
            }

            if ( null !== $scheduled ) {
                continue;
            }

            $starts = null === $row->starts_at || $row->starts_at->lessThanOrEqualTo( $at );
            $ends   = null === $row->ends_at || $row->ends_at->greaterThanOrEqualTo( $at );

            if ( $starts && $ends ) {
                $scheduled = $row;
            }
        }

        $winner = $scheduled ?? $base;

        if ( null === $winner || null === $winner->price_amount ) {
            return null;
        }

        return new Money(
            (int) $winner->price_amount,
            new MoneyCurrency( $currency ),
        );
    }

    /**
     * Coerces a variant_id request value to a positive integer or `null`.
     *
     * Accepts either an actual `int > 0`, or a canonical integer string
     * (`"42"` — no leading zeros, no whitespace, no sign, no decimals).
     * Everything else — floats, negative numbers, zero, arrays, garbage
     * strings — is rejected. `(int) $value` would otherwise silently
     * coerce `"42-abc"` → `42` or `"1.9"` → `1`, both of which are
     * request-poisoning shapes we do not want persisted onto
     * `cart_items.options`.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Raw value from the request payload.
     *
     * @return int|null Positive integer, or null when unusable.
     */
    private function normaliseVariantId( mixed $value ): ?int
    {
        if ( is_int( $value ) ) {
            return $value > 0 ? $value : null;
        }

        if ( is_string( $value ) && 1 === preg_match( '/^[1-9]\d*$/', $value ) ) {
            return (int) $value;
        }

        return null;
    }
}
