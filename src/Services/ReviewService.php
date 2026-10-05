<?php

/**
 * ReviewService.
 *
 * Owns the product-review lifecycle (parent plan §5.12, engine spec §3.26):
 *
 * - {@see self::submit()} — runs `ap.ecommerce.review.submitting`, marks
 *   the review a verified purchase when its order belongs to the reviewer
 *   and contains the product, persists it as `pending`, asks the bound
 *   {@see ReviewModerator} for a verdict, then fires
 *   `ap.ecommerce.review.submitted` and {@see ReviewSubmitted} (honeypot
 *   hits are filed as spam silently, without either);
 * - {@see self::approve()} / {@see self::reject()} / {@see self::markSpam()}
 *   / {@see self::requeue()} — moderation transitions, each firing its hook;
 * - {@see self::delete()}.
 *
 * The product's denormalized rating follows every transition through
 * {@see ProductRatingAggregator}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Contracts\ReviewModerator;
use ArtisanPackUI\Ecommerce\Events\ReviewApproved;
use ArtisanPackUI\Ecommerce\Events\ReviewSubmitted;
use ArtisanPackUI\Ecommerce\Exceptions\ReviewNotAllowedException;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Models\ProductReviewMedia;
use ArtisanPackUI\Ecommerce\Reviews\ProductRatingAggregator;
use ArtisanPackUI\Ecommerce\Reviews\ReviewEligibility;
use ArtisanPackUI\Ecommerce\Reviews\ReviewMediaStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ReviewService
{
    /**
     * Moderation actions accepted by {@see self::moderate()}.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const ACTIONS = [ 'approve', 'reject', 'spam', 'pending' ];

    /**
     * Order payment statuses that count as a purchase.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const PURCHASED_PAYMENT_STATUSES = [ 'paid', 'partially_refunded' ];

    /**
     * @since 1.0.0
     *
     * @param  ReviewModerator          $moderator   Automatic moderation.
     * @param  ProductRatingAggregator  $aggregator  Rating denormalizer.
     */
    public function __construct(
        protected ReviewModerator $moderator,
        protected ProductRatingAggregator $aggregator,
    ) {
    }

    /**
     * The honeypot input name (`artisanpack.ecommerce.reviews.honeypot_field`).
     * Real shoppers never see the field; bots that fill it in are filed as
     * spam.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function honeypotField(): string
    {
        return (string) config( 'artisanpack.ecommerce.reviews.honeypot_field', 'website' );
    }

    /**
     * Submits a review of `$product`.
     *
     * `$attributes` takes `rating`, `title`, `body`, `author_name`,
     * `author_email`, `order_id`, and `media` (uploaded photos, stored
     * through {@see ReviewMediaStore}, at most `reviews.max_media`). Author
     * details default to the customer's. A supplied `order_id` is kept only
     * when it proves the purchase; without one, the customer's latest paid
     * order for the product is used, so buyers get the verified badge
     * (#181). The author must be eligible ({@see self::eligibility()}).
     *
     * @since 1.0.0
     *
     * @param  Product               $product     Reviewed product.
     * @param  array<string, mixed>  $attributes  Review input.
     * @param  Customer|null         $customer    The signed-in reviewer, if any.
     * @param  bool                  $isSpam      Skip moderation and file straight to spam
     *                                            (e.g. a honeypot field was filled in).
     *
     * @throws ReviewNotAllowedException When the author isn't eligible.
     *
     * @return ProductReview|null The review, or null when a filter aborted the submission.
     */
    public function submit( Product $product, array $attributes, ?Customer $customer = null, bool $isSpam = false ): ?ProductReview
    {
        $eligibility = $this->eligibility( $product, $customer );

        if ( ! $eligibility->allowed ) {
            throw new ReviewNotAllowedException( (string) $eligibility->reason );
        }

        $order = isset( $attributes['order_id'] ) ? Order::query()->find( $attributes['order_id'] ) : null;

        if ( null !== $order && ! $this->provesPurchase( $order, $product, $customer ) ) {
            $order = null;
        }

        $order ??= null === $eligibility->purchaseOrderId ? null : Order::query()->find( $eligibility->purchaseOrderId );

        $media = array_slice(
            array_values( array_filter( (array) ( $attributes['media'] ?? [] ), static fn ( mixed $file ): bool => $file instanceof UploadedFile ) ),
            0,
            max( 0, (int) config( 'artisanpack.ecommerce.reviews.max_media', 5 ) ),
        );
        $store    = app( ReviewMediaStore::class );
        $mediaIds = array_map( static fn ( UploadedFile $file ): int => $store->store( $file ), $media );

        $attributes = applyFilters( 'ap.ecommerce.review.submitting', [
            'product_id'           => $product->id,
            'customer_id'          => $customer?->id,
            'order_id'             => $order?->id,
            'author_name'          => $this->authorName( $attributes, $customer ),
            'author_email'         => $attributes['author_email'] ?? $customer?->email,
            'rating'               => (int) $attributes['rating'],
            'title'                => $attributes['title'] ?? null,
            'body'                 => $attributes['body'] ?? null,
            'is_verified_purchase' => null !== $order,
            'status'               => ProductReview::STATUS_PENDING,
        ], $order );

        if ( ! is_array( $attributes ) ) {
            return null;
        }

        $review = ProductReview::query()->create( $attributes );

        foreach ( $mediaIds as $mediaId ) {
            ProductReviewMedia::query()->create( [ 'review_id' => $review->id, 'media_id' => $mediaId ] );
        }

        if ( $isSpam ) {
            // Bot traffic: file it, but don't announce it to listeners or the
            // admin broadcast channel.
            $this->markSpam( $review );

            return $review;
        }

        $this->autoModerate( $review );

        doAction( 'ap.ecommerce.review.submitted', $review );
        Event::dispatch( new ReviewSubmitted( $review ) );

        return $review;
    }

    /**
     * Applies an admin moderation action.
     *
     * @since 1.0.0
     *
     * @param  ProductReview  $review   Review.
     * @param  string         $action   `approve`, `reject`, `spam`, or `pending`.
     * @param  string|null    $reason   Rejection reason.
     * @param  int|null       $actorId  Moderating user id.
     *
     * @throws InvalidArgumentException When `$action` is unknown.
     *
     * @return ProductReview
     */
    public function moderate( ProductReview $review, string $action, ?string $reason = null, ?int $actorId = null ): ProductReview
    {
        return match ( $action ) {
            'approve' => $this->approve( $review, $actorId ),
            'reject'  => $this->reject( $review, (string) $reason, $actorId ),
            'spam'    => $this->markSpam( $review, $actorId ),
            'pending' => $this->requeue( $review, $actorId ),
            default   => throw new InvalidArgumentException( sprintf( 'Unknown review moderation action "%s".', $action ) ),
        };
    }

    /**
     * Publishes the review and counts it toward the product's rating.
     *
     * @since 1.0.0
     *
     * @param  ProductReview  $review   Review.
     * @param  int|null       $actorId  Moderating user id (null for automatic moderation).
     *
     * @return ProductReview
     */
    public function approve( ProductReview $review, ?int $actorId = null ): ProductReview
    {
        if ( $review->isApproved() ) {
            return $review;
        }

        $review->forceFill( [
            'status'              => ProductReview::STATUS_APPROVED,
            'approved_at'         => now(),
            'reviewed_by_user_id' => $actorId,
        ] )->save();

        doAction( 'ap.ecommerce.review.approved', $review );
        Event::dispatch( new ReviewApproved( $review ) );

        return $review;
    }

    /**
     * Rejects the review (hiding it if it was published).
     *
     * @since 1.0.0
     *
     * @param  ProductReview  $review   Review.
     * @param  string         $reason   Why it was rejected.
     * @param  int|null       $actorId  Moderating user id.
     *
     * @return ProductReview
     */
    public function reject( ProductReview $review, string $reason = '', ?int $actorId = null ): ProductReview
    {
        $this->transition( $review, ProductReview::STATUS_REJECTED, $actorId );

        doAction( 'ap.ecommerce.review.rejected', $review, $reason );

        return $review;
    }

    /**
     * Files the review as spam (hiding it if it was published).
     *
     * @since 1.0.0
     *
     * @param  ProductReview  $review   Review.
     * @param  int|null       $actorId  Moderating user id.
     *
     * @return ProductReview
     */
    public function markSpam( ProductReview $review, ?int $actorId = null ): ProductReview
    {
        $this->transition( $review, ProductReview::STATUS_SPAM, $actorId );

        doAction( 'ap.ecommerce.review.markedSpam', $review );

        return $review;
    }

    /**
     * Puts the review back in the moderation queue.
     *
     * @since 1.0.0
     *
     * @param  ProductReview  $review   Review.
     * @param  int|null       $actorId  Moderating user id.
     *
     * @return ProductReview
     */
    public function requeue( ProductReview $review, ?int $actorId = null ): ProductReview
    {
        $wasApproved = $review->isApproved();

        $this->transition( $review, ProductReview::STATUS_PENDING, $actorId );

        if ( $wasApproved ) {
            $this->aggregator->recalculate( $review->product_id );
        }

        return $review;
    }

    /**
     * Deletes the review, dropping it from the product's rating.
     *
     * @since 1.0.0
     *
     * @param  ProductReview  $review  Review.
     *
     * @return void
     */
    public function delete( ProductReview $review ): void
    {
        $wasApproved = $review->isApproved();

        $review->delete();

        if ( $wasApproved ) {
            $this->aggregator->recalculate( $review->product_id );
        }
    }

    /**
     * Whether `$customer` (null: a guest) may review `$product`, and
     * whether the review would be a verified purchase (#181).
     *
     * @since 1.0.0
     *
     * @param  Product        $product   Product.
     * @param  Customer|null  $customer  Shopper.
     *
     * @return ReviewEligibility
     */
    public function eligibility( Product $product, ?Customer $customer ): ReviewEligibility
    {
        if ( null === $customer && ! (bool) config( 'artisanpack.ecommerce.reviews.allow_guests', true ) ) {
            return new ReviewEligibility( false, ReviewEligibility::GUESTS_NOT_ALLOWED );
        }

        $orderId = null === $customer ? null : $this->purchaseOrderId( $product, $customer );

        if ( null === $orderId && (bool) config( 'artisanpack.ecommerce.reviews.require_purchase', false ) ) {
            return new ReviewEligibility( false, ReviewEligibility::PURCHASE_REQUIRED );
        }

        $reviewed = null !== $customer && ProductReview::query()
            ->where( 'product_id', $product->id )
            ->where( 'customer_id', $customer->id )
            ->whereNotIn( 'status', [ ProductReview::STATUS_REJECTED, ProductReview::STATUS_SPAM ] )
            ->exists();

        if ( $reviewed && ! (bool) config( 'artisanpack.ecommerce.reviews.allow_multiple', false ) ) {
            return new ReviewEligibility( false, ReviewEligibility::ALREADY_REVIEWED, null !== $orderId, $orderId );
        }

        return new ReviewEligibility( true, null, null !== $orderId, $orderId );
    }

    /**
     * Whether `$order` proves the reviewer bought `$product`: it belongs to
     * the reviewing customer, has been paid for, and contains the product.
     *
     * @since 1.0.0
     *
     * @param  Order          $order     Order the reviewer cited.
     * @param  Product        $product   Reviewed product.
     * @param  Customer|null  $customer  Reviewer.
     *
     * @return bool
     */
    public function provesPurchase( Order $order, Product $product, ?Customer $customer ): bool
    {
        return null !== $customer
            && $order->customer_id === $customer->id
            && in_array( $order->payment_status, self::PURCHASED_PAYMENT_STATUSES, true )
            && $order->items()->where( 'product_id', $product->id )->exists();
    }

    /**
     * The customer's latest paid order containing `$product`, if any.
     *
     * @since 1.0.0
     *
     * @param  Product   $product   Product.
     * @param  Customer  $customer  Customer.
     *
     * @return int|null
     */
    protected function purchaseOrderId( Product $product, Customer $customer ): ?int
    {
        $id = Order::query()
            ->where( 'customer_id', $customer->id )
            ->whereIn( 'payment_status', self::PURCHASED_PAYMENT_STATUSES )
            ->whereHas( 'items', static fn ( $items ) => $items->where( 'product_id', $product->id ) )
            ->latest( 'id' )
            ->value( 'id' );

        return null === $id ? null : (int) $id;
    }

    /**
     * Runs the bound moderator and applies its verdict.
     *
     * @since 1.0.0
     *
     * @param  ProductReview  $review  Freshly persisted review.
     *
     * @return void
     */
    protected function autoModerate( ProductReview $review ): void
    {
        $review  = applyFilters( 'ap.ecommerce.review.moderating', $review );
        $verdict = $this->moderator->moderate( $review );

        match ( $verdict ) {
            'approve' => $this->approve( $review ),
            'reject'  => $this->reject( $review, __( 'Rejected by automatic moderation.' ) ),
            'spam'    => $this->markSpam( $review ),
            'pending' => null,
            default   => Log::channel( 'ecommerce' )->warning( 'ecommerce.review.unknown_verdict', [
                'moderator' => $this->moderator->key(),
                'verdict'   => $verdict,
                'review_id' => $review->id,
            ] ),
        };
    }

    /**
     * Moves the review to a non-approved status.
     *
     * @since 1.0.0
     *
     * @param  ProductReview  $review   Review.
     * @param  string         $status   Target status.
     * @param  int|null       $actorId  Moderating user id.
     *
     * @return void
     */
    protected function transition( ProductReview $review, string $status, ?int $actorId ): void
    {
        $review->forceFill( [
            'status'              => $status,
            'approved_at'         => null,
            'reviewed_by_user_id' => $actorId,
        ] )->save();
    }

    /**
     * The display name: supplied, else the customer's, else "Anonymous".
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  Review input.
     * @param  Customer|null         $customer    Reviewer.
     *
     * @return string
     */
    protected function authorName( array $attributes, ?Customer $customer ): string
    {
        $name = trim( (string) ( $attributes['author_name'] ?? '' ) );

        if ( '' === $name && null !== $customer ) {
            $name = trim( ( $customer->first_name ?? '' ) . ' ' . ( $customer->last_name ?? '' ) );
        }

        return '' === $name ? __( 'Anonymous' ) : $name;
    }
}
