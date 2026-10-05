<?php

/**
 * ConfigSchema.
 *
 * Reads, checks, and enforces the config schemas that
 * {@see DescribesConfig} entries declare (engine issue #149):
 *
 * - {@see self::of()} — an entry's schema, normalized (presence and type
 *   rules spelled out in `rules`), or `null` when it declares none;
 * - {@see self::problems()} — what is wrong with a schema (the contract
 *   tests assert this is empty);
 * - {@see self::rules()} / {@see self::attributes()} — Laravel validation
 *   rules and attribute names for a config, under a key prefix, so form
 *   requests can validate `conditions.0.config` and the like;
 * - {@see self::validate()} — the same check for in-process callers;
 * - {@see self::catalog()} — the `{ key, label, provided_by, config_schema }`
 *   rows the catalog endpoints return.
 *
 * Fields a schema does not declare are left alone, so satellites can keep
 * private keys in `config`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Support;

use ArtisanPackUI\Ecommerce\Contracts\DescribesConfig;
use ArtisanPackUI\Ecommerce\Registries\AbstractContractRegistry;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ConfigSchema
{
    /**
     * The field-type vocabulary.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const TYPES = [
        'text',
        'textarea',
        'number',
        'money',
        'percent',
        'boolean',
        'select',
        'multiselect',
        'product',
        'variant',
        'category',
        'tag',
        'date',
        'weekday',
        'template',
        'url',
        'list',
        'repeater',
        'json',
    ];

    /**
     * Types that take `multiple: true` (a list of values instead of one).
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const MULTIPLE_TYPES = [ 'product', 'variant', 'category', 'tag', 'weekday' ];

    /**
     * Keys a field may carry.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    private const FIELD_KEYS = [ 'name', 'type', 'label', 'required', 'rules', 'help', 'default', 'multiple', 'options', 'fields', 'tokens' ];

    /**
     * The normalized schema `$entry` declares, or `null` when it does not
     * implement {@see DescribesConfig}.
     *
     * @since 1.0.0
     *
     * @param  object  $entry  Registry entry.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function of( object $entry ): ?array
    {
        return $entry instanceof DescribesConfig ? self::normalize( $entry->configSchema() ) : null;
    }

    /**
     * Fills in each field's defaults and spells its presence and type
     * rules out in `rules`, so clients can mirror the server's checks.
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>>  $schema  Declared schema.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function normalize( array $schema ): array
    {
        $normalized = [];

        foreach ( $schema as $field ) {
            $field = (array) $field;
            $type  = (string) ( $field['type'] ?? 'json' );
            $out   = [
                'name'     => (string) ( $field['name'] ?? '' ),
                'type'     => $type,
                'label'    => (string) ( $field['label'] ?? '' ),
                'required' => (bool) ( $field['required'] ?? false ),
                'multiple' => (bool) ( $field['multiple'] ?? false ),
                'help'     => isset( $field['help'] ) ? (string) $field['help'] : null,
            ];

            $out['rules'] = array_values( array_merge(
                [ $out['required'] ? 'required' : 'nullable' ],
                self::typeRules( $type, $out['multiple'], (array) ( $field['options'] ?? [] ) ),
                array_values( array_filter( (array) ( $field['rules'] ?? [] ), 'is_string' ) ),
            ) );

            if ( array_key_exists( 'default', $field ) ) {
                $out['default'] = $field['default'];
            }

            if ( in_array( $type, [ 'select', 'multiselect' ], true ) ) {
                $out['options'] = array_values( (array) ( $field['options'] ?? [] ) );
            }

            if ( 'repeater' === $type ) {
                $out['fields'] = self::normalize( (array) ( $field['fields'] ?? [] ) );
            }

            if ( 'template' === $type ) {
                $out['tokens'] = array_values( array_filter( (array) ( $field['tokens'] ?? [] ), 'is_string' ) );
            }

            $normalized[] = $out;
        }

        return $normalized;
    }

    /**
     * What is wrong with `$schema`; empty when it is well-formed.
     *
     * @since 1.0.0
     *
     * @param  mixed   $schema  Declared schema.
     * @param  string  $path    Path prefix for messages (nested repeaters).
     *
     * @return array<int, string>
     */
    public static function problems( mixed $schema, string $path = '' ): array
    {
        if ( ! is_array( $schema ) || ! array_is_list( $schema ) ) {
            return [ sprintf( '%sschema must be a list of fields.', $path ) ];
        }

        $problems = [];
        $names    = [];

        foreach ( $schema as $index => $field ) {
            $at = sprintf( '%s[%d]', $path, $index );

            if ( ! is_array( $field ) ) {
                $problems[] = sprintf( '%s must be an array.', $at );

                continue;
            }

            $name = $field['name'] ?? null;

            if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $name ) ) {
                $problems[] = sprintf( '%s needs a snake_case "name".', $at );
            } elseif ( in_array( $name, $names, true ) ) {
                $problems[] = sprintf( '%s repeats the name "%s".', $at, $name );
            } else {
                $names[] = $name;
                $at      = sprintf( '%s(%s)', $at, $name );
            }

            foreach ( array_diff( array_keys( $field ), self::FIELD_KEYS ) as $unknown ) {
                $problems[] = sprintf( '%s has an unknown key "%s".', $at, $unknown );
            }

            $type = $field['type'] ?? null;

            if ( ! is_string( $type ) || ! in_array( $type, self::TYPES, true ) ) {
                $problems[] = sprintf( '%s has an unknown type; use one of: %s.', $at, implode( ', ', self::TYPES ) );

                continue;
            }

            if ( ! is_string( $field['label'] ?? null ) || '' === trim( $field['label'] ) ) {
                $problems[] = sprintf( '%s needs a "label".', $at );
            }

            foreach ( [ 'required', 'multiple' ] as $flag ) {
                if ( array_key_exists( $flag, $field ) && ! is_bool( $field[ $flag ] ) ) {
                    $problems[] = sprintf( '%s "%s" must be a boolean.', $at, $flag );
                }
            }

            if ( ( $field['multiple'] ?? false ) === true && ! in_array( $type, self::MULTIPLE_TYPES, true ) ) {
                $problems[] = sprintf( '%s: "multiple" only applies to %s.', $at, implode( ', ', self::MULTIPLE_TYPES ) );
            }

            if ( array_key_exists( 'rules', $field ) && ( ! is_array( $field['rules'] ) || [] !== array_filter( $field['rules'], static fn ( mixed $rule ): bool => ! is_string( $rule ) ) ) ) {
                $problems[] = sprintf( '%s "rules" must be a list of rule strings.', $at );
            }

            if ( array_key_exists( 'help', $field ) && ! is_string( $field['help'] ) ) {
                $problems[] = sprintf( '%s "help" must be a string.', $at );
            }

            if ( in_array( $type, [ 'select', 'multiselect' ], true ) ) {
                $problems = array_merge( $problems, self::optionProblems( $field['options'] ?? null, $at ) );
            }

            if ( 'repeater' === $type ) {
                if ( empty( $field['fields'] ) ) {
                    $problems[] = sprintf( '%s: a repeater needs "fields".', $at );
                } else {
                    $problems = array_merge( $problems, self::problems( $field['fields'], $at . '.fields' ) );
                }
            }

            if ( array_key_exists( 'tokens', $field ) && ( ! is_array( $field['tokens'] ) || [] !== array_filter( $field['tokens'], static fn ( mixed $token ): bool => ! is_string( $token ) ) ) ) {
                $problems[] = sprintf( '%s "tokens" must be a list of strings.', $at );
            }
        }

        return $problems;
    }

    /**
     * Laravel validation rules for a config under `$prefix` (for example
     * `conditions.0.config.`), including the checks that can't be written
     * as rule strings (money amounts, repeater rows).
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>>  $schema  Declared schema.
     * @param  string                            $prefix  Key prefix, with a trailing dot when non-empty.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules( array $schema, string $prefix = '' ): array
    {
        $rules = [];

        foreach ( self::normalize( $schema ) as $field ) {
            $key   = $prefix . $field['name'];
            $set   = $field['rules'];
            $type  = $field['type'];
            $multi = $field['multiple'];

            if ( 'money' === $type ) {
                $set[] = self::moneyRule();
            }

            if ( 'list' === $type ) {
                $set[] = self::listRule();
            }

            $rules[ $key ] = $set;

            $item = match ( true ) {
                'multiselect' === $type                    => [ Rule::in( self::optionValues( $field['options'] ?? [] ) ) ],
                'weekday' === $type && $multi              => [ 'integer', 'between:1,7' ],
                $multi                                     => [ 'integer', 'min:1' ],
                'list' === $type                           => [ 'string' ],
                'repeater' === $type                       => [ 'array' ],
                default                                    => null,
            };

            if ( null !== $item ) {
                $rules[ $key . '.*' ] = $item;
            }

            if ( 'repeater' === $type ) {
                $rules += self::rules( $field['fields'], $key . '.*.' );
            }
        }

        return $rules;
    }

    /**
     * Readable attribute names (the field labels) for validation messages.
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>>  $schema  Declared schema.
     * @param  string                            $prefix  Key prefix, as for {@see self::rules()}.
     *
     * @return array<string, string>
     */
    public static function attributes( array $schema, string $prefix = '' ): array
    {
        $attributes = [];

        foreach ( self::normalize( $schema ) as $field ) {
            $key                = $prefix . $field['name'];
            $attributes[ $key ] = $field['label'];

            if ( 'repeater' === $field['type'] ) {
                $attributes += self::attributes( $field['fields'], $key . '.*.' );
            }
        }

        return $attributes;
    }

    /**
     * Validates `$config` against the schema `$entry` declares. A no-op
     * for entries without one.
     *
     * @since 1.0.0
     *
     * @param  object                $entry   Registry entry.
     * @param  array<string, mixed>  $config  Config to check.
     * @param  string                $prefix  Key prefix for the error keys.
     *
     * @throws ValidationException When the config breaks the schema.
     *
     * @return void
     */
    public static function validate( object $entry, array $config, string $prefix = '' ): void
    {
        if ( ! $entry instanceof DescribesConfig ) {
            return;
        }

        $schema = $entry->configSchema();
        $data   = '' === $prefix ? $config : [];

        if ( '' !== $prefix ) {
            data_set( $data, rtrim( $prefix, '.' ), $config );
        }

        Validator::make( $data, self::rules( $schema, $prefix ), [], self::attributes( $schema, $prefix ) )->validate();
    }

    /**
     * Rules for the config stored under `$prefix` when `$key` names a
     * registered entry that declares a schema; empty otherwise.
     *
     * @since 1.0.0
     *
     * @param  AbstractContractRegistry<object>  $registry  Registry holding the entry.
     * @param  mixed                             $key       Entry key from the request.
     * @param  string                            $prefix    Key prefix.
     *
     * @return array{rules: array<string, array<int, mixed>>, attributes: array<string, string>}
     */
    public static function forRegistryEntry( AbstractContractRegistry $registry, mixed $key, string $prefix ): array
    {
        if ( ! is_string( $key ) || ! $registry->has( $key ) ) {
            return [ 'rules' => [], 'attributes' => [] ];
        }

        $entry = $registry->get( $key );

        if ( ! $entry instanceof DescribesConfig ) {
            return [ 'rules' => [], 'attributes' => [] ];
        }

        $schema = $entry->configSchema();

        return [ 'rules' => self::rules( $schema, $prefix ), 'attributes' => self::attributes( $schema, $prefix ) ];
    }

    /**
     * Catalog rows for every entry in `$registry`: key, label (meta label
     * first), `provided_by`, and the normalized `config_schema` (`null`
     * when the entry declares none).
     *
     * @since 1.0.0
     *
     * @param  AbstractContractRegistry<object>  $registry  Registry.
     *
     * @return array<int, array{key: string, label: string, provided_by: string, config_schema: array<int, array<string, mixed>>|null}>
     */
    public static function catalog( AbstractContractRegistry $registry ): array
    {
        $rows = [];

        foreach ( $registry->keys() as $key ) {
            $entry = $registry->get( $key );
            $meta  = $registry->meta( $key );
            $label = $meta['label'] ?? ( method_exists( $entry, 'label' ) ? $entry->label() : $key );

            $rows[] = [
                'key'           => $key,
                'label'         => (string) ( $label instanceof Closure ? $label() : $label ),
                'provided_by'   => (string) ( $meta['provided_by'] ?? 'ecommerce' ),
                'config_schema' => self::of( $entry ),
            ];
        }

        return $rows;
    }

    /**
     * Rule strings implied by a field's type.
     *
     * @since 1.0.0
     *
     * @param  string             $type      Field type.
     * @param  bool               $multiple  Whether the field takes a list.
     * @param  array<int, mixed>  $options   Select options.
     *
     * @return array<int, string>
     */
    private static function typeRules( string $type, bool $multiple, array $options ): array
    {
        if ( $multiple && in_array( $type, self::MULTIPLE_TYPES, true ) ) {
            return [ 'array' ];
        }

        return match ( $type ) {
            'text', 'textarea', 'template'          => [ 'string' ],
            'number'                                => [ 'numeric' ],
            'percent'                               => [ 'numeric', 'gt:0', 'max:100' ],
            'boolean'                               => [ 'boolean' ],
            'select'                                => [ (string) Rule::in( self::optionValues( $options ) ) ],
            'multiselect', 'repeater'               => [ 'array' ],
            'product', 'variant', 'category', 'tag' => [ 'integer', 'min:1' ],
            'weekday'                               => [ 'integer', 'between:1,7' ],
            'date'                                  => [ 'date' ],
            'url'                                   => [ 'url' ],
            default                                 => [],
        };
    }

    /**
     * A money amount: whole minor units in the store base currency, or a
     * map of ISO 4217 code → minor units (see `CurrencyConverter::fromConfigured()`).
     *
     * @since 1.0.0
     *
     * @return Closure(string, mixed, Closure): void
     */
    private static function moneyRule(): Closure
    {
        return static function ( string $attribute, mixed $value, Closure $fail ): void {
            $amount = static fn ( mixed $minor ): bool => is_int( $minor ) && $minor >= 0;

            if ( $amount( $value ) ) {
                return;
            }

            if ( is_array( $value ) && [] !== $value ) {
                foreach ( $value as $currency => $minor ) {
                    if ( ! is_string( $currency ) || 1 !== preg_match( '/^[A-Za-z]{3}$/', $currency ) || ! $amount( $minor ) ) {
                        $fail( __( 'The :attribute must be whole minor units, or a map of currency codes to whole minor units.' ) );

                        return;
                    }
                }

                return;
            }

            $fail( __( 'The :attribute must be whole minor units, or a map of currency codes to whole minor units.' ) );
        };
    }

    /**
     * A list of strings; a single string counts as a one-item list.
     *
     * @since 1.0.0
     *
     * @return Closure(string, mixed, Closure): void
     */
    private static function listRule(): Closure
    {
        return static function ( string $attribute, mixed $value, Closure $fail ): void {
            if ( ! is_string( $value ) && ! ( is_array( $value ) && array_is_list( $value ) ) ) {
                $fail( __( 'The :attribute must be a list of values.' ) );
            }
        };
    }

    /**
     * @since 1.0.0
     *
     * @param  mixed   $options  Declared options.
     * @param  string  $at       Field path for messages.
     *
     * @return array<int, string>
     */
    private static function optionProblems( mixed $options, string $at ): array
    {
        if ( ! is_array( $options ) || [] === $options || ! array_is_list( $options ) ) {
            return [ sprintf( '%s needs a non-empty list of "options".', $at ) ];
        }

        foreach ( $options as $index => $option ) {
            if ( ! is_array( $option ) || ! is_scalar( $option['value'] ?? null ) || ! is_string( $option['label'] ?? null ) ) {
                return [ sprintf( '%s option %d needs a scalar "value" and a string "label".', $at, $index ) ];
            }
        }

        return [];
    }

    /**
     * @since 1.0.0
     *
     * @param  array<int, mixed>  $options  Declared options.
     *
     * @return array<int, int|string>
     */
    private static function optionValues( array $options ): array
    {
        return array_values( array_map(
            static fn ( mixed $option ): int|string => is_array( $option ) && is_scalar( $option['value'] ?? null ) ? $option['value'] : '',
            $options,
        ) );
    }
}
