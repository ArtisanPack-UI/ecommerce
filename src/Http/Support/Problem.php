<?php

/**
 * Problem.
 *
 * Builds RFC 7807 `application/problem+json` responses for the REST API
 * (engine spec §11.5). `type` URIs are rooted at
 * `artisanpack.ecommerce.idempotency.problem_base_url`, the same base the
 * idempotency and rate-limit middleware use.
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

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class Problem
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const CONTENT_TYPE = 'application/problem+json';

    /**
     * Builds a problem response.
     *
     * @since 1.0.0
     *
     * @param  int                                                         $status   HTTP status.
     * @param  string                                                      $slug     Problem slug appended to the base URL.
     * @param  string                                                      $title    Short, human-readable summary.
     * @param  string|null                                                 $detail   Occurrence-specific explanation.
     * @param  Request|null                                                $request  Request (for `instance`).
     * @param  array<int, array{field: string, code: string, message: string}>  $errors  Field-level errors.
     *
     * @return JsonResponse
     */
    public static function make( int $status, string $slug, string $title, ?string $detail = null, ?Request $request = null, array $errors = [] ): JsonResponse
    {
        $base = rtrim( (string) config(
            'artisanpack.ecommerce.idempotency.problem_base_url',
            'https://docs.artisanpack-ui.dev/ecommerce/problems',
        ), '/' );

        $body = array_filter( [
            'type'     => $base . '/' . $slug,
            'title'    => $title,
            'status'   => $status,
            'detail'   => $detail,
            'instance' => null === $request ? null : '/' . ltrim( $request->path(), '/' ),
            'errors'   => [] === $errors ? null : $errors,
        ], static fn ( mixed $value ): bool => null !== $value );

        return new JsonResponse( $body, $status, [ 'Content-Type' => self::CONTENT_TYPE ] );
    }
}
