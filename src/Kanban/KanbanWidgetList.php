<?php

/**
 * KanbanWidgetList.
 *
 * The shape of a card widget list — a column's `card_widgets` or a board's
 * `settings.card_widgets` (ArtisanPack-UI/ecommerce#202). Each entry is
 * either a registered widget key (the 1.x shape) or
 * `{ "key": string, "config": object }`. Config is checked against the
 * widget's {@see DescribesConfig::configSchema()}; a widget that declares a
 * schema rejects config keys the schema doesn't name.
 *
 * Shared by the REST requests, {@see \ArtisanPackUI\Ecommerce\Services\KanbanColumnService},
 * and {@see KanbanCardRenderer}, so every writer and the renderer agree.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Kanban;

use ArtisanPackUI\Ecommerce\Contracts\DescribesConfig;
use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
use ArtisanPackUI\Ecommerce\Support\ConfigSchema;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
final class KanbanWidgetList
{
    /**
     * Most entries a widget list may hold.
     *
     * @since 1.2.0
     *
     * @var int
     */
    public const MAX_ENTRIES = 50;

    /**
     * Most settings one entry's config may hold. Widgets without a declared
     * schema keep their config unchecked otherwise, so satellites can store
     * private keys.
     *
     * @since 1.2.0
     *
     * @var int
     */
    public const MAX_CONFIG_KEYS = 50;

    /**
     * Entries as `{ key, config }` pairs, in order. Malformed entries are
     * dropped and a repeated key keeps its first entry.
     *
     * @since 1.2.0
     *
     * @param  mixed  $entries  Stored or submitted list.
     *
     * @return array<int, array{key: string, config: array<string, mixed>}>
     */
    public static function normalize( mixed $entries ): array
    {
        $normalized = [];

        foreach ( is_array( $entries ) ? $entries : [] as $entry ) {
            $key    = is_string( $entry ) ? $entry : ( is_array( $entry ) && is_string( $entry['key'] ?? null ) ? $entry['key'] : null );
            $config = is_array( $entry ) && is_array( $entry['config'] ?? null ) ? $entry['config'] : [];

            if ( null === $key || '' === $key || isset( $normalized[ $key ] ) ) {
                continue;
            }

            $normalized[ $key ] = [ 'key' => $key, 'config' => $config ];
        }

        return array_values( $normalized );
    }

    /**
     * The widget keys in a list, in order.
     *
     * @since 1.2.0
     *
     * @param  mixed  $entries  Stored or submitted list.
     *
     * @return array<int, string>
     */
    public static function keys( mixed $entries ): array
    {
        return array_column( self::normalize( $entries ), 'key' );
    }

    /**
     * Validation rules for the list submitted under `$attribute`
     * (`card_widgets`, `settings.card_widgets`). The rules depend on the
     * submitted entries, so pass what was submitted.
     *
     * @since 1.2.0
     *
     * @param  string  $attribute  Key of the list.
     * @param  mixed   $entries    Submitted list.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules( string $attribute, mixed $entries ): array
    {
        $registry = app( KanbanCardWidgetRegistry::class );
        $keys     = $registry->keys();
        $rules    = [ $attribute => [ 'array', 'list', 'max:' . self::MAX_ENTRIES ] ];

        if ( ! is_array( $entries ) ) {
            return $rules;
        }

        foreach ( array_keys( $entries ) as $index ) {
            $at    = $attribute . '.' . $index;
            $entry = $entries[ $index ];

            if ( ! is_array( $entry ) ) {
                $rules[ $at ] = [ 'string', Rule::in( $keys ) ];

                continue;
            }

            $rules[ $at ]             = [ 'array:key,config' ];
            $rules[ $at . '.key' ]    = [ 'required', 'string', Rule::in( $keys ) ];
            $rules[ $at . '.config' ] = [ 'sometimes', 'array', 'max:' . self::MAX_CONFIG_KEYS ];

            $key = $entry['key'] ?? null;

            if ( ! is_string( $key ) || ! $registry->has( $key ) || ! $registry->get( $key ) instanceof DescribesConfig ) {
                continue;
            }

            $schema                     = $registry->get( $key )->configSchema();
            $rules[ $at . '.config' ][] = self::onlyDeclared( $schema );
            $rules += ConfigSchema::rules( $schema, $at . '.config.' );
        }

        return $rules;
    }

    /**
     * Readable attribute names for the config fields in a submitted list.
     *
     * @since 1.2.0
     *
     * @param  string  $attribute  Key of the list.
     * @param  mixed   $entries    Submitted list.
     *
     * @return array<string, string>
     */
    public static function attributes( string $attribute, mixed $entries ): array
    {
        $registry   = app( KanbanCardWidgetRegistry::class );
        $attributes = [];

        foreach ( is_array( $entries ) ? $entries : [] as $index => $entry ) {
            $key = is_array( $entry ) ? ( $entry['key'] ?? null ) : null;

            if ( is_string( $key ) && $registry->has( $key ) && $registry->get( $key ) instanceof DescribesConfig ) {
                $attributes += ConfigSchema::attributes( $registry->get( $key )->configSchema(), $attribute . '.' . $index . '.config.' );
            }
        }

        return $attributes;
    }

    /**
     * Validates a list for in-process writers.
     *
     * @since 1.2.0
     *
     * @param  mixed   $entries    List to check.
     * @param  string  $attribute  Key used in the error bag.
     *
     * @throws ValidationException When an entry names an unknown widget or its config breaks the widget's schema.
     *
     * @return void
     */
    public static function validate( mixed $entries, string $attribute = 'card_widgets' ): void
    {
        $data = [];
        data_set( $data, $attribute, $entries );

        Validator::make( $data, self::rules( $attribute, $entries ), [], self::attributes( $attribute, $entries ) )->validate();
    }

    /**
     * Rejects config keys the widget's schema doesn't declare.
     *
     * @since 1.2.0
     *
     * @param  array<int, array<string, mixed>>  $schema  Declared schema.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function onlyDeclared( array $schema ): Closure
    {
        $declared = array_column( ConfigSchema::normalize( $schema ), 'name' );

        return static function ( string $attribute, mixed $value, Closure $fail ) use ( $declared ): void {
            $unknown = array_diff( array_map( 'strval', array_keys( (array) $value ) ), $declared );

            if ( [] !== $unknown ) {
                $fail( __( 'The :attribute has unknown settings: :keys.', [ 'keys' => implode( ', ', $unknown ) ] ) );
            }
        };
    }
}
