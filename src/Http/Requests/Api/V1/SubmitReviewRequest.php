<?php

/**
 * SubmitReviewRequest.
 *
 * Validates `POST products/{product}/reviews` (engine spec §9.1). Author
 * details are required when the reviewer isn't a signed-in customer.
 * The configured honeypot field is accepted (and must stay empty for the
 * review to reach moderation).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Requests\Api\V1;

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Reviews\ReviewMediaStore;
use ArtisanPackUI\Ecommerce\Services\ReviewService;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SubmitReviewRequest extends ApiFormRequest
{
    /**
     * Rules shared with the GraphQL mutation.
     *
     * @since 1.0.0
     *
     * @param  bool  $hasCustomer  Whether the reviewer is a signed-in customer.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function baseRules( bool $hasCustomer ): array
    {
        $author = $hasCustomer ? [ 'nullable' ] : [ 'required' ];

        return [
            'rating'                       => [ 'required', 'integer', 'min:1', 'max:5' ],
            'title'                        => [ 'nullable', 'string', 'max:255' ],
            'body'                         => [ 'nullable', 'string', 'max:10000' ],
            'author_name'                  => [ ...$author, 'string', 'max:255' ],
            'author_email'                 => [ ...$author, 'string', 'email', 'max:255' ],
            'order_id'                     => [ 'nullable', 'integer', 'min:1' ],
            // Photos (#181) need media-library to store them.
            'media'                        => app( ReviewMediaStore::class )->available()
                ? [ 'sometimes', 'array', 'max:' . max( 0, (int) config( 'artisanpack.ecommerce.reviews.max_media', 5 ) ) ]
                : [ 'prohibited' ],
            'media.*'                      => [ 'file', 'image', 'max:5120' ],
            ReviewService::honeypotField() => [ 'nullable' ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return self::baseRules( null !== Customer::forUser( $this->user() ) );
    }
}
