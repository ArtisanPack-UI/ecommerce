<?php

/**
 * DigitalFileRequest.
 *
 * Validates `POST|PATCH admin/digital-files` (engine spec §9.9). A file
 * lives on one of `artisanpack.ecommerce.digital.allowed_disks` (`disk` +
 * `path`) or in the media library (`media_id`); paths may not climb out of
 * the disk root.
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

use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Closure;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DigitalFileRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->sometimes( [
            'product_id'         => [ 'nullable', 'integer', Rule::exists( Product::class, 'id' ) ],
            'product_variant_id' => [ 'nullable', 'integer', Rule::exists( ProductVariant::class, 'id' ) ],
            'media_id'           => [ 'nullable', 'integer', 'min:1' ],
            'disk'               => [ 'nullable', 'string', Rule::in( (array) config( 'artisanpack.ecommerce.digital.allowed_disks', [ 'local' ] ) ) ],
            'path'               => [
                'nullable',
                'string',
                'max:1000',
                static function ( string $attribute, mixed $value, Closure $fail ): void {
                    if ( is_string( $value ) && ( str_contains( $value, '..' ) || str_starts_with( $value, '/' ) || str_contains( $value, "\0" ) ) ) {
                        $fail( __( 'The :attribute must be a relative path inside the disk.' ) );
                    }
                },
            ],
            'label'              => [ 'string', 'max:255' ],
            'version'            => [ 'nullable', 'string', 'max:60' ],
            'is_streaming_only'  => [ 'boolean' ],
            'checksum_sha256'    => [ 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/' ],
            'is_archived'        => [ 'boolean' ],
        ], [ 'label' ] );

        if ( ! $this->isUpdate() ) {
            $rules['path'][]     = 'required_without:media_id';
            $rules['media_id'][] = 'required_without:path';
        }

        return $rules;
    }
}
