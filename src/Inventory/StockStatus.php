<?php

/**
 * StockStatus.
 *
 * Read-only availability for storefronts (#172): in stock, low, on
 * backorder, or out, with the quantity when the store shows it
 * (`inventory.show_quantity`). A variable product aggregates its variants
 * (in stock if any is). Bundles and grouped products follow their
 * components through {@see StockLevels}. Never creates inventory rows.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Inventory;

use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class StockStatus
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const IN_STOCK = 'in_stock';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const LOW_STOCK = 'low_stock';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const BACKORDER = 'backorder';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const OUT_OF_STOCK = 'out_of_stock';

    /**
     * @since 1.0.0
     *
     * @param  string    $status    One of the constants.
     * @param  int|null  $quantity  Units available, when the store shows it and stock is limited.
     */
    public function __construct(
        public readonly string $status,
        public readonly ?int $quantity = null,
    ) {
    }

    /**
     * The availability of `$subject`.
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant  $subject  Product or variant.
     *
     * @return self
     */
    public static function for( Product|ProductVariant $subject ): self
    {
        $levels = app( StockLevels::class );

        if ( $subject instanceof Product && $subject->variants()->exists() ) {
            $states = $subject->variants()->get()->map( static fn ( ProductVariant $variant ): self => self::single( $levels, $subject, $variant ) );

            return self::aggregate( $states->all() );
        }

        return $subject instanceof ProductVariant
            ? self::single( $levels, $subject->product, $subject )
            : self::single( $levels, $subject, null );
    }

    /**
     * Whether the item can be added to a cart.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function purchasable(): bool
    {
        return self::OUT_OF_STOCK !== $this->status;
    }

    /**
     * @since 1.0.0
     *
     * @return array{status: string, purchasable: bool, quantity: int|null}
     */
    public function toArray(): array
    {
        return [ 'status' => $this->status, 'purchasable' => $this->purchasable(), 'quantity' => $this->quantity ];
    }

    /**
     * One product or variant.
     *
     * @since 1.0.0
     *
     * @param  StockLevels          $levels   Stock reads.
     * @param  Product|null         $product  Product.
     * @param  ProductVariant|null  $variant  Variant.
     *
     * @return self
     */
    private static function single( StockLevels $levels, ?Product $product, ?ProductVariant $variant ): self
    {
        if ( null === $product ) {
            return new self( self::OUT_OF_STOCK, self::shown( 0 ) );
        }

        $components = $levels->components( $product, $variant, 1 );

        if ( [] === $components ) {
            return new self( self::IN_STOCK );
        }

        $sellable  = $levels->sellable( $product, $variant );
        $backorder = false;
        $low       = false;

        foreach ( $components as $component ) {
            $item = $levels->itemFor( $component['stockable'] );

            if ( null === $item || ! $item->track_inventory ) {
                continue;
            }

            if ( $item->allow_backorder && $item->availableQuantity() < $component['quantity'] ) {
                $backorder = true;
            }

            if ( null !== $item->low_stock_threshold && $item->availableQuantity() <= (int) $item->low_stock_threshold ) {
                $low = true;
            }
        }

        if ( $backorder ) {
            return new self( self::BACKORDER );
        }

        if ( null === $sellable ) {
            return new self( self::IN_STOCK );
        }

        if ( $sellable <= 0 ) {
            return new self( self::OUT_OF_STOCK, self::shown( 0 ) );
        }

        return new self( $low ? self::LOW_STOCK : self::IN_STOCK, self::shown( $sellable ) );
    }

    /**
     * The best state across variants, with their total quantity.
     *
     * @since 1.0.0
     *
     * @param  array<int, self>  $states  Variant states.
     *
     * @return self
     */
    private static function aggregate( array $states ): self
    {
        $rank   = [ self::IN_STOCK => 0, self::LOW_STOCK => 1, self::BACKORDER => 2, self::OUT_OF_STOCK => 3 ];
        $best   = self::OUT_OF_STOCK;
        $total  = 0;
        $counts = true;

        foreach ( $states as $state ) {
            $best = $rank[ $state->status ] < $rank[ $best ] ? $state->status : $best;

            if ( null === $state->quantity ) {
                $counts = $counts && self::OUT_OF_STOCK === $state->status;
            } else {
                $total += $state->quantity;
            }
        }

        return new self( $best, $counts ? self::shown( $total ) : null );
    }

    /**
     * `$quantity` when the store shows stock levels.
     *
     * @since 1.0.0
     *
     * @param  int  $quantity  Units.
     *
     * @return int|null
     */
    private static function shown( int $quantity ): ?int
    {
        return (bool) config( 'artisanpack.ecommerce.inventory.show_quantity', false ) ? max( 0, $quantity ) : null;
    }
}
