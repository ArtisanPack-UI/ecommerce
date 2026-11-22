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
     * How deep an option value may nest (`frame.mat` is two levels).
     *
     * @since 1.4.0
     *
     * @var int
     */
    public const MAX_OPTION_DEPTH = 3;

    /**
     * The most values an options payload may hold, counting nested ones.
     *
     * @since 1.4.0
     *
     * @var int
     */
    public const MAX_OPTION_VALUES = 100;

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
            ...self::optionRules(),
        ];
    }

    /**
     * The rules for an `options` payload: at most 20 top-level options. Each
     * is a scalar of at most 255 characters, or (for dot- or bracket-named
     * fields such as `frame.mat`) a nested object or list at most
     * {@see self::MAX_OPTION_DEPTH} levels deep, with at most
     * {@see self::MAX_OPTION_VALUES} values in all and the same limit on
     * each value. Whether a nested name is valid is the product type's call
     * (`StorefrontOptionSchema`). Shared with the price quote endpoints.
     *
     * @since 1.4.0
     *
     * @return array<string, array<int, mixed>>
     */
    public static function optionRules(): array
    {
        return [
            'options'   => [ 'nullable', 'array', 'max:20', self::optionTree( ... ) ],
            'options.*' => [ 'nullable', self::optionValue( ... ) ],
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
     * Fails an option value that is longer than 255 characters, nested more
     * than {@see self::MAX_OPTION_DEPTH} levels, or holds anything other
     * than scalars, nulls, and nested lists/objects of them.
     *
     * @since 1.0.0
     * @since 1.4.0 Nested values (dot- or bracket-named fields) are allowed.
     *
     * @param  string   $attribute  Attribute.
     * @param  mixed    $value      Value.
     * @param  Closure  $fail       Failure callback.
     *
     * @return void
     */
    protected static function optionValue( string $attribute, mixed $value, Closure $fail ): void
    {
        $message = self::invalidOption( $value, 1 );

        if ( null !== $message ) {
            $fail( $message );
        }
    }

    /**
     * Fails an options payload with more than
     * {@see self::MAX_OPTION_VALUES} values in all, counting nested ones.
     *
     * @since 1.4.0
     *
     * @param  string   $attribute  Attribute.
     * @param  mixed    $value      Value.
     * @param  Closure  $fail       Failure callback.
     *
     * @return void
     */
    protected static function optionTree( string $attribute, mixed $value, Closure $fail ): void
    {
        $count = 0;

        array_walk_recursive( $value, static function () use ( &$count ): void {
            $count++;
        } );

        if ( $count > self::MAX_OPTION_VALUES ) {
            $fail( __( 'The options may hold at most :max values.', [ 'max' => self::MAX_OPTION_VALUES ] ) );
        }
    }

    /**
     * Why an option value (at `$depth`) is invalid, or null.
     *
     * @since 1.4.0
     *
     * @param  mixed  $value  Value.
     * @param  int    $depth  Nesting level, from 1.
     *
     * @return string|null
     */
    protected static function invalidOption( mixed $value, int $depth ): ?string
    {
        if ( null === $value ) {
            return null;
        }

        if ( is_array( $value ) ) {
            if ( $depth >= self::MAX_OPTION_DEPTH ) {
                return __( 'Options may be nested at most :max levels deep.', [ 'max' => self::MAX_OPTION_DEPTH ] );
            }

            foreach ( $value as $key => $item ) {
                if ( mb_strlen( (string) $key ) > 255 ) {
                    return __( 'Each option may be at most :max characters.', [ 'max' => 255 ] );
                }

                $message = self::invalidOption( $item, $depth + 1 );

                if ( null !== $message ) {
                    return $message;
                }
            }

            return null;
        }

        if ( ! is_scalar( $value ) ) {
            return __( 'Each option must be a single value.' );
        }

        return mb_strlen( (string) $value ) > 255 ? __( 'Each option may be at most :max characters.', [ 'max' => 255 ] ) : null;
    }
}
