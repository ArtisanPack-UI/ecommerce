<?php

/**
 * Timestamp.
 *
 * The one timestamp format the engine puts on the wire — REST, GraphQL, and
 * webhook payloads (audit F15): RFC 3339 in UTC, to the second
 * (`2026-03-02T15:04:05Z`).
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

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class Timestamp
{
    /**
     * `$value` as an RFC 3339 UTC string, or null.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|null  $value  Moment.
     *
     * @return string|null
     */
    public static function format( ?DateTimeInterface $value ): ?string
    {
        return null === $value ? null : Carbon::instance( $value )->utc()->toIso8601ZuluString();
    }

    /**
     * Formats every date in a (nested) array.
     *
     * @since 1.0.0
     *
     * @param  array<array-key, mixed>  $values  Values.
     *
     * @return array<array-key, mixed>
     */
    public static function inArray( array $values ): array
    {
        foreach ( $values as $key => $value ) {
            if ( $value instanceof DateTimeInterface ) {
                $values[ $key ] = self::format( $value );
            } elseif ( is_array( $value ) ) {
                $values[ $key ] = self::inArray( $value );
            }
        }

        return $values;
    }
}
