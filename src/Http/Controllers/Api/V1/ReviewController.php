<?php

/**
 * ReviewController.
 *
 * The review moderation queue (`admin/reviews`, engine spec §6.18
 * `review` abilities): list and filter reviews in any status, moderate
 * one (`approve`, `reject`, `spam`, back to `pending`), or delete it.
 * Every action goes through {@see ReviewService}, so moderation hooks fire
 * and the product's rating aggregate stays current.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1;

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ModerateReviewRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductReviewResource;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ReviewController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  ReviewService  $reviews  Review lifecycle.
     */
    public function __construct( private readonly ReviewService $reviews )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List reviews for moderation', resource: ProductReviewResource::class, collection: true, filters: [ 'status' => 'string', 'product_id' => 'int-list', 'customer_id' => 'int-list', 'rating' => 'int-list', 'is_verified_purchase' => 'boolean' ], sorts: [ 'rating', 'created_at' ] )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            ProductReview::query()->with( 'media' ),
            $request,
            ProductReviewResource::class,
            [
                'status'               => 'status',
                'product_id'           => [ 'product_id', 'int' ],
                'customer_id'          => [ 'customer_id', 'int' ],
                'rating'               => [ 'rating', 'int' ],
                'is_verified_purchase' => [ 'is_verified_purchase', 'bool' ],
            ],
            [ 'rating' => 'rating', 'created_at' => 'created_at' ],
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request        $request  Request.
     * @param  ProductReview  $review   Review.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Show a review', resource: ProductReviewResource::class )]
    public function show( Request $request, ProductReview $review ): JsonResponse
    {
        return $this->resourceResponse( $review->load( 'media' ), $request, ProductReviewResource::class );
    }

    /**
     * Applies a moderation action.
     *
     * @since 1.0.0
     *
     * @param  ModerateReviewRequest  $request  Validated request.
     * @param  ProductReview          $review   Review.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Moderate a review', resource: ProductReviewResource::class )]
    public function moderate( ModerateReviewRequest $request, ProductReview $review ): JsonResponse
    {
        $actor = $request->user()?->getAuthIdentifier();

        $this->reviews->moderate(
            $review,
            (string) $request->validated( 'action' ),
            $request->validated( 'reason' ),
            is_numeric( $actor ) ? (int) $actor : null,
        );

        return $this->resourceResponse( $review->load( 'media' ), $request, ProductReviewResource::class );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request        $request  Request.
     * @param  ProductReview  $review   Review.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a review', resource: ProductReviewResource::class )]
    public function destroy( Request $request, ProductReview $review ): JsonResponse
    {
        $review->load( 'media' );
        $this->reviews->delete( $review );

        return $this->resourceResponse( $review, $request, ProductReviewResource::class );
    }
}
