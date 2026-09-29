<?php

/**
 * ListQuery.
 *
 * Parses and applies the REST listing parameters from parent plan §12.1:
 *
 * - `include=a,b.c`       — eager-load allow-listed relations; an include
 *                           may carry a query constraint.
 * - `filter[field]=v1,v2` — exact-match (comma list → `IN`) on allow-listed
 *                           fields. A filter maps to a column, a typed
 *                           `[ column, 'bool'|'int' ]` pair (values are
 *                           coerced; bad input is a 400), or a closure.
 * - `sort=-field,other`   — allow-listed sort columns, `-` for descending.
 *                           `id` is always appended as a tie-breaker so
 *                           cursor pagination is stable.
 * - `per_page`, `cursor`  — cursor pagination, capped by
 *                           `artisanpack.ecommerce.api.max_per_page`.
 *
 * Anything outside the allow-lists is a 400 `problem+json` — typos in
 * `include` / `filter` / `sort` fail loudly instead of being ignored.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Support;

use Closure;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ListQuery
{
    /**
     * Relations requested via `include`, validated against the allow-list.
     *
     * @since 1.0.0
     *
     * @param  Request                                            $request   Request.
     * @param  array<string, array{0: string, 1: Closure}|string>  $allowed   Public include name → relation path, or [ path, constraint ].
     *
     * @throws HttpResponseException 400 when an include is not allowed.
     *
     * @return array<int|string, Closure|string> Argument for `with()` / `loadMissing()`.
     */
    public static function includes( Request $request, array $allowed ): array
    {
        $requested = self::csv( $request->query( 'include' ) );
        $unknown   = array_diff( $requested, array_keys( $allowed ) );

        if ( [] !== $unknown ) {
            self::fail( $request, 'include', sprintf(
                'Unsupported include "%s". Allowed: %s.',
                implode( ', ', $unknown ),
                [] === $allowed ? '(none)' : implode( ', ', array_keys( $allowed ) ),
            ) );
        }

        $with = [];

        foreach ( $requested as $name ) {
            $target = $allowed[ $name ];

            if ( is_array( $target ) ) {
                $with[ $target[0] ] = $target[1];
            } else {
                $with[] = $target;
            }
        }

        return $with;
    }

    /**
     * Applies `filter[...]` and `sort` to `$query`.
     *
     * @since 1.0.0
     *
     * @param  Builder<Model>                       $query        Query.
     * @param  Request                              $request      Request.
     * @param  array<string, Closure|string>        $filters      Public filter name → column, or closure( Builder, string $value ).
     * @param  array<string, string>                $sorts        Public sort name → column.
     * @param  string                               $defaultSort  Sort applied when none is requested.
     *
     * @throws HttpResponseException 400 on an unknown filter or sort.
     *
     * @return Builder<Model>
     */
    public static function apply( Builder $query, Request $request, array $filters, array $sorts, string $defaultSort = '-id' ): Builder
    {
        $requestedFilters = $request->query( 'filter', [] );

        if ( ! is_array( $requestedFilters ) ) {
            self::fail( $request, 'filter', 'The filter parameter must be of the form filter[field]=value.' );
        }

        foreach ( $requestedFilters as $name => $value ) {
            if ( ! isset( $filters[ $name ] ) ) {
                self::fail( $request, 'filter', sprintf(
                    'Unsupported filter "%s". Allowed: %s.',
                    $name,
                    [] === $filters ? '(none)' : implode( ', ', array_keys( $filters ) ),
                ) );
            }

            if ( ! is_scalar( $value ) ) {
                self::fail( $request, 'filter', sprintf( 'Filter "%s" must be a scalar value.', $name ) );
            }

            $target = $filters[ $name ];

            if ( $target instanceof Closure ) {
                $target( $query, (string) $value );

                continue;
            }

            [ $column, $type ] = is_array( $target ) ? $target : [ $target, 'string' ];

            $values = array_map(
                static fn ( string $v ): bool|int|string => self::coerce( $v, $type, $name, $request ),
                self::csv( (string) $value ),
            );

            count( $values ) > 1 ? $query->whereIn( $column, $values ) : $query->where( $column, $values[0] ?? '' );
        }

        $sortParam  = $request->query( 'sort', $defaultSort );
        $sortedById = false;

        foreach ( self::csv( is_string( $sortParam ) ? $sortParam : '' ) as $sort ) {
            $descending = str_starts_with( $sort, '-' );
            $name       = ltrim( $sort, '-' );

            if ( 'id' !== $name && ! isset( $sorts[ $name ] ) ) {
                self::fail( $request, 'sort', sprintf(
                    'Unsupported sort "%s". Allowed: %s.',
                    $name,
                    implode( ', ', [ 'id', ...array_keys( $sorts ) ] ),
                ) );
            }

            $column = 'id' === $name ? $query->getModel()->getQualifiedKeyName() : $sorts[ $name ];
            $query->orderBy( $column, $descending ? 'desc' : 'asc' );
            $sortedById = $sortedById || 'id' === $name;
        }

        if ( ! $sortedById ) {
            $query->orderBy( $query->getModel()->getQualifiedKeyName(), 'desc' );
        }

        return $query;
    }

    /**
     * Cursor-paginates `$query` honouring `per_page`.
     *
     * @since 1.0.0
     *
     * @param  Builder<Model>  $query    Sorted query.
     * @param  Request         $request  Request.
     *
     * @return CursorPaginator<int, Model>
     */
    public static function paginate( Builder $query, Request $request ): CursorPaginator
    {
        $default = (int) config( 'artisanpack.ecommerce.api.default_per_page', 25 );
        $max     = (int) config( 'artisanpack.ecommerce.api.max_per_page', 100 );
        $perPage = (int) $request->query( 'per_page', (string) $default );

        return $query->cursorPaginate( max( 1, min( $max, $perPage ) ) )->withQueryString();
    }

    /**
     * Coerces a filter value to its declared type.
     *
     * @since 1.0.0
     *
     * @param  string   $value    Raw value.
     * @param  string   $type     `string`, `bool`, or `int`.
     * @param  string   $name     Filter name (for the error message).
     * @param  Request  $request  Request.
     *
     * @throws HttpResponseException 400 on a value that doesn't fit the type.
     *
     * @return bool|int|string
     */
    private static function coerce( string $value, string $type, string $name, Request $request ): bool|int|string
    {
        if ( 'bool' === $type ) {
            $bool = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

            if ( null === $bool ) {
                self::fail( $request, 'filter', sprintf( 'Filter "%s" must be true or false.', $name ) );
            }

            return $bool;
        }

        if ( 'int' === $type ) {
            if ( ! ctype_digit( $value ) ) {
                self::fail( $request, 'filter', sprintf( 'Filter "%s" must be a whole number.', $name ) );
            }

            return (int) $value;
        }

        return $value;
    }

    /**
     * Splits a comma-separated parameter into trimmed, non-empty values.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Raw parameter.
     *
     * @return array<int, string>
     */
    private static function csv( mixed $value ): array
    {
        if ( ! is_string( $value ) ) {
            return [];
        }

        return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), static fn ( string $v ): bool => '' !== $v ) );
    }

    /**
     * Aborts with a 400 problem response.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $field    Offending parameter.
     * @param  string   $message  Explanation.
     *
     * @throws HttpResponseException Always.
     *
     * @return never
     */
    private static function fail( Request $request, string $field, string $message ): never
    {
        throw new HttpResponseException( Problem::make(
            400,
            'invalid-list-query',
            'Invalid list query',
            $message,
            $request,
            [ [ 'field' => $field, 'code' => 'unsupported', 'message' => $message ] ],
        ) );
    }
}
