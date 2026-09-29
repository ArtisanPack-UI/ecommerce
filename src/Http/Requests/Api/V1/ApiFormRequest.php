<?php

/**
 * ApiFormRequest.
 *
 * Base form request for the REST API. Authorization is handled by the
 * `ecommerce.can` route middleware, so `authorize()` always passes here;
 * validation failures render as a 422 `problem+json` with one entry per
 * failing field (engine spec §11.5). `PATCH` requests are partial — call
 * {@see self::sometimes()} to prefix rules with `sometimes` on update.
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

use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class ApiFormRequest extends FormRequest
{
    /**
     * @since 1.0.0
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Whether this is a partial update.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    protected function isUpdate(): bool
    {
        return in_array( $this->method(), [ 'PATCH', 'PUT' ], true );
    }

    /**
     * Prefixes each rule set with `sometimes` on partial updates, and with
     * `required` on creates for the keys listed in `$requiredOnCreate`.
     *
     * @since 1.0.0
     *
     * @param  array<string, array<int, mixed>>  $rules             Rules without presence rules.
     * @param  array<int, string>                $requiredOnCreate  Keys required when creating.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function sometimes( array $rules, array $requiredOnCreate = [] ): array
    {
        foreach ( $rules as $key => $set ) {
            if ( $this->isUpdate() ) {
                array_unshift( $set, 'sometimes' );
            } elseif ( in_array( $key, $requiredOnCreate, true ) ) {
                array_unshift( $set, 'required' );
            }

            $rules[ $key ] = $set;
        }

        return $rules;
    }

    /**
     * @since 1.0.0
     *
     * @param  Validator  $validator  Failed validator.
     *
     * @throws HttpResponseException Always.
     *
     * @return void
     */
    protected function failedValidation( Validator $validator ): void
    {
        $errors = [];

        foreach ( $validator->errors()->messages() as $field => $messages ) {
            foreach ( $messages as $message ) {
                $errors[] = [ 'field' => (string) $field, 'code' => 'invalid', 'message' => $message ];
            }
        }

        throw new HttpResponseException( Problem::make(
            422,
            'validation-failed',
            __( 'Validation failed' ),
            __( 'The request payload failed validation.' ),
            $this,
            $errors,
        ) );
    }
}
