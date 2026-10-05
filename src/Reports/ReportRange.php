<?php

/**
 * ReportRange.
 *
 * The date range, interval, and comparison flag a report runs over (engine
 * issue #146). Dates are whole days in the store time zone
 * (`artisanpack.ecommerce.timezone`, falling back to `app.timezone`), so a
 * "day" bucket starts at the store's midnight rather than UTC's.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Reports;

use ArtisanPackUI\Ecommerce\Support\StoreTimezone;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ReportRange
{
    /**
     * Supported bucket sizes.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const INTERVALS = [ 'day', 'week', 'month' ];

    /**
     * Longest range a report accepts, in days (about three years).
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_DAYS = 1100;

    /**
     * Length of the default range, in days, ending today.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_DAYS = 30;

    /**
     * @since 1.0.0
     *
     * @param  CarbonImmutable  $from      First instant (start of day, store time zone).
     * @param  CarbonImmutable  $to        Last instant (end of day, store time zone).
     * @param  string           $interval  One of {@see self::INTERVALS}.
     * @param  bool             $compare   Whether to also run the previous period.
     *
     * @throws InvalidArgumentException When the range is reversed, too long, or the interval is unknown.
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $interval = 'day',
        public readonly bool $compare = false,
    ) {
        if ( ! in_array( $interval, self::INTERVALS, true ) ) {
            throw new InvalidArgumentException( sprintf( 'Unknown report interval "%s".', $interval ) );
        }

        if ( $from->greaterThan( $to ) ) {
            throw new InvalidArgumentException( 'The report range starts after it ends.' );
        }

        if ( $this->days() > self::MAX_DAYS ) {
            throw new InvalidArgumentException( sprintf( 'Report ranges are limited to %d days.', self::MAX_DAYS ) );
        }
    }

    /**
     * Builds a range from `Y-m-d` strings in the store time zone. A missing
     * `to` is today; a missing `from` is {@see self::DEFAULT_DAYS} days
     * before `to`.
     *
     * @since 1.0.0
     *
     * @param  string|null  $from      First day, `Y-m-d`.
     * @param  string|null  $to        Last day, `Y-m-d`.
     * @param  string       $interval  Bucket size.
     * @param  bool         $compare   Compare with the previous period.
     *
     * @throws InvalidArgumentException When a date cannot be parsed or the range is invalid.
     *
     * @return self
     */
    public static function make( ?string $from = null, ?string $to = null, string $interval = 'day', bool $compare = false ): self
    {
        $timezone = self::timezone();
        $end      = null === $to || '' === $to ? CarbonImmutable::now( $timezone ) : self::parseDay( $to, $timezone );
        $start    = null === $from || '' === $from ? $end->subDays( self::DEFAULT_DAYS - 1 ) : self::parseDay( $from, $timezone );

        return new self( $start->startOfDay(), $end->endOfDay(), $interval, $compare );
    }

    /**
     * The last `$days` days, including today.
     *
     * @since 1.0.0
     *
     * @param  int     $days      Number of days (at least 1).
     * @param  string  $interval  Bucket size.
     *
     * @return self
     */
    public static function lastDays( int $days, string $interval = 'day' ): self
    {
        $today = CarbonImmutable::now( self::timezone() );

        return new self( $today->subDays( max( 1, $days ) - 1 )->startOfDay(), $today->endOfDay(), $interval );
    }

    /**
     * The store time zone.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function timezone(): string
    {
        return StoreTimezone::name();
    }

    /**
     * The period of the same length that ends the day before this one. A
     * range of whole calendar months with a `month` interval steps back by
     * the same number of months (Jul–Sep → Apr–Jun).
     *
     * @since 1.0.0
     *
     * @return self
     */
    public function previous(): self
    {
        // Whole calendar months compared by month: step back by months, so
        // the previous period has the same month buckets.
        if ( 'month' === $this->interval
            && $this->from->isSameDay( $this->from->startOfMonth() )
            && $this->to->isSameDay( $this->to->endOfMonth() ) ) {
            $months = (int) $this->from->startOfMonth()->diffInMonths( $this->to->startOfMonth() ) + 1;

            return new self(
                $this->from->subMonthsNoOverflow( $months )->startOfMonth(),
                $this->from->subDay()->endOfDay(),
                $this->interval,
            );
        }

        $days = $this->days();

        return new self(
            $this->from->subDays( $days )->startOfDay(),
            $this->from->subDay()->endOfDay(),
            $this->interval,
        );
    }

    /**
     * Number of days covered.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function days(): int
    {
        return (int) $this->from->startOfDay()->diffInDays( $this->to->startOfDay() ) + 1;
    }

    /**
     * The range as instants in the application time zone, for comparing
     * against timestamp columns.
     *
     * @since 1.0.0
     *
     * @return array{0: string, 1: string}
     */
    public function queryBounds(): array
    {
        $appTimezone = (string) ( config( 'app.timezone' ) ?: 'UTC' );

        return [
            $this->from->setTimezone( $appTimezone )->format( 'Y-m-d H:i:s' ),
            $this->to->setTimezone( $appTimezone )->format( 'Y-m-d H:i:s' ),
        ];
    }

    /**
     * Every bucket in the range, in order: `key => start date`. Day keys are
     * `Y-m-d`, week keys the Monday that starts the week, month keys `Y-m`.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function buckets(): array
    {
        $buckets = [];
        $cursor  = $this->bucketStart( $this->from );

        while ( $cursor->lessThanOrEqualTo( $this->to ) ) {
            $buckets[ $this->keyFor( $cursor ) ] = $cursor->format( 'Y-m-d' );
            $cursor                              = match ( $this->interval ) {
                'week'  => $cursor->addWeek(),
                'month' => $cursor->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };
        }

        return $buckets;
    }

    /**
     * The bucket key for a timestamp read from the database.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|string  $at  Timestamp (strings are read in the application time zone).
     *
     * @return string
     */
    public function bucketKey( DateTimeInterface|string $at ): string
    {
        $instant = is_string( $at )
            ? CarbonImmutable::parse( $at, (string) ( config( 'app.timezone' ) ?: 'UTC' ) )
            : CarbonImmutable::instance( $at );

        return $this->keyFor( $this->bucketStart( $instant->setTimezone( $this->from->getTimezone() ) ) );
    }

    /**
     * Serializes the range.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from'     => $this->from->format( 'Y-m-d' ),
            'to'       => $this->to->format( 'Y-m-d' ),
            'interval' => $this->interval,
            'timezone' => $this->from->getTimezone()->getName(),
            'compare'  => $this->compare,
        ];
    }

    /**
     * The start of the bucket containing `$at`.
     *
     * @since 1.0.0
     *
     * @param  CarbonImmutable  $at  Instant in the store time zone.
     *
     * @return CarbonImmutable
     */
    protected function bucketStart( CarbonImmutable $at ): CarbonImmutable
    {
        return match ( $this->interval ) {
            'week'  => $at->startOfWeek( CarbonImmutable::MONDAY ),
            'month' => $at->startOfMonth(),
            default => $at->startOfDay(),
        };
    }

    /**
     * The key of a bucket that starts at `$start`.
     *
     * @since 1.0.0
     *
     * @param  CarbonImmutable  $start  Bucket start.
     *
     * @return string
     */
    protected function keyFor( CarbonImmutable $start ): string
    {
        return 'month' === $this->interval ? $start->format( 'Y-m' ) : $start->format( 'Y-m-d' );
    }

    /**
     * Parses a `Y-m-d` day in `$timezone`.
     *
     * @since 1.0.0
     *
     * @param  string  $day       Day.
     * @param  string  $timezone  Time zone.
     *
     * @throws InvalidArgumentException When the day is not a valid date.
     *
     * @return CarbonImmutable
     */
    protected static function parseDay( string $day, string $timezone ): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat( '!Y-m-d', $day, $timezone );
        } catch ( Throwable ) {
            $parsed = false;
        }

        if ( false === $parsed || null === $parsed || $parsed->format( 'Y-m-d' ) !== $day ) {
            throw new InvalidArgumentException( sprintf( 'Report dates must be Y-m-d; got "%s".', $day ) );
        }

        return $parsed;
    }
}
