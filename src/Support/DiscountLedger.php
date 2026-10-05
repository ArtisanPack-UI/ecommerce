<?php

/**
 * DiscountLedger.
 *
 * Running record of every discount the promotion engine grants for one
 * cart evaluation (parent plan §5.9 step 5). Actions write here instead of
 * touching Money on the cart; the ledger clamps every write so a line can
 * never go below zero and the cart can never be discounted past its
 * subtotal, no matter how promotions stack.
 *
 * Line snapshots are taken at construction, so actions see line totals as
 * they were when evaluation started, minus whatever earlier promotions
 * already took (`remainingForLine()` / `remainingSubtotal()`).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Support;

use ArtisanPackUI\Ecommerce\Models\Cart;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Money\Currency;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class DiscountLedger
{
    /**
     * Line snapshots keyed by cart-item id.
     *
     * @since 1.0.0
     *
     * @var array<int, array{id: int, product_id: int, variant_id: int|null, quantity: int, unit_price: Money, total: Money}>
     */
    private array $lines = [];

    /**
     * Accumulated discount per line, keyed by cart-item id.
     *
     * @since 1.0.0
     *
     * @var array<int, Money>
     */
    private array $lineDiscounts = [];

    /**
     * Discount granted per promotion id.
     *
     * @since 1.0.0
     *
     * @var array<int, Money>
     */
    private array $promotionTotals = [];

    /**
     * Promotion ids that granted free shipping.
     *
     * @since 1.0.0
     *
     * @var array<int, int>
     */
    private array $freeShippingBy = [];

    /**
     * Free items to add to the cart.
     *
     * @since 1.0.0
     *
     * @var array<int, array{product_id: int, variant_id: int|null, quantity: int, promotion_id: int|null}>
     */
    private array $freeItems = [];

    /**
     * Promotion id currently applying (0 when written outside the engine).
     *
     * @since 1.0.0
     *
     * @var int
     */
    private int $currentPromotionId = 0;

    /**
     * @since 1.0.0
     *
     * @var Currency
     */
    private Currency $currency;

    /**
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart being evaluated (lines are snapshotted).
     */
    public function __construct( Cart $cart )
    {
        $this->currency = new Currency( strtoupper( (string) $cart->currency ) );

        $items = $cart->relationLoaded( 'items' ) ? $cart->items : $cart->items()->get();

        foreach ( $items as $item ) {
            $this->lines[ (int) $item->id ] = [
                'id'         => (int) $item->id,
                'product_id' => (int) $item->product_id,
                'variant_id' => null === $item->product_variant_id ? null : (int) $item->product_variant_id,
                'quantity'   => (int) $item->quantity,
                'unit_price' => new Money( max( 0, (int) $item->unit_price_amount ), $this->currency ),
                'total'      => new Money( max( 0, (int) $item->line_total_amount ), $this->currency ),
            ];
            $this->lineDiscounts[ (int) $item->id ] = new Money( 0, $this->currency );
        }
    }

    /**
     * Marks subsequent writes as belonging to `$promotionId`. Called by the
     * engine around each promotion's actions.
     *
     * @since 1.0.0
     *
     * @param  int  $promotionId  Promotion id.
     *
     * @return void
     */
    public function beginPromotion( int $promotionId ): void
    {
        $this->currentPromotionId = $promotionId;
    }

    /**
     * Ends the current promotion scope.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function endPromotion(): void
    {
        $this->currentPromotionId = 0;
    }

    /**
     * Ledger currency (the cart currency).
     *
     * @since 1.0.0
     *
     * @return Currency
     */
    public function currency(): Currency
    {
        return $this->currency;
    }

    /**
     * Zero in the ledger currency.
     *
     * @since 1.0.0
     *
     * @return Money
     */
    public function zero(): Money
    {
        return new Money( 0, $this->currency );
    }

    /**
     * Line snapshots.
     *
     * @since 1.0.0
     *
     * @return Collection<int, array{id: int, product_id: int, variant_id: int|null, quantity: int, unit_price: Money, total: Money}>
     */
    public function lines(): Collection
    {
        return new Collection( $this->lines );
    }

    /**
     * Undiscounted amount left on a line.
     *
     * @since 1.0.0
     *
     * @param  int  $lineId  Cart-item id.
     *
     * @return Money
     */
    public function remainingForLine( int $lineId ): Money
    {
        if ( ! isset( $this->lines[ $lineId ] ) ) {
            return $this->zero();
        }

        return $this->lines[ $lineId ]['total']->subtract( $this->lineDiscounts[ $lineId ] );
    }

    /**
     * Undiscounted subtotal left across all lines.
     *
     * @since 1.0.0
     *
     * @return Money
     */
    public function remainingSubtotal(): Money
    {
        $remaining = $this->zero();

        foreach ( array_keys( $this->lines ) as $lineId ) {
            $remaining = $remaining->add( $this->remainingForLine( $lineId ) );
        }

        return $remaining;
    }

    /**
     * Discounts one line, clamped to what remains on it.
     *
     * @since 1.0.0
     *
     * @param  int    $lineId  Cart-item id.
     * @param  Money  $amount  Requested discount.
     *
     * @throws InvalidArgumentException When `$amount` is in another currency.
     *
     * @return Money Amount actually applied.
     */
    public function discountLine( int $lineId, Money $amount ): Money
    {
        $this->assertCurrency( $amount );

        if ( ! isset( $this->lines[ $lineId ] ) || ! $amount->isPositive() ) {
            return $this->zero();
        }

        $applied = Money::min( $amount, $this->remainingForLine( $lineId ) );

        if ( ! $applied->isPositive() ) {
            return $this->zero();
        }

        $this->lineDiscounts[ $lineId ] = $this->lineDiscounts[ $lineId ]->add( $applied );
        $this->record( $applied );

        return $applied;
    }

    /**
     * Discounts the cart as a whole, spread across lines in proportion to
     * what remains on each, and clamped to the remaining subtotal.
     *
     * @since 1.0.0
     *
     * @param  Money  $amount  Requested discount.
     *
     * @throws InvalidArgumentException When `$amount` is in another currency.
     *
     * @return Money Amount actually applied.
     */
    public function discountCart( Money $amount ): Money
    {
        $this->assertCurrency( $amount );

        $remaining = $this->remainingSubtotal();

        if ( ! $amount->isPositive() || ! $remaining->isPositive() ) {
            return $this->zero();
        }

        $applied = Money::min( $amount, $remaining );
        $ratios  = [];

        // Only lines with something left take part, so a fully-discounted
        // line can never be handed a rounding remainder.
        foreach ( array_keys( $this->lines ) as $lineId ) {
            $left = (int) $this->remainingForLine( $lineId )->getAmount();

            if ( $left > 0 ) {
                $ratios[ $lineId ] = $left;
            }
        }

        foreach ( $applied->allocate( $ratios ) as $lineId => $share ) {
            $this->lineDiscounts[ $lineId ] = $this->lineDiscounts[ $lineId ]->add( $share );
        }

        $this->record( $applied );

        return $applied;
    }

    /**
     * Grants free shipping.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function grantFreeShipping(): void
    {
        $this->freeShippingBy[] = $this->currentPromotionId;
        $this->record( $this->zero() );
    }

    /**
     * Records an item to be added to the cart at no charge.
     *
     * @since 1.0.0
     *
     * @param  int       $productId  Product id.
     * @param  int|null  $variantId  Variant id.
     * @param  int       $quantity   Units to add.
     *
     * @return void
     */
    public function addFreeItem( int $productId, ?int $variantId, int $quantity ): void
    {
        if ( $quantity < 1 ) {
            return;
        }

        $this->freeItems[] = [
            'product_id'   => $productId,
            'variant_id'   => $variantId,
            'quantity'     => $quantity,
            'promotion_id' => 0 === $this->currentPromotionId ? null : $this->currentPromotionId,
        ];
        $this->record( $this->zero() );
    }

    /**
     * Total discount across all lines.
     *
     * @since 1.0.0
     *
     * @return Money
     */
    public function total(): Money
    {
        return Money::sum( $this->zero(), ...array_values( $this->lineDiscounts ) );
    }

    /**
     * Discount per line, keyed by cart-item id.
     *
     * @since 1.0.0
     *
     * @return array<int, Money>
     */
    public function lineDiscounts(): array
    {
        return $this->lineDiscounts;
    }

    /**
     * Discount granted by one promotion.
     *
     * @since 1.0.0
     *
     * @param  int  $promotionId  Promotion id.
     *
     * @return Money
     */
    public function amountForPromotion( int $promotionId ): Money
    {
        return $this->promotionTotals[ $promotionId ] ?? $this->zero();
    }

    /**
     * Whether any promotion granted free shipping.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function hasFreeShipping(): bool
    {
        return [] !== $this->freeShippingBy;
    }

    /**
     * Whether `$promotionId` granted free shipping.
     *
     * @since 1.0.0
     *
     * @param  int  $promotionId  Promotion id.
     *
     * @return bool
     */
    public function grantedFreeShipping( int $promotionId ): bool
    {
        return in_array( $promotionId, $this->freeShippingBy, true );
    }

    /**
     * Items to add to the cart at no charge.
     *
     * @since 1.0.0
     *
     * @return array<int, array{product_id: int, variant_id: int|null, quantity: int, promotion_id: int|null}>
     */
    public function freeItems(): array
    {
        return $this->freeItems;
    }

    /**
     * Adds `$amount` to the current promotion's running total.
     *
     * @since 1.0.0
     *
     * @param  Money  $amount  Applied amount.
     *
     * @return void
     */
    private function record( Money $amount ): void
    {
        $id = $this->currentPromotionId;

        $this->promotionTotals[ $id ] = ( $this->promotionTotals[ $id ] ?? $this->zero() )->add( $amount );
    }

    /**
     * @since 1.0.0
     *
     * @param  Money  $amount  Amount to check.
     *
     * @throws InvalidArgumentException When `$amount` is in another currency.
     *
     * @return void
     */
    private function assertCurrency( Money $amount ): void
    {
        if ( ! $amount->getCurrency()->equals( $this->currency ) ) {
            throw new InvalidArgumentException( sprintf(
                'Discount currency %s does not match cart currency %s.',
                $amount->getCurrency()->getCode(),
                $this->currency->getCode(),
            ) );
        }
    }
}
