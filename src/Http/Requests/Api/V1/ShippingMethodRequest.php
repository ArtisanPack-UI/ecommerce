<?php

/**
 * ShippingMethodRequest.
 *
 * Payload for `POST admin/shipping-zones/{zone}/methods` /
 * `PATCH admin/shipping-methods/{method}`. `key` must be a registered
 * shipping-method driver or `provider:{key}` for a registered rate provider.
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

use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Registries\ShippingMethodTypeRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingRateProviderRegistry;
use Closure;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ShippingMethodRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->sometimes( [
            'key'           => [ 'string', 'max:120', function ( string $attribute, mixed $value, Closure $fail ): void {
                if ( ! is_string( $value ) || ! $this->isKnownKey( $value ) ) {
                    $fail( __( 'Unknown shipping method key.' ) );
                }
            } ],
            'label'         => [ 'string', 'max:255' ],
            'config'        => [ 'array' ],
            'tax_class_key' => [ 'nullable', 'string', Rule::exists( 'tax_classes', 'key' ) ],
            'is_active'     => [ 'boolean' ],
            'position'      => [ 'integer', 'min:0' ],
        ], [ 'key', 'label' ] );
    }

    /**
     * Whether `$key` is a registered driver or provider reference.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Method key.
     *
     * @return bool
     */
    protected function isKnownKey( string $key ): bool
    {
        if ( str_starts_with( $key, ShippingMethod::PROVIDER_PREFIX ) ) {
            return app( ShippingRateProviderRegistry::class )->has( substr( $key, strlen( ShippingMethod::PROVIDER_PREFIX ) ) );
        }

        return app( ShippingMethodTypeRegistry::class )->has( $key );
    }
}
