<?php

/**
 * KanbanCardFilters.
 *
 * The filters a kanban filter bar narrows a board's cards with
 * (ArtisanPack-UI/ecommerce#199), read by {@see KanbanCardQuery}. Build one
 * with {@see self::fromArray()} from request or component state; values it
 * doesn't recognise stay in {@see self::$extra} for satellites that add
 * filters through `ap.ecommerce.kanban.cardQuery`.
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

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
final class KanbanCardFilters
{
    /**
     * The keys {@see self::fromArray()} reads.
     *
     * @since 1.2.0
     *
     * @var array<int, string>
     */
    public const KEYS = [
        'search',
        'customer_id',
        'tags',
        'tag_match',
        'product_ids',
        'placed_from',
        'placed_to',
        'moved_before',
        'stale',
        'payment_status',
        'fulfillment_status',
    ];

    /**
     * Longest search term kept.
     *
     * @since 1.2.0
     *
     * @var int
     */
    public const MAX_SEARCH_LENGTH = 100;

    /**
     * Most values kept for a list filter.
     *
     * @since 1.2.0
     *
     * @var int
     */
    public const MAX_VALUES = 50;

    /**
     * @since 1.2.0
     *
     * @param  string|null                $search             Order number, email, or customer name.
     * @param  int|null                   $customerId         Orders of this customer.
     * @param  array<int, string>         $tags               Order tags.
     * @param  string                     $tagMatch           `any` or `all` of `$tags`.
     * @param  array<int, int>            $productIds         Orders containing one of these products (or their variants).
     * @param  CarbonImmutable|null       $placedFrom         Placed at or after.
     * @param  CarbonImmutable|null       $placedTo           Placed at or before.
     * @param  CarbonImmutable|null       $movedBefore        Cards that reached their column before this (stale cards).
     * @param  array<int, string>         $paymentStatus      Payment statuses.
     * @param  array<int, string>         $fulfillmentStatus  Fulfillment statuses.
     * @param  array<string, mixed>       $extra              Values for satellite filters.
     */
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?int $customerId = null,
        public readonly array $tags = [],
        public readonly string $tagMatch = 'any',
        public readonly array $productIds = [],
        public readonly ?CarbonImmutable $placedFrom = null,
        public readonly ?CarbonImmutable $placedTo = null,
        public readonly ?CarbonImmutable $movedBefore = null,
        public readonly array $paymentStatus = [],
        public readonly array $fulfillmentStatus = [],
        public readonly array $extra = [],
    ) {
    }

    /**
     * No filters.
     *
     * @since 1.2.0
     *
     * @return self
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Reads filters from loosely typed input. Lists may be arrays or
     * comma-separated strings; dates are anything Carbon parses, and a
     * date-only `placed_to` covers the whole day. `stale` (truthy) means
     * "moved before `artisanpack.ecommerce.kanban.stale_after_days` ago"
     * unless `moved_before` is given. Invalid values are dropped.
     *
     * @since 1.2.0
     *
     * @param  array<string, mixed>  $values  Raw filter values.
     *
     * @return self
     */
    public static function fromArray( array $values ): self
    {
        $search = is_scalar( $values['search'] ?? null ) ? trim( (string) $values['search'] ) : '';
        $search = '' === $search ? null : mb_substr( $search, 0, self::MAX_SEARCH_LENGTH );

        $customerId = $values['customer_id'] ?? null;
        $customerId = is_numeric( $customerId ) && (int) $customerId > 0 ? (int) $customerId : null;

        $movedBefore = self::date( $values['moved_before'] ?? null );

        if ( null === $movedBefore && filter_var( $values['stale'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
            $movedBefore = CarbonImmutable::now()->subDays( max( 1, (int) config( 'artisanpack.ecommerce.kanban.stale_after_days', 3 ) ) );
        }

        $placedTo = self::date( $values['placed_to'] ?? null );

        if ( null !== $placedTo && is_string( $values['placed_to'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/D', trim( $values['placed_to'] ) ) ) {
            $placedTo = $placedTo->endOfDay();
        }

        return new self(
            search: $search,
            customerId: $customerId,
            tags: self::strings( $values['tags'] ?? [] ),
            tagMatch: 'all' === ( $values['tag_match'] ?? null ) ? 'all' : 'any',
            productIds: array_values( array_filter( array_map( 'intval', array_filter( self::list( $values['product_ids'] ?? [] ), 'is_numeric' ) ), static fn ( int $id ): bool => $id > 0 ) ),
            placedFrom: self::date( $values['placed_from'] ?? null ),
            placedTo: $placedTo,
            movedBefore: $movedBefore,
            paymentStatus: self::strings( $values['payment_status'] ?? [] ),
            fulfillmentStatus: self::strings( $values['fulfillment_status'] ?? [] ),
            extra: array_diff_key( $values, array_flip( self::KEYS ) ),
        );
    }

    /**
     * Whether no core or satellite filter is set.
     *
     * @since 1.2.0
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return null === $this->search
            && null === $this->customerId
            && [] === $this->tags
            && [] === $this->productIds
            && null === $this->placedFrom
            && null === $this->placedTo
            && null === $this->movedBefore
            && [] === $this->paymentStatus
            && [] === $this->fulfillmentStatus
            && [] === array_filter( $this->extra, static fn ( mixed $value ): bool => null !== $value && '' !== $value && [] !== $value );
    }

    /**
     * @since 1.2.0
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'search'             => $this->search,
            'customer_id'        => $this->customerId,
            'tags'               => $this->tags,
            'tag_match'          => $this->tagMatch,
            'product_ids'        => $this->productIds,
            'placed_from'        => $this->placedFrom?->toIso8601String(),
            'placed_to'          => $this->placedTo?->toIso8601String(),
            'moved_before'       => $this->movedBefore?->toIso8601String(),
            'payment_status'     => $this->paymentStatus,
            'fulfillment_status' => $this->fulfillmentStatus,
        ] + $this->extra;
    }

    /**
     * A list from an array or a comma-separated string.
     *
     * @since 1.2.0
     *
     * @param  mixed  $value  Raw value.
     *
     * @return array<int, mixed>
     */
    private static function list( mixed $value ): array
    {
        if ( is_string( $value ) ) {
            $value = explode( ',', $value );
        }

        return array_slice( is_array( $value ) ? array_values( $value ) : [], 0, self::MAX_VALUES );
    }

    /**
     * Distinct non-empty trimmed strings from a list.
     *
     * @since 1.2.0
     *
     * @param  mixed  $value  Raw value.
     *
     * @return array<int, string>
     */
    private static function strings( mixed $value ): array
    {
        $strings = array_map( static fn ( mixed $item ): string => is_scalar( $item ) ? trim( (string) $item ) : '', self::list( $value ) );

        return array_values( array_unique( array_filter( $strings, static fn ( string $item ): bool => '' !== $item ) ) );
    }

    /**
     * @since 1.2.0
     *
     * @param  mixed  $value  Raw date.
     *
     * @return CarbonImmutable|null
     */
    private static function date( mixed $value ): ?CarbonImmutable
    {
        if ( $value instanceof DateTimeInterface ) {
            return CarbonImmutable::instance( $value );
        }

        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            return null;
        }

        try {
            return CarbonImmutable::parse( trim( $value ) );
        } catch ( Throwable ) {
            return null;
        }
    }
}
