<?php

/**
 * ProductReviewController.
 *
 * Storefront reviews (engine spec §9.1): `GET products/{product}/reviews`
 * lists a visible product's approved reviews; `POST products/{product}/reviews`
 * submits one through {@see ReviewService}. Submissions are rate-limited
 * by `ecommerce.review.submit` (3/hour per customer, 10/hour per IP) and
 * guarded by a honeypot field: a filled-in honeypot is answered exactly
 * like a genuine submission but filed straight to spam.
 *
 * Signed-in customers review under their customer record (and may cite an
 * order for the verified-purchase badge); guests may review when
 * `artisanpack.ecommerce.reviews.allow_guests` is on.
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

use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\SubmitReviewRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductReviewResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Product;
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
class ProductReviewController extends ApiController
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
     * Approved reviews of a storefront-visible product.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  int      $product  Product id.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: "List a product's approved reviews", resource: ProductReviewResource::class, collection: true )]
    public function index( Request $request, int $product ): JsonResponse
    {
        $model = Product::query()->storefrontVisible()->findOrFail( $product );

        return $this->listResponse(
            $model->reviews()->approved()->with( 'media' )->getQuery(),
            $request,
            ProductReviewResource::class,
            [
                'rating'               => [ 'rating', 'int' ],
                'is_verified_purchase' => [ 'is_verified_purchase', 'bool' ],
            ],
            [ 'rating' => 'rating', 'created_at' => 'created_at' ],
        );
    }

    /**
     * Submits a review.
     *
     * @since 1.0.0
     *
     * @param  SubmitReviewRequest  $request  Validated request.
     * @param  int                  $product  Product id.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Submit a product review', resource: ProductReviewResource::class, status: 201 )]
    public function store( SubmitReviewRequest $request, int $product ): JsonResponse
    {
        $model = Product::query()->storefrontVisible()->findOrFail( $product );

        $user = $request->user();

        if ( null !== $user && ! TokenAbilities::allowsStorefront( $user ) ) {
            return Problem::make( 403, 'forbidden', __( 'Forbidden' ), sprintf( 'Missing ability %s.', TokenAbilities::STOREFRONT ), $request );
        }

        $customer = Customer::forUser( $user );

        if ( null === $customer && ! (bool) config( 'artisanpack.ecommerce.reviews.allow_guests', true ) ) {
            return Problem::make( 401, 'unauthenticated', __( 'Unauthenticated' ), __( 'Sign in to review this product.' ), $request );
        }

        $review = $this->reviews->submit(
            $model,
            $request->safe()->only( [ 'rating', 'title', 'body', 'author_name', 'author_email', 'order_id' ] ),
            $customer,
            $request->filled( ReviewService::honeypotField() ),
        );

        if ( ! $review instanceof ProductReview ) {
            return Problem::make( 422, 'review-rejected', __( 'Review rejected' ), __( 'The review was rejected by a filter.' ), $request );
        }

        return $this->resourceResponse( $review->load( 'media' ), $request, ProductReviewResource::class, [], 201 );
    }
}
