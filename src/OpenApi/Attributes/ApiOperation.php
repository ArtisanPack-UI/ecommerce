<?php

/**
 * ApiOperation attribute.
 *
 * Describes a REST controller action for the OpenAPI generator
 * (`php artisan ecommerce:generate-openapi`). Everything the generator can
 * read from the route itself — method, path, auth, required ability, rate
 * limit policy, Idempotency-Key requirement, request-body rules — comes
 * from the route; this attribute only supplies what the route can't say:
 * a summary and which resource the action responds with.
 *
 * Has no runtime effect.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\OpenApi\Attributes;

use Attribute;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
#[Attribute( Attribute::TARGET_METHOD )]
final class ApiOperation
{
    /**
     * @since 1.0.0
     *
     * @param  string                                                                  $summary      One-line summary.
     * @param  string|null                                                             $resource     Resource class rendered in `data`, or null for no body.
     * @param  bool                                                                    $collection   Whether `data` is a list (cursor-paginated when it declares filters or sorts).
     * @param  int                                                                     $status       Success status code.
     * @param  string|null                                                             $description  Longer description.
     * @param  array<string, string>                                                   $filters      `filter[...]` keys a list accepts → JSON type, or `int-list` for comma-separated ids.
     * @param  array<int|string, mixed>                                                $sorts        `sort` values a list accepts (a list, or a map keyed by them).
     * @param  array<int|string, mixed>                                                $includes     `include` values the endpoint accepts (a list, or a map keyed by them).
     * @param  array<string, array{schema: array<string, mixed>, required?: bool, description?: string, style?: string}>  $query  Other query parameters.
     */
    public function __construct(
        public readonly string $summary,
        public readonly ?string $resource = null,
        public readonly bool $collection = false,
        public readonly int $status = 200,
        public readonly ?string $description = null,
        public readonly array $filters = [],
        public readonly array $sorts = [],
        public readonly array $includes = [],
        public readonly array $query = [],
    ) {
    }
}
