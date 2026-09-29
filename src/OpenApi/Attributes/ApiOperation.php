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
     * @param  string       $summary      One-line summary.
     * @param  string|null  $resource     Resource class rendered in `data`, or null for no body.
     * @param  bool         $collection   Whether `data` is a cursor-paginated list.
     * @param  int          $status       Success status code.
     * @param  string|null  $description  Longer description.
     */
    public function __construct(
        public readonly string $summary,
        public readonly ?string $resource = null,
        public readonly bool $collection = false,
        public readonly int $status = 200,
        public readonly ?string $description = null,
    ) {
    }
}
