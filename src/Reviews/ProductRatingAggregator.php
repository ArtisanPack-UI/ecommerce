<?php

/**
 * ProductRatingAggregator.
 *
 * Keeps `products.avg_rating` and `products.reviews_count` in step with the
 * product's approved reviews, so listings never run a rating query per
 * product (parent plan §5.12). The aggregate is always recomputed from the
 * approved rows — never incremented — under a lock on the product row, so
 * concurrent moderation can't drift it.
 *
 * Registered on `ap.ecommerce.review.approved`, `.rejected`, and
 * `.markedSpam`; {@see \ArtisanPackUI\Ecommerce\Services\ReviewService}
 * also calls it when a review is re-queued or deleted.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Reviews;

use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use Illuminate\Support\Facades\DB;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProductRatingAggregator
{
    /**
     * Hook listener: recalculates the reviewed product's aggregate.
     *
     * @since 1.0.0
     *
     * @param  ProductReview  $review  The review whose status changed.
     *
     * @return void
     */
    public function handle( ProductReview $review ): void
    {
        $this->recalculate( $review->product_id );
    }

    /**
     * Recomputes the product's average rating and approved-review count.
     *
     * @since 1.0.0
     *
     * @param  int  $productId  Product id.
     *
     * @return void
     */
    public function recalculate( int $productId ): void
    {
        DB::transaction( function () use ( $productId ): void {
            $product = Product::query()->lockForUpdate()->find( $productId );

            if ( null === $product ) {
                return;
            }

            $aggregate = ProductReview::query()
                ->where( 'product_id', $productId )
                ->approved()
                ->selectRaw( 'COUNT(*) AS reviews_count, AVG(rating) AS avg_rating' )
                ->first();

            $count = (int) ( $aggregate?->getAttribute( 'reviews_count' ) ?? 0 );

            // Saved through the model so Scout re-indexes the new rating.
            $product->forceFill( [
                'reviews_count' => $count,
                'avg_rating'    => $count > 0 ? round( (float) $aggregate->getAttribute( 'avg_rating' ), 2 ) : 0.0,
            ] )->save();
        } );
    }

    /**
     * How many approved reviews of `$product` gave each star, 5 to 1 (#181).
     *
     * @since 1.0.0
     *
     * @param  Product  $product  Product.
     *
     * @return array<int, int>
     */
    public function histogram( Product $product ): array
    {
        $counts = ProductReview::query()
            ->where( 'product_id', $product->id )
            ->approved()
            ->selectRaw( 'rating, COUNT(*) AS reviews' )
            ->groupBy( 'rating' )
            ->pluck( 'reviews', 'rating' );

        $histogram = [];

        foreach ( [ 5, 4, 3, 2, 1 ] as $stars ) {
            $histogram[ $stars ] = (int) ( $counts[ $stars ] ?? 0 );
        }

        return $histogram;
    }
}
