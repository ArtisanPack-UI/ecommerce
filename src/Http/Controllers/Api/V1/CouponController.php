<?php

/**
 * CouponController.
 *
 * `POST admin/promotions/{promotion}/coupons`,
 * `PATCH|DELETE admin/coupons/{coupon}` (engine spec §9.7).
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\CouponRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\CouponResource;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CouponController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  CouponRequest  $request    Validated request.
     * @param  Promotion      $promotion  Promotion.
     *
     * @return JsonResponse
     */
    public function store( CouponRequest $request, Promotion $promotion ): JsonResponse
    {
        return $this->resourceResponse( $promotion->coupons()->create( $request->validated() ), $request, CouponResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  CouponRequest  $request  Validated request.
     * @param  Coupon         $coupon   Coupon.
     *
     * @return JsonResponse
     */
    public function update( CouponRequest $request, Coupon $coupon ): JsonResponse
    {
        $coupon->fill( $request->validated() )->save();

        return $this->resourceResponse( $coupon, $request, CouponResource::class );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  Coupon   $coupon   Coupon.
     *
     * @return JsonResponse
     */
    public function destroy( Request $request, Coupon $coupon ): JsonResponse
    {
        $coupon->delete();

        return $this->resourceResponse( $coupon, $request, CouponResource::class );
    }
}
