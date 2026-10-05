<?php

/**
 * ActivityLogController.
 *
 * `GET admin/activity/{products|customers|promotions}/{subject}` (engine
 * issue #147): a subject's activity log, newest first, cursor-paginated.
 * Each route is gated on the subject's own `view` ability by the route
 * middleware. Filter with `filter[event_type]=…`.
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

use ArtisanPackUI\Ecommerce\Http\Resources\ActivityLogEntryResource;
use ArtisanPackUI\Ecommerce\Models\ActivityLogEntry;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ActivityLogController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  Product  $product  Product.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List a product\'s activity', resource: ActivityLogEntryResource::class, collection: true, filters: [ 'event_type' => 'string' ], sorts: [ 'created_at' ] )]
    public function product( Request $request, Product $product ): JsonResponse
    {
        return $this->entries( $request, $product );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request   $request   Request.
     * @param  Customer  $customer  Customer.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List a customer\'s activity', resource: ActivityLogEntryResource::class, collection: true, filters: [ 'event_type' => 'string' ], sorts: [ 'created_at' ] )]
    public function customer( Request $request, Customer $customer ): JsonResponse
    {
        return $this->entries( $request, $customer );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request    $request    Request.
     * @param  Promotion  $promotion  Promotion.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List a promotion\'s activity', resource: ActivityLogEntryResource::class, collection: true, filters: [ 'event_type' => 'string' ], sorts: [ 'created_at' ] )]
    public function promotion( Request $request, Promotion $promotion ): JsonResponse
    {
        return $this->entries( $request, $promotion );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  Model    $subject  Subject.
     *
     * @return JsonResponse
     */
    protected function entries( Request $request, Model $subject ): JsonResponse
    {
        return $this->listResponse(
            ActivityLogEntry::query()->forSubject( $subject ),
            $request,
            ActivityLogEntryResource::class,
            [ 'event_type' => 'event_type' ],
            [ 'created_at' => 'created_at' ],
        );
    }
}
