<?php

/**
 * ValidationErrors.
 *
 * Turns a failed validator into the API's field-error list (audit F7): each
 * error's `code` is the kebab-cased rule that failed (`required`, `max`,
 * `email`, `unique`, `in`, `required-with`, …), so clients can react to a
 * failure without parsing the message. Rule objects and closures report
 * `invalid`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Support;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ValidationErrors
{
    /**
     * The field errors of a failed validator.
     *
     * @since 1.0.0
     *
     * @param  Validator  $validator  Failed validator.
     *
     * @return array<int, array{field: string, code: string, message: string}>
     */
    public static function from( Validator $validator ): array
    {
        $failed = method_exists( $validator, 'failed' ) ? (array) $validator->failed() : [];
        $errors = [];

        foreach ( $validator->errors()->messages() as $field => $messages ) {
            $rules = array_keys( (array) ( $failed[ $field ] ?? [] ) );

            foreach ( array_values( $messages ) as $index => $message ) {
                $errors[] = [ 'field' => (string) $field, 'code' => self::code( $rules[ $index ] ?? null ), 'message' => $message ];
            }
        }

        return $errors;
    }

    /**
     * The error code for a failed rule.
     *
     * @since 1.0.0
     *
     * @param  string|null  $rule  Rule name as the validator reports it.
     *
     * @return string
     */
    public static function code( ?string $rule ): string
    {
        if ( null === $rule || '' === $rule || str_contains( $rule, '\\' ) || 'Closure' === $rule ) {
            return 'invalid';
        }

        return Str::kebab( $rule );
    }
}
