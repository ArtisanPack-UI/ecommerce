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
use ArtisanPackUI\Ecommerce\Http\Support\ValidationErrors;
use ArtisanPackUI\Ecommerce\Registries\AbstractContractRegistry;
use ArtisanPackUI\Ecommerce\Support\ConfigSchema;
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
     * Attribute names collected by {@see self::configRules()}.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    protected array $configAttributes = [];

    /**
     * Config paths (`conditions.0.config`) validated against a schema.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected array $configPaths = [];

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
     * Field labels for the rules {@see self::configRules()} added, so
     * config errors read "The Minimum subtotal field is required."
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->configAttributes;
    }

    /**
     * The validated data, with each schema-checked config restored whole.
     * Laravel keeps only the validated children of an array that has
     * child rules; config keys a schema doesn't declare are left alone.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>|int|string|null  $key      Key to read.
     * @param  mixed                               $default  Default.
     *
     * @return mixed
     */
    public function validated( $key = null, $default = null ): mixed
    {
        $data = parent::validated();

        foreach ( $this->configPaths as $path ) {
            $raw = data_get( $this->validationData(), $path );

            if ( is_array( $raw ) ) {
                data_set( $data, $path, $raw );
            }
        }

        return data_get( $data, $key, $default );
    }

    /**
     * Rules for a registry entry's `config` under `$prefix`, from the
     * schema the entry declares (engine issue #149). Empty when `$key` is
     * not registered or the entry declares no schema.
     *
     * @since 1.0.0
     *
     * @param  AbstractContractRegistry<object>  $registry  Registry holding the entry.
     * @param  mixed                             $key       Entry key.
     * @param  string                            $prefix    Key prefix, e.g. `conditions.0.config.`.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function configRules( AbstractContractRegistry $registry, mixed $key, string $prefix ): array
    {
        $schema = ConfigSchema::forRegistryEntry( $registry, $key, $prefix );

        if ( [] !== $schema['rules'] ) {
            $this->configAttributes = $schema['attributes'] + $this->configAttributes;
            $this->configPaths[]    = rtrim( $prefix, '.' );
        }

        return $schema['rules'];
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
        $errors = ValidationErrors::from( $validator );

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
