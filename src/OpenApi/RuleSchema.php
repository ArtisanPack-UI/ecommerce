<?php

/**
 * RuleSchema.
 *
 * Converts Laravel validation rules (a FormRequest's `rules()`) into a
 * JSON Schema object for an OpenAPI request body. Dotted keys become
 * nested properties and `*` segments become array items, so
 * `lines.*.order_item_id` documents an array of objects. Rules with no
 * JSON Schema equivalent (`unique`, `exists`, closures) are skipped — the
 * API still enforces them and reports failures as 422.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\OpenApi;

use Illuminate\Validation\Rules\In;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class RuleSchema
{
    /**
     * Rule name → JSON Schema type.
     *
     * @since 1.0.0
     *
     * @var array<string, array<string, string>>
     */
    private const TYPES = [
        'string'  => [ 'type' => 'string' ],
        'alpha'   => [ 'type' => 'string' ],
        'integer' => [ 'type' => 'integer' ],
        'numeric' => [ 'type' => 'number' ],
        'boolean' => [ 'type' => 'boolean' ],
        'array'   => [ 'type' => 'array' ],
        'email'   => [ 'type' => 'string', 'format' => 'email' ],
        'url'     => [ 'type' => 'string', 'format' => 'uri' ],
        'uuid'    => [ 'type' => 'string', 'format' => 'uuid' ],
        'date'    => [ 'type' => 'string', 'format' => 'date-time' ],
    ];

    /**
     * Builds an object schema from a rules array.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $rules  Field → rules.
     *
     * @return array<string, mixed>
     */
    public static function fromRules( array $rules ): array
    {
        $root = [ 'type' => 'object', 'properties' => [] ];

        uksort( $rules, static fn ( string $a, string $b ): int => substr_count( $a, '.' ) <=> substr_count( $b, '.' ) ?: strcmp( $a, $b ) );

        foreach ( $rules as $key => $set ) {
            self::place( $root, explode( '.', (string) $key ), is_string( $set ) ? explode( '|', $set ) : (array) $set );
        }

        return self::tidy( $root );
    }

    /**
     * Places one rule set at a dotted path.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $node      Current schema node.
     * @param  array<int, string>    $segments  Remaining path segments.
     * @param  array<int, mixed>     $set       Rules.
     *
     * @return void
     */
    private static function place( array &$node, array $segments, array $set ): void
    {
        $segment = array_shift( $segments );

        if ( '*' === $segment ) {
            $node['type'] ??= 'array';
            $node['items'] ??= [];
            $child = &$node['items'];
        } else {
            $node['type'] ??= 'object';
            $node['properties'][ $segment ] ??= [];
            $child = &$node['properties'][ $segment ];
        }

        if ( [] === $segments ) {
            self::apply( $child, $set );

            if ( '*' !== $segment && in_array( 'required', $set, true ) ) {
                $node['required'][] = $segment;
            }

            return;
        }

        self::place( $child, $segments, $set );
    }

    /**
     * Applies one field's rules to its schema node.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $schema  Node.
     * @param  array<int, mixed>     $set     Rules.
     *
     * @return void
     */
    private static function apply( array &$schema, array $set ): void
    {
        $nullable = false;
        $limits   = [];

        foreach ( $set as $rule ) {
            if ( $rule instanceof In ) {
                $schema['enum'] = str_getcsv( substr( (string) $rule, 3 ), ',', '"', '\\' );

                continue;
            }

            if ( ! is_string( $rule ) ) {
                continue;
            }

            [ $name, $argument ] = array_pad( explode( ':', $rule, 2 ), 2, null );

            match ( true ) {
                'nullable' === $name                              => $nullable          = true,
                isset( self::TYPES[ $name ] )                     => $schema            = self::TYPES[ $name ] + $schema,
                in_array( $name, [ 'min', 'max', 'size' ], true ) => $limits[ $name ]   = (int) $argument,
                'in' === $name                                    => $schema['enum']    = explode( ',', (string) $argument ),
                'regex' === $name                                 => $schema['pattern'] = self::pattern( (string) $argument ),
                default                                           => null,
            };

            if ( 'url' === $name && null !== $argument ) {
                $schema['description'] = sprintf( 'Scheme: %s.', str_replace( ',', ' or ', $argument ) );
            }
        }

        $type = $schema['type'] ?? null;

        foreach ( $limits as $name => $value ) {
            $keys = match ( $type ) {
                'string'            => [ 'min' => 'minLength', 'max' => 'maxLength', 'size' => [ 'minLength', 'maxLength' ] ],
                'array'             => [ 'min' => 'minItems', 'max' => 'maxItems', 'size' => [ 'minItems', 'maxItems' ] ],
                'integer', 'number' => [ 'min' => 'minimum', 'max' => 'maximum', 'size' => [ 'minimum', 'maximum' ] ],
                default             => [],
            };

            foreach ( (array) ( $keys[ $name ] ?? [] ) as $key ) {
                $schema[ $key ] = $value;
            }
        }

        if ( $nullable ) {
            $schema['type'] = null === $type ? [ 'null' ] : [ $type, 'null' ];

            if ( [ 'null' ] === $schema['type'] ) {
                unset( $schema['type'] );
            }
        }
    }

    /**
     * Strips PHP regex delimiters and flags.
     *
     * @since 1.0.0
     *
     * @param  string  $regex  PHP regex.
     *
     * @return string
     */
    private static function pattern( string $regex ): string
    {
        $delimiter = $regex[0] ?? '/';
        $end       = strrpos( $regex, $delimiter );

        return false !== $end && $end > 0 ? substr( $regex, 1, $end - 1 ) : $regex;
    }

    /**
     * Drops empty `properties` / `required` keys and de-duplicates `required`.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $schema  Schema.
     *
     * @return array<string, mixed>
     */
    private static function tidy( array $schema ): array
    {
        if ( isset( $schema['properties'] ) ) {
            $schema['properties'] = array_map( self::tidy( ... ), $schema['properties'] );

            if ( [] === $schema['properties'] ) {
                $schema['properties'] = (object) [];
            }
        }

        if ( isset( $schema['items'] ) ) {
            $schema['items'] = self::tidy( $schema['items'] );

            // A `*` child with no type rule says nothing about the values;
            // treat it like no child at all.
            if ( [] === $schema['items'] ) {
                unset( $schema['items'] );
            }
        }

        // An `array` rule with no typed `*` children is a map (options,
        // meta, config, …), not a list (audit F6).
        $types = (array) ( $schema['type'] ?? [] );

        if ( in_array( 'array', $types, true ) && ! isset( $schema['items'] ) ) {
            $schema['type'] = 1 === count( $types ) ? 'object' : array_values( array_map( static fn ( string $type ): string => 'array' === $type ? 'object' : $type, $types ) );

            $schema['additionalProperties'] ??= true;

            foreach ( [ 'minItems' => 'minProperties', 'maxItems' => 'maxProperties' ] as $from => $to ) {
                if ( isset( $schema[ $from ] ) ) {
                    $schema[ $to ] = $schema[ $from ];
                    unset( $schema[ $from ] );
                }
            }
        }

        if ( isset( $schema['required'] ) ) {
            $schema['required'] = array_values( array_unique( $schema['required'] ) );
        }

        return $schema;
    }
}
