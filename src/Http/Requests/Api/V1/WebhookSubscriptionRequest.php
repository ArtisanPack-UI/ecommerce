<?php

/**
 * WebhookSubscriptionRequest.
 *
 * Validates `POST|PATCH admin/webhook-subscriptions` (engine spec §9.11).
 * Endpoints must be `https://` unless
 * `artisanpack.ecommerce.webhooks.allow_insecure_urls` is enabled (local
 * development). Event names are wire names (`order.refunded`) or `*`.
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

use ArtisanPackUI\Ecommerce\Webhooks\WebhookUrlGuard;
use Closure;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class WebhookSubscriptionRequest extends ApiFormRequest
{
    /**
     * Pattern for a wire event name, or the `*` wildcard.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const EVENT_PATTERN = '/^(\*|[a-z0-9_]+(\.[a-z0-9_]+)+)$/';

    /**
     * Rules shared with the GraphQL mutations.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public static function baseRules(): array
    {
        $schemes = (bool) config( 'artisanpack.ecommerce.webhooks.allow_insecure_urls', false ) ? 'http,https' : 'https';

        return [
            'name'      => [ 'string', 'max:255' ],
            'url'       => [
                'bail',
                'string',
                'max:1000',
                'url:' . $schemes,
                static function ( string $attribute, mixed $value, Closure $fail ): void {
                    if ( is_string( $value ) && ! app( WebhookUrlGuard::class )->allows( $value ) ) {
                        $fail( __( 'The :attribute must resolve to a public address.' ) );
                    }
                },
            ],
            'events'    => [ 'array', 'min:1' ],
            'events.*'  => [ 'string', 'max:120', 'regex:' . self::EVENT_PATTERN ],
            'secret'    => [ 'nullable', 'string', 'min:16', 'max:255' ],
            'is_active' => [ 'boolean' ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->sometimes( self::baseRules(), [ 'name', 'url', 'events' ] );
    }
}
