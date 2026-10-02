<?php

/**
 * SettingDefinition.
 *
 * One allow-listed, admin-editable setting (engine issue #145). The
 * definition names the config value the setting overlays, the group it
 * appears under, how an admin renders it (`type`), and the validation
 * rules a new value must pass.
 *
 * Types are rendering hints for admin satellites:
 *
 * | Type          | Value                         | Notes                                   |
 * |---------------|-------------------------------|-----------------------------------------|
 * | `string`      | string                        |                                         |
 * | `text`        | string                        | Multi-line.                             |
 * | `email`       | string                        |                                         |
 * | `integer`     | int                           |                                         |
 * | `boolean`     | bool                          |                                         |
 * | `select`      | string                        | One of {@see self::options()}.          |
 * | `multiselect` | list of strings               | Each one of {@see self::options()}.     |
 * | `list`        | list of strings               | Free entry (e.g. e-mail addresses).     |
 * | `map`         | `array<string, string>`       | Key/value pairs (e.g. locale → label).  |
 * | `currency`    | ISO 4217 code                 |                                         |
 * | `timezone`    | IANA time zone                |                                         |
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Settings;

use Closure;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class SettingDefinition
{
    /**
     * Supported types.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const TYPES = [ 'string', 'text', 'email', 'integer', 'boolean', 'select', 'multiselect', 'list', 'map', 'currency', 'timezone' ];

    /**
     * Prefix applied to keys that do not name their own config path.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CONFIG_PREFIX = 'artisanpack.ecommerce.';

    /**
     * @since 1.0.0
     *
     * @param  string                                         $key          Setting key, e.g. `tax.provider`. Unique across groups.
     * @param  string                                         $group        Group key the setting appears under.
     * @param  string                                         $type         One of {@see self::TYPES}.
     * @param  string                                         $label        Translated label.
     * @param  array<int, mixed>|Closure(): array<int, mixed> $rules        Laravel validation rules for the value.
     * @param  string|null                                    $description  Translated help text.
     * @param  array<string, string>|Closure(): array<string, string>|null  $options  `value => label` choices for `select` / `multiselect`.
     * @param  string|null                                    $configKey    Config path the setting overlays. Defaults to `artisanpack.ecommerce.{key}`.
     * @param  int                                            $position     Sort order within the group.
     * @param  array<int, mixed>                              $itemRules    Rules for each entry of a `list` / `multiselect` / `map` value.
     *
     * @throws InvalidArgumentException When the key, group, or type is invalid.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $group,
        public readonly string $type,
        public readonly string $label,
        private readonly array|Closure $rules = [],
        public readonly ?string $description = null,
        private readonly array|Closure|null $options = null,
        private readonly ?string $configKey = null,
        public readonly int $position = 0,
        public readonly array $itemRules = [],
    ) {
        if ( '' === trim( $key ) || '' === trim( $group ) ) {
            throw new InvalidArgumentException( 'A setting needs a key and a group.' );
        }

        if ( ! in_array( $type, self::TYPES, true ) ) {
            throw new InvalidArgumentException( sprintf( 'Setting "%s" has unknown type "%s".', $key, $type ) );
        }
    }

    /**
     * Builds a definition from an array, for satellites that prefer arrays.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $definition  Constructor arguments by name.
     *
     * @return self
     */
    public static function fromArray( array $definition ): self
    {
        return new self(
            key: (string) ( $definition['key'] ?? '' ),
            group: (string) ( $definition['group'] ?? '' ),
            type: (string) ( $definition['type'] ?? 'string' ),
            label: (string) ( $definition['label'] ?? ( $definition['key'] ?? '' ) ),
            rules: $definition['rules'] ?? [],
            description: isset( $definition['description'] ) ? (string) $definition['description'] : null,
            options: $definition['options'] ?? null,
            configKey: isset( $definition['config_key'] ) ? (string) $definition['config_key'] : null,
            position: (int) ( $definition['position'] ?? 0 ),
            itemRules: (array) ( $definition['item_rules'] ?? [] ),
        );
    }

    /**
     * The config path this setting overlays.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function configKey(): string
    {
        return $this->configKey ?? self::CONFIG_PREFIX . $this->key;
    }

    /**
     * The validation rules for the value.
     *
     * @since 1.0.0
     *
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        return array_values( $this->rules instanceof Closure ? (array) ( $this->rules )() : $this->rules );
    }

    /**
     * `value => label` choices, resolved now so registries filled during
     * boot are complete.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = $this->options instanceof Closure ? ( $this->options )() : $this->options;

        return array_map( 'strval', (array) ( $options ?? [] ) );
    }

    /**
     * Whether the value is a list (`multiselect`, `list`).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isList(): bool
    {
        return in_array( $this->type, [ 'multiselect', 'list' ], true );
    }

    /**
     * Coerces a submitted value to this setting's type, so `"1"` stores as
     * `true` for a boolean and `"15"` as `15` for an integer. Values that
     * cannot be coerced are returned unchanged for validation to reject.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Submitted value.
     *
     * @return mixed
     */
    public function normalize( mixed $value ): mixed
    {
        if ( is_string( $value ) && 'map' !== $this->type && ! $this->isList() ) {
            $value = trim( $value );
        }

        return match ( $this->type ) {
            'boolean'             => is_bool( $value ) ? $value : ( filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? $value ),
            'integer'             => '' === $value ? null : ( is_numeric( $value ) && (int) $value == $value ? (int) $value : $value ),
            'currency'            => is_string( $value ) ? strtoupper( $value ) : $value,
            'multiselect', 'list' => is_array( $value )
                ? array_values( array_filter( array_map( static fn ( $item ) => is_string( $item ) ? trim( $item ) : $item, $value ), static fn ( $item ): bool => '' !== $item && null !== $item ) )
                : $value,
            'map'      => is_array( $value )
                ? array_filter( array_map( static fn ( $item ) => is_string( $item ) ? trim( $item ) : $item, $value ), static fn ( $item ): bool => '' !== $item && null !== $item )
                : $value,
            default    => '' === $value ? null : $value,
        };
    }

    /**
     * Serializes the definition for an API response.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key'         => $this->key,
            'group'       => $this->group,
            'type'        => $this->type,
            'label'       => $this->label,
            'description' => $this->description,
            'options'     => $this->options(),
            'position'    => $this->position,
        ];
    }
}
