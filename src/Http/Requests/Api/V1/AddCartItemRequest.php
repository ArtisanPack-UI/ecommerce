<?php

/**
 * AddCartItemRequest.
 *
 * Payload for `POST carts/{cart}/items` (engine spec §9.2). The unit price
 * is never accepted from the client — it is resolved server-side.
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

use Closure;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class AddCartItemRequest extends ApiFormRequest
{
    /**
     * Rules shared with the matching GraphQL mutation. A line takes at
     * most 20 options, each a scalar of at most 255 characters.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public static function baseRules(): array
    {
        return [
            'product_id'         => [ 'required', 'integer', 'min:1' ],
            'product_variant_id' => [ 'nullable', 'integer', 'min:1' ],
            'quantity'           => [ 'required', 'integer', 'min:1', 'max:10000' ],
            'options'            => [ 'nullable', 'array', 'max:20' ],
            'options.*'          => [ 'nullable', self::scalarOption( ... ) ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::baseRules();
    }

    /**
     * Fails an option value that is a list/object or longer than 255 characters.
     *
     * @since 1.0.0
     *
     * @param  string   $attribute  Attribute.
     * @param  mixed    $value      Value.
     * @param  Closure  $fail       Failure callback.
     *
     * @return void
     */
    protected static function scalarOption( string $attribute, mixed $value, Closure $fail ): void
    {
        if ( ! is_scalar( $value ) ) {
            $fail( __( 'Each option must be a single value.' ) );

            return;
        }

        if ( mb_strlen( (string) $value ) > 255 ) {
            $fail( __( 'Each option may be at most :max characters.', [ 'max' => 255 ] ) );
        }
    }
}
