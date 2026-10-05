<?php

/**
 * WebhookSubscriptionController.
 *
 * `admin/webhook-subscriptions` (engine spec §9.11), plus the delivery
 * ledger (list and single read, engine issue #150). Writes delegate
 * to {@see WebhookSubscriptionService}, the same service the GraphQL
 * mutations use.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1;

use ArtisanPackUI\Ecommerce\Http\Middleware\IdempotencyMiddleware;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\WebhookSubscriptionRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\WebhookDeliveryResource;
use ArtisanPackUI\Ecommerce\Http\Resources\WebhookSubscriptionResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\WebhookSubscriptionService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class WebhookSubscriptionController extends ApiController
{
    /**
     * Columns the deliveries listing reads (everything but `payload` and
     * `response_body`).
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    private const DELIVERY_LIST_COLUMNS = [
        'id',
        'subscription_id',
        'event',
        'payload_hash',
        'response_status',
        'attempts',
        'delivered_at',
        'next_retry_at',
        'created_at',
    ];

    /**
     * @since 1.0.0
     *
     * @param  WebhookSubscriptionService  $subscriptions  Subscription service.
     */
    public function __construct( private readonly WebhookSubscriptionService $subscriptions )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List webhook subscriptions', resource: WebhookSubscriptionResource::class, collection: true )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            WebhookSubscription::query(),
            $request,
            WebhookSubscriptionResource::class,
            [ 'is_active' => [ 'is_active', 'bool' ] ],
            [ 'name' => 'name', 'created_at' => 'created_at' ],
            self::includes(),
        );
    }

    /**
     * Creates a subscription. The response is the only one that reveals the
     * signing secret.
     *
     * @since 1.0.0
     *
     * @param  WebhookSubscriptionRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create a webhook subscription', resource: WebhookSubscriptionResource::class, status: 201 )]
    public function store( WebhookSubscriptionRequest $request ): JsonResponse
    {
        // The secret is shown once: keep it out of the stored idempotent
        // replay. Set on the container's request — the one the middleware
        // holds — not on this FormRequest copy.
        request()->attributes->set( IdempotencyMiddleware::REDACT_ATTRIBUTE, [ 'secret' ] );

        $subscription = $this->subscriptions->create( $request->validated() );

        if ( null === $subscription ) {
            return Problem::make( 422, 'webhook-subscription-rejected', __( 'Subscription rejected' ), __( 'The subscription was rejected by a filter.' ), $request );
        }

        return ( new WebhookSubscriptionResource( $subscription ) )->withSecret()->response( $request )->setStatusCode( 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  WebhookSubscriptionRequest  $request       Validated request.
     * @param  WebhookSubscription         $subscription  Subscription.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a webhook subscription', resource: WebhookSubscriptionResource::class )]
    public function update( WebhookSubscriptionRequest $request, WebhookSubscription $subscription ): JsonResponse
    {
        return $this->resourceResponse(
            $this->subscriptions->update( $subscription, $request->validated() ),
            $request,
            WebhookSubscriptionResource::class,
            self::includes(),
        );
    }

    /**
     * Deletes the subscription (its deliveries cascade).
     *
     * @since 1.0.0
     *
     * @param  Request              $request       Request.
     * @param  WebhookSubscription  $subscription  Subscription.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a webhook subscription', resource: WebhookSubscriptionResource::class )]
    public function destroy( Request $request, WebhookSubscription $subscription ): JsonResponse
    {
        $subscription->delete();

        return $this->resourceResponse( $subscription, $request, WebhookSubscriptionResource::class );
    }

    /**
     * A subscription's deliveries, newest first, without payloads or
     * response bodies (engine issue #150). Filters: `event`, and `status`
     * — `delivered`, `retrying` (failed, another attempt scheduled),
     * `failed` (attempts exhausted), or `pending` (not yet attempted).
     *
     * @since 1.0.0
     *
     * @param  Request              $request       Request.
     * @param  WebhookSubscription  $subscription  Subscription.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List a webhook subscription\'s deliveries', resource: WebhookDeliveryResource::class, collection: true )]
    public function deliveries( Request $request, WebhookSubscription $subscription ): JsonResponse
    {
        return $this->listResponse(
            // Payloads and response bodies can be large and the listing
            // doesn't render them, so leave them in the database.
            $subscription->deliveries()->getQuery()->select( self::DELIVERY_LIST_COLUMNS ),
            $request,
            WebhookDeliveryResource::class,
            [
                'event'  => 'event',
                'status' => static fn ( Builder $query, string $value ): Builder => match ( $value ) {
                    'delivered' => $query->whereNotNull( 'delivered_at' ),
                    'retrying'  => $query->whereNull( 'delivered_at' )->whereNotNull( 'next_retry_at' )->where( 'attempts', '>', 0 ),
                    'failed'    => $query->whereNull( 'delivered_at' )->whereNull( 'next_retry_at' )->where( 'attempts', '>', 0 ),
                    'pending'   => $query->whereNull( 'delivered_at' )->where( 'attempts', 0 ),
                    default     => $query->whereRaw( '1 = 0' ),
                },
            ],
            [ 'id' => 'id', 'created_at' => 'created_at' ],
        );
    }

    /**
     * One delivery, with its payload and the endpoint's response body.
     *
     * @since 1.0.0
     *
     * @param  Request              $request       Request.
     * @param  WebhookSubscription  $subscription  Subscription (scopes the delivery).
     * @param  WebhookDelivery      $delivery      Delivery.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Show a webhook delivery', resource: WebhookDeliveryResource::class )]
    public function delivery( Request $request, WebhookSubscription $subscription, WebhookDelivery $delivery ): JsonResponse
    {
        return ( new WebhookDeliveryResource( $delivery ) )->withBody()->response( $request );
    }

    /**
     * Re-sends a past delivery as a new ledger row.
     *
     * @since 1.0.0
     *
     * @param  Request              $request       Request.
     * @param  WebhookSubscription  $subscription  Subscription (scopes the delivery).
     * @param  WebhookDelivery      $delivery      Delivery to replay.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Replay a webhook delivery', resource: WebhookDeliveryResource::class, status: 202 )]
    public function replay( Request $request, WebhookSubscription $subscription, WebhookDelivery $delivery ): JsonResponse
    {
        $replayed = $this->subscriptions->replay( $delivery );

        if ( null === $replayed ) {
            return Problem::make( 422, 'webhook-subscription-inactive', __( 'Subscription inactive' ), __( 'Re-enable the subscription before replaying its deliveries.' ), $request );
        }

        return $this->resourceResponse( $replayed, $request, WebhookDeliveryResource::class, [], 202 );
    }

    /**
     * Allowed includes. Deliveries are capped to the newest 20 per
     * subscription so a noisy endpoint can't blow up the listing.
     *
     * @since 1.0.0
     *
     * @return array<string, array{0: string, 1: Closure}>
     */
    protected static function includes(): array
    {
        return [
            'deliveries' => [ 'deliveries', static fn ( $query ) => $query->latest( 'id' )->limit( 20 ) ],
        ];
    }
}
