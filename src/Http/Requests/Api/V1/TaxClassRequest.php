<?php

/**
 * TaxClassRequest.
 *
 * Payload for `POST admin/tax-classes`.
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

use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class TaxClassRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key'   => [ 'required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique( 'tax_classes', 'key' ) ],
            'label' => [ 'required', 'string', 'max:120' ],
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
            'key.regex'  => __( 'Tax class keys must be lowercase kebab-case.' ),
            'key.unique' => __( 'A tax class with this key already exists.' ),
        ];
    }
}
