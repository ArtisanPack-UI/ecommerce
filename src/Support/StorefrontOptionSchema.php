<?php

/**
 * StorefrontOptionSchema.
 *
 * Validates and evaluates the add-to-cart field schema product types
 * describe through {@see \ArtisanPackUI\Ecommerce\Contracts\ProvidesStorefrontOptions}
 * (#179, #223).
 *
 * - {@see self::normalize()} checks every field and option and drops the
 *   malformed ones with a logged warning (`ecommerce.storefront_options.*`
 *   on the `ecommerce` channel) instead of throwing, so one bad field never
 *   breaks a product page. Malformed option metadata (`price_delta`,
 *   `image`, `swatch`, `hint`, `disabled`) and a malformed `meta.disables`
 *   are dropped on their own; the option or field they sat on is kept.
 * - {@see self::isVisible()} / {@see self::visibleFields()} evaluate a
 *   field's `visible_when` conditions against the submitted values.
 * - {@see self::stripHidden()} removes hidden fields' values from the
 *   submitted options, and {@see self::rules()} / {@see self::validate()}
 *   validate only the visible fields, so product types never validate or
 *   store a value the shopper couldn't see:
 *
 * ```php
 * public function validateCartOptions( Product $product, array $options ): array
 * {
 *     $fields = $this->storefrontOptions( $product );
 *
 *     return app( StorefrontOptionSchema::class )->validate( $fields, $options );
 * }
 * ```
 *
 * Stateless; resolve it from the container.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Support;

use Closure;
use Illuminate\Contracts\Validation\InvokableRule;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Stringable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.4.0
 */
class StorefrontOptionSchema
{
    /**
     * The field types the engine documents. Extend through
     * `ap.ecommerce.storefrontOptions.fieldTypes`.
     *
     * @since 1.4.0
     *
     * @var array<int, string>
     */
    public const FIELD_TYPES = [ 'text', 'textarea', 'number', 'quantity', 'select', 'radio', 'checkbox', 'info', 'swatch' ];

    /**
     * Types that must offer at least one option.
     *
     * @since 1.4.0
     *
     * @var array<int, string>
     */
    public const CHOICE_TYPES = [ 'select', 'radio', 'swatch' ];

    /**
     * The fields that pass validation, in their original order, each with
     * malformed parts removed.
     *
     * @since 1.4.0
     *
     * @param  array<int|string, mixed>  $fields  Fields as a product type described them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function normalize( array $fields ): array
    {
        $types = array_values( array_filter( (array) applyFilters( 'ap.ecommerce.storefrontOptions.fieldTypes', self::FIELD_TYPES ), 'is_string' ) );
        $clean = [];
        $names = [];

        foreach ( $fields as $index => $field ) {
            $normalized = $this->normalizeField( $field, $types, (string) $index );

            if ( null === $normalized ) {
                continue;
            }

            // `frame[mat]` and `frame.mat` are the same option: the first wins.
            $path = $this->path( $normalized['name'] );

            if ( isset( $names[ $path ] ) ) {
                $this->warn( 'duplicate_field', (string) $index, [ 'name' => $normalized['name'] ] );

                continue;
            }

            $names[ $path ]               = true;
            $clean[]                      = $normalized;
        }

        return $clean;
    }

    /**
     * Whether `$field` is shown for `$values`: every `visible_when`
     * condition's field holds one of its `in` values (for a list value,
     * any of them). A field without conditions is always visible.
     *
     * @since 1.4.0
     *
     * @param  array<string, mixed>  $field   Field.
     * @param  array<string, mixed>  $values  Submitted values (nested, keyed by field name).
     *
     * @return bool
     */
    public function isVisible( array $field, array $values ): bool
    {
        foreach ( (array) ( $field['visible_when'] ?? [] ) as $condition ) {
            if ( ! is_array( $condition ) || ! is_string( $condition['field'] ?? null ) || ! is_array( $condition['in'] ?? null ) ) {
                return false;
            }

            $value   = data_get( $values, $this->path( $condition['field'] ) );
            $allowed = array_map( static fn ( mixed $item ): string => self::stringValue( $item ), $condition['in'] );
            $current = is_array( $value ) ? array_values( $value ) : [ $value ];
            $matched = false;

            foreach ( $current as $item ) {
                if ( null !== $item && '' !== self::stringValue( $item ) && in_array( self::stringValue( $item ), $allowed, true ) ) {
                    $matched = true;

                    break;
                }
            }

            if ( ! $matched ) {
                return false;
            }
        }

        return true;
    }

    /**
     * The fields visible for `$values`. A condition on a field that is
     * itself hidden never matches, so hiding cascades down a chain.
     *
     * @since 1.4.0
     *
     * @param  array<int, array<string, mixed>>  $fields  Fields.
     * @param  array<string, mixed>              $values  Submitted values.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visibleFields( array $fields, array $values ): array
    {
        $hidden = $this->hiddenNames( $fields, $values );

        return array_values( array_filter(
            $fields,
            static fn ( mixed $field ): bool => is_array( $field ) && ! in_array( (string) ( $field['name'] ?? '' ), $hidden, true ),
        ) );
    }

    /**
     * `$options` without the values of hidden fields. Field names may use
     * dot or bracket notation (`frame.mat`, `frame[mat]`).
     *
     * @since 1.4.0
     *
     * @param  array<int, array<string, mixed>>  $fields   Fields.
     * @param  array<string, mixed>              $options  Submitted options.
     *
     * @return array<string, mixed>
     */
    public function stripHidden( array $fields, array $options ): array
    {
        foreach ( $this->hiddenNames( $fields, $options ) as $name ) {
            Arr::forget( $options, $this->path( $name ) );
        }

        return $options;
    }

    /**
     * Validation rules for the visible fields: each field's `rules`, with
     * `required` added when the field says so. Keys are dot paths.
     *
     * @since 1.4.0
     *
     * @param  array<int, array<string, mixed>>  $fields   Fields.
     * @param  array<string, mixed>              $options  Submitted options.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules( array $fields, array $options ): array
    {
        $rules = [];

        foreach ( $this->visibleFields( $fields, $options ) as $field ) {
            if ( 'info' === ( $field['type'] ?? null ) || ! is_string( $field['name'] ?? null ) ) {
                continue;
            }

            $fieldRules = is_string( $field['rules'] ?? null ) ? explode( '|', $field['rules'] ) : (array) ( $field['rules'] ?? [] );

            if ( true === ( $field['required'] ?? false ) && ! in_array( 'required', $fieldRules, true ) ) {
                array_unshift( $fieldRules, 'required' );
            }

            if ( [] !== $fieldRules ) {
                $rules[ $this->path( $field['name'] ) ] = array_values( $fieldRules );
            }
        }

        return $rules;
    }

    /**
     * Drops hidden fields' values, validates the visible fields' rules, and
     * returns the remaining options.
     *
     * @since 1.4.0
     *
     * @param  array<int, array<string, mixed>>  $fields   Fields (normalized or raw).
     * @param  array<string, mixed>              $options  Submitted options.
     *
     * @throws ValidationException When a visible field fails; errors are keyed `options.{name}`.
     *
     * @return array<string, mixed>
     */
    public function validate( array $fields, array $options ): array
    {
        $fields    = $this->normalize( $fields );
        $options   = $this->stripHidden( $fields, $options );
        $validator = Validator::make( $options, $this->rules( $fields, $options ) );

        if ( $validator->fails() ) {
            $errors = [];

            foreach ( $validator->errors()->messages() as $key => $messages ) {
                $errors[ 'options.' . $key ] = $messages;
            }

            throw ValidationException::withMessages( $errors );
        }

        return $options;
    }

    /**
     * A field name as a dot path (`frame[mat]` → `frame.mat`).
     *
     * @since 1.4.0
     *
     * @param  string  $name  Field name.
     *
     * @return string
     */
    public function path( string $name ): string
    {
        return trim( str_replace( [ '[', ']' ], [ '.', '' ], $name ), '.' );
    }

    /**
     * Names of the fields hidden for `$values`, resolved to a fixpoint so a
     * field conditioned on a hidden field is hidden too.
     *
     * @since 1.4.0
     *
     * @param  array<int, array<string, mixed>>  $fields  Fields.
     * @param  array<string, mixed>              $values  Submitted values.
     *
     * @return array<int, string>
     */
    protected function hiddenNames( array $fields, array $values ): array
    {
        $hidden = [];

        do {
            $changed = false;
            $visible = $values;

            foreach ( $hidden as $name ) {
                Arr::forget( $visible, $this->path( $name ) );
            }

            foreach ( $fields as $field ) {
                $name = is_array( $field ) && is_string( $field['name'] ?? null ) ? $field['name'] : null;

                if ( null === $name || in_array( $name, $hidden, true ) || $this->isVisible( $field, $visible ) ) {
                    continue;
                }

                $hidden[] = $name;
                $changed  = true;
            }
        } while ( $changed );

        return $hidden;
    }

    /**
     * One field, cleaned, or null when it is malformed.
     *
     * @since 1.4.0
     *
     * @param  mixed               $field  Candidate field.
     * @param  array<int, string>  $types  Allowed types.
     * @param  string              $index  Position, for the log.
     *
     * @return array<string, mixed>|null
     */
    protected function normalizeField( mixed $field, array $types, string $index ): ?array
    {
        if ( ! is_array( $field ) ) {
            return $this->reject( 'invalid_field', $index );
        }

        $type = $field['type'] ?? null;
        $name = $field['name'] ?? null;

        if ( ! is_string( $type ) || ! in_array( $type, $types, true ) ) {
            return $this->reject( 'invalid_type', $index, [ 'type' => is_scalar( $type ) ? (string) $type : gettype( $type ) ] );
        }

        if ( ! is_string( $name ) || 1 !== preg_match( '/^[A-Za-z0-9_\-]+(?:(?:\.[A-Za-z0-9_\-]+)|(?:\[[A-Za-z0-9_\-]+\]))*$/', $name ) ) {
            return $this->reject( 'invalid_name', $index, [ 'type' => $type ] );
        }

        if ( array_key_exists( 'label', $field ) && ! is_string( $field['label'] ) ) {
            return $this->reject( 'invalid_label', $index, [ 'name' => $name ] );
        }

        if ( array_key_exists( 'rules', $field ) && ! $this->validRules( $field['rules'] ) ) {
            return $this->reject( 'invalid_rules', $index, [ 'name' => $name ] );
        }

        if ( array_key_exists( 'options', $field ) || in_array( $type, self::CHOICE_TYPES, true ) ) {
            if ( ! is_array( $field['options'] ?? null ) ) {
                return $this->reject( 'invalid_options', $index, [ 'name' => $name ] );
            }

            $options = [];

            foreach ( $field['options'] as $position => $option ) {
                $clean = $this->normalizeOption( $option, $name, (string) $position );

                if ( null !== $clean ) {
                    $options[] = $clean;
                }
            }

            if ( [] === $options && in_array( $type, self::CHOICE_TYPES, true ) ) {
                return $this->reject( 'no_options', $index, [ 'name' => $name ] );
            }

            $field['options'] = $options;
        }

        if ( array_key_exists( 'visible_when', $field ) ) {
            $conditions = $this->normalizeConditions( $field['visible_when'] );

            if ( null === $conditions ) {
                return $this->reject( 'invalid_visible_when', $index, [ 'name' => $name ] );
            }

            $field['visible_when'] = $conditions;
        }

        if ( array_key_exists( 'meta', $field ) ) {
            if ( ! is_array( $field['meta'] ) ) {
                $this->warn( 'invalid_meta', $index, [ 'name' => $name ] );
                unset( $field['meta'] );
            } elseif ( array_key_exists( 'disables', $field['meta'] ) && ! $this->validDisables( $field['meta']['disables'] ) ) {
                $this->warn( 'invalid_disables', $index, [ 'name' => $name ] );
                unset( $field['meta']['disables'] );
            }
        }

        return $field;
    }

    /**
     * One option, cleaned, or null when it is malformed.
     *
     * @since 1.4.0
     *
     * @param  mixed   $option    Candidate option.
     * @param  string  $field     Field name, for the log.
     * @param  string  $position  Position, for the log.
     *
     * @return array<string, mixed>|null
     */
    protected function normalizeOption( mixed $option, string $field, string $position ): ?array
    {
        $context = [ 'name' => $field, 'option' => $position ];

        if ( ! is_array( $option ) || ! ( is_string( $option['value'] ?? null ) || is_int( $option['value'] ?? null ) || is_float( $option['value'] ?? null ) ) || ! is_string( $option['label'] ?? null ) ) {
            return $this->reject( 'invalid_option', $field, $context );
        }

        if ( array_key_exists( 'price_delta', $option ) ) {
            $delta = $option['price_delta'];

            if ( is_array( $delta ) && is_int( $delta['amount'] ?? null ) && is_string( $delta['currency'] ?? null ) && 1 === preg_match( '/^[A-Za-z]{3}$/', $delta['currency'] ) ) {
                $option['price_delta'] = [ 'amount' => $delta['amount'], 'currency' => strtoupper( $delta['currency'] ) ];
            } else {
                $this->warn( 'invalid_price_delta', $field, $context );
                unset( $option['price_delta'] );
            }
        }

        if ( array_key_exists( 'image', $option ) ) {
            $image = $option['image'];

            if ( is_array( $image ) && is_string( $image['url'] ?? null ) && '' !== trim( $image['url'] ) && ( ! isset( $image['srcset'] ) || is_string( $image['srcset'] ) ) && ( ! isset( $image['alt'] ) || is_string( $image['alt'] ) ) ) {
                $option['image'] = array_filter( [
                    'url'    => $image['url'],
                    'srcset' => $image['srcset'] ?? null,
                    'alt'    => (string) ( $image['alt'] ?? '' ),
                ], static fn ( mixed $value ): bool => null !== $value );
            } else {
                $this->warn( 'invalid_image', $field, $context );
                unset( $option['image'] );
            }
        }

        if ( array_key_exists( 'swatch', $option ) ) {
            $swatch = $option['swatch'];
            $hex    = is_array( $swatch ) ? ( $swatch['hex'] ?? null ) : null;
            $url    = is_array( $swatch ) ? ( $swatch['image_url'] ?? null ) : null;
            $valid  = is_array( $swatch )
                && ( null === $hex || ( is_string( $hex ) && 1 === preg_match( '/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $hex ) ) )
                && ( null === $url || ( is_string( $url ) && '' !== trim( $url ) ) )
                && ( null !== $hex || null !== $url )
                && ( ! isset( $swatch['alt'] ) || is_string( $swatch['alt'] ) );

            if ( $valid ) {
                $option['swatch'] = array_filter( [
                    'hex'       => $hex,
                    'image_url' => $url,
                    'alt'       => (string) ( $swatch['alt'] ?? '' ),
                ], static fn ( mixed $value ): bool => null !== $value );
            } else {
                $this->warn( 'invalid_swatch', $field, $context );
                unset( $option['swatch'] );
            }
        }

        if ( array_key_exists( 'hint', $option ) && ! is_string( $option['hint'] ) ) {
            $this->warn( 'invalid_hint', $field, $context );
            unset( $option['hint'] );
        }

        if ( array_key_exists( 'disabled', $option ) && ! is_bool( $option['disabled'] ) ) {
            $this->warn( 'invalid_disabled', $field, $context );
            unset( $option['disabled'] );
        }

        return $option;
    }

    /**
     * `visible_when` as a clean list, or null when it is malformed.
     *
     * @since 1.4.0
     *
     * @param  mixed  $conditions  Candidate conditions.
     *
     * @return array<int, array{field: string, in: array<int, bool|float|int|string>}>|null
     */
    protected function normalizeConditions( mixed $conditions ): ?array
    {
        if ( ! is_array( $conditions ) || ! array_is_list( $conditions ) ) {
            return null;
        }

        $clean = [];

        foreach ( $conditions as $condition ) {
            if ( ! is_array( $condition ) || ! is_string( $condition['field'] ?? null ) || '' === $condition['field'] || ! is_array( $condition['in'] ?? null ) || [] === $condition['in'] ) {
                return null;
            }

            foreach ( $condition['in'] as $value ) {
                if ( ! is_scalar( $value ) ) {
                    return null;
                }
            }

            // An empty value never matches (an unanswered field isn't a choice), so `''` is dropped.
            $values = array_values( array_filter( $condition['in'], static fn ( mixed $value ): bool => '' !== $value ) );

            if ( [] === $values ) {
                return null;
            }

            $clean[] = [ 'field' => $condition['field'], 'in' => $values ];
        }

        return $clean;
    }

    /**
     * Whether `rules` is something Laravel's validator takes: a pipe string,
     * or a list of strings, closures, and rule objects.
     *
     * @since 1.4.0
     *
     * @param  mixed  $rules  Candidate rules.
     *
     * @return bool
     */
    protected function validRules( mixed $rules ): bool
    {
        if ( null === $rules || is_string( $rules ) ) {
            return true;
        }

        if ( ! is_array( $rules ) ) {
            return false;
        }

        foreach ( $rules as $rule ) {
            if ( ! is_string( $rule ) && ! $rule instanceof Closure && ! $rule instanceof ValidationRule && ! $rule instanceof Rule && ! $rule instanceof InvokableRule && ! $rule instanceof Stringable ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether `meta.disables` is `{ value: { field: [values] } }`.
     *
     * @since 1.4.0
     *
     * @param  mixed  $disables  Candidate.
     *
     * @return bool
     */
    protected function validDisables( mixed $disables ): bool
    {
        if ( ! is_array( $disables ) ) {
            return false;
        }

        foreach ( $disables as $targets ) {
            if ( ! is_array( $targets ) ) {
                return false;
            }

            foreach ( $targets as $field => $values ) {
                if ( ! is_string( $field ) || ! is_array( $values ) ) {
                    return false;
                }

                foreach ( $values as $value ) {
                    if ( ! is_scalar( $value ) ) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Logs why something was dropped and returns null.
     *
     * @since 1.4.0
     *
     * @param  string                $reason   Reason code.
     * @param  string                $where    Field index or name.
     * @param  array<string, mixed>  $context  Extra context.
     *
     * @return null
     */
    protected function reject( string $reason, string $where, array $context = [] ): null
    {
        $this->warn( $reason, $where, $context );

        return null;
    }

    /**
     * Logs a schema warning on the `ecommerce` channel.
     *
     * @since 1.4.0
     *
     * @param  string                $reason   Reason code.
     * @param  string                $where    Field index or name.
     * @param  array<string, mixed>  $context  Extra context.
     *
     * @return void
     */
    protected function warn( string $reason, string $where, array $context = [] ): void
    {
        Log::channel( 'ecommerce' )->warning( 'ecommerce.storefront_options.' . $reason, [ 'field' => $where ] + $context );
    }

    /**
     * A scalar as the string a comparison uses (`true` → `1`).
     *
     * @since 1.4.0
     *
     * @param  mixed  $value  Value.
     *
     * @return string
     */
    protected static function stringValue( mixed $value ): string
    {
        return match ( true ) {
            true === $value     => '1',
            false === $value    => '0',
            is_scalar( $value ) => (string) $value,
            default             => '',
        };
    }
}
