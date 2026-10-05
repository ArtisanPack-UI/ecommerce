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
use ArtisanPackUI\Ecommerce\Exceptions\ReviewNotAllowedException;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\SubmitReviewRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductReviewResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Reviews\ProductRatingAggregator;
use ArtisanPackUI\Ecommerce\Reviews\ReviewEligibility;
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
    #[ApiOperation( summary: "List a product's approved reviews", resource: ProductReviewResource::class, collection: true, filters: [ 'rating' => 'int-list', 'is_verified_purchase' => 'boolean' ], sorts: [ 'rating', 'created_at' ] )]
    public function index( Request $request, int $product ): JsonResponse
    {
        $model = Product::query()->storefrontVisible()->findOrFail( $product );

        $response = $this->listResponse(
            $model->reviews()->approved()->with( 'media' )->getQuery(),
            $request,
            ProductReviewResource::class,
            [
                'rating'               => [ 'rating', 'int' ],
                'is_verified_purchase' => [ 'is_verified_purchase', 'bool' ],
            ],
            [ 'rating' => 'rating', 'created_at' => 'created_at' ],
        );

        // The rating breakdown for the product page (#181).
        $payload                      = $response->getData( true );
        $payload['meta']['histogram'] = app( ProductRatingAggregator::class )->histogram( $model );
        $payload['meta']['average']   = (float) $model->avg_rating;

        return $response->setData( $payload );
    }

    /**
     * Whether the caller may review `$product`, and whether their review
     * would carry the verified-purchase badge (#181).
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  int      $product  Product id.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Check whether I can review a product' )]
    public function eligibility( Request $request, int $product ): JsonResponse
    {
        $model = Product::query()->storefrontVisible()->findOrFail( $product );

        return new JsonResponse( [ 'data' => $this->reviews->eligibility( $model, Customer::forUser( $request->user() ) )->toArray() ] );
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
            return Problem::make( 403, 'forbidden', __( 'Forbidden' ), __( 'Missing ability :ability.', [ 'ability' => TokenAbilities::STOREFRONT ] ), $request );
        }

        $customer = Customer::forUser( $user );

        if ( null === $customer && ! (bool) config( 'artisanpack.ecommerce.reviews.allow_guests', true ) ) {
            return Problem::make( 401, 'unauthenticated', __( 'Unauthenticated' ), __( 'Sign in to review this product.' ), $request );
        }

        try {
            $review = $this->reviews->submit(
                $model,
                $request->safe()->only( [ 'rating', 'title', 'body', 'author_name', 'author_email', 'order_id' ] ) + [ 'media' => (array) $request->file( 'media', [] ) ],
                $customer,
                $request->filled( ReviewService::honeypotField() ),
            );
        } catch ( ReviewNotAllowedException $e ) {
            return ReviewEligibility::GUESTS_NOT_ALLOWED === $e->reason
                ? Problem::make( 401, 'unauthenticated', __( 'Unauthenticated' ), $e->getMessage(), $request )
                : Problem::make( 403, $e->reason, __( 'Review not allowed' ), $e->getMessage(), $request );
        }

        if ( ! $review instanceof ProductReview ) {
            return Problem::make( 422, 'review-rejected', __( 'Review rejected' ), __( 'The review was rejected by a filter.' ), $request );
        }

        return $this->resourceResponse( $review->load( 'media' ), $request, ProductReviewResource::class, [], 201 );
    }
}
