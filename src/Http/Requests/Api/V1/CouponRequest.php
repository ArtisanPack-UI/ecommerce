<?php

/**
 * CouponRequest.
 *
 * Payload for `POST admin/promotions/{promotion}/coupons` /
 * `PATCH admin/coupons/{coupon}`. Codes are normalized upper-case before
 * the uniqueness check.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Requests\Api\V1;

use ArtisanPackUI\Ecommerce\Models\Coupon;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CouponRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [ 'required', 'string', 'max:80', Rule::unique( 'coupons', 'code' )->ignore( $this->route( 'coupon' ) ) ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => __( 'That coupon code is already in use.' ),
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if ( is_string( $this->input( 'code' ) ) ) {
            $this->merge( [ 'code' => Coupon::normalize( $this->input( 'code' ) ) ] );
        }
    }
}
