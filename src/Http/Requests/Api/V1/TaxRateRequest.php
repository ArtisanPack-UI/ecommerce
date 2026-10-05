<?php

/**
 * TaxRateRequest.
 *
 * Payload for `POST admin/tax-rates` / `PATCH admin/tax-rates/{rate}`.
 * Accepts the rate as `rate_ubps` or as a `rate_percent` decimal string
 * (`"8.375"`), converted without floats via `TaxRateMath`.
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

use ArtisanPackUI\Ecommerce\Models\TaxClass;
use ArtisanPackUI\Ecommerce\Support\TaxRateMath;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class TaxRateRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->sometimes( [
            'tax_class_key'       => [ 'string', Rule::exists( TaxClass::class, 'key' ) ],
            'country_code'        => [ 'string', 'size:2' ],
            'region_code'         => [ 'nullable', 'string', 'max:10' ],
            'postal_pattern'      => [ 'nullable', 'string', 'max:60' ],
            'rate_ubps'           => [ 'integer', 'min:0', 'max:' . TaxRateMath::UNITS_PER_WHOLE ],
            'is_compound'         => [ 'boolean' ],
            'priority'            => [ 'integer' ],
            'label'               => [ 'string', 'max:120' ],
            'is_shipping_taxable' => [ 'boolean' ],
            'is_active'           => [ 'boolean' ],
        ], [ 'tax_class_key', 'country_code', 'rate_ubps', 'label' ] );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tax_class_key.exists' => __( 'Unknown tax class.' ),
            'rate_ubps.required'   => __( 'Provide the rate as rate_ubps or rate_percent.' ),
            'rate_ubps.max'        => __( 'A tax rate cannot exceed 100%.' ),
        ];
    }

    /**
     * Converts `rate_percent` into `rate_ubps` and upper-cases codes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if ( $this->has( 'rate_percent' ) && ! $this->has( 'rate_ubps' ) ) {
            try {
                $this->merge( [ 'rate_ubps' => TaxRateMath::fromPercent( (string) $this->input( 'rate_percent' ) ) ] );
            } catch ( InvalidArgumentException ) {
                $this->merge( [ 'rate_ubps' => 'invalid' ] );
            }
        }

        foreach ( [ 'country_code', 'region_code' ] as $key ) {
            if ( is_string( $this->input( $key ) ) ) {
                $this->merge( [ $key => strtoupper( $this->input( $key ) ) ] );
            }
        }
    }
}
