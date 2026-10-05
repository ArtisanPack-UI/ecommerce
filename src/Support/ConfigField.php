<?php

/**
 * ConfigField.
 *
 * Builds the field arrays a {@see \ArtisanPackUI\Ecommerce\Contracts\DescribesConfig}
 * entry returns (engine issue #149):
 *
 * ```php
 * return [
 *     ConfigField::make( 'amount', 'money', __( 'Minimum subtotal' ), [ 'required' => true ] ),
 *     ConfigField::make( 'match', 'select', __( 'Match' ), [
 *         'options' => ConfigField::options( [ 'any' => __( 'Any' ), 'all' => __( 'All' ) ] ),
 *         'default' => 'any',
 *     ] ),
 * ];
 * ```
 *
 * Plain arrays work just as well; this only saves typing.
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

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ConfigField
{
    /**
     * One field.
     *
     * @since 1.0.0
     *
     * @param  string                $name   Config key.
     * @param  string                $type   One of {@see ConfigSchema::TYPES}.
     * @param  string                $label  Translated label.
     * @param  array<string, mixed>  $extra  `required`, `rules`, `help`, `default`, `multiple`, `options`, `fields`, `tokens`.
     *
     * @return array<string, mixed>
     */
    public static function make( string $name, string $type, string $label, array $extra = [] ): array
    {
        return [ 'name' => $name, 'type' => $type, 'label' => $label ] + $extra;
    }

    /**
     * Select options from a `value => label` map.
     *
     * @since 1.0.0
     *
     * @param  array<int|string, string>  $labels  Value → label.
     *
     * @return array<int, array{value: int|string, label: string}>
     */
    public static function options( array $labels ): array
    {
        $options = [];

        foreach ( $labels as $value => $label ) {
            $options[] = [ 'value' => $value, 'label' => $label ];
        }

        return $options;
    }
}
