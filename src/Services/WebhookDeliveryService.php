<?php

/**
 * WebhookDeliveryService.
 *
 * Performs one outbound delivery attempt and records the outcome in the
 * retry ledger (engine spec §8.2 steps 4–6):
 *
 * - The body runs through `ap.ecommerce.webhook.delivering` (filter), is
 *   JSON-encoded once, signed with `X-ArtisanPack-Signature` (§8.1), and
 *   POSTed with the event name, delivery id, and request correlation id.
 * - **2xx** → `delivered_at` set, the subscription's failure streak reset,
 *   `ap.ecommerce.webhook.delivered` + {@see WebhookDelivered}.
 * - **Anything else** (non-2xx, timeout, connection error) → `attempts`
 *   incremented, `next_retry_at` pushed out by the backoff schedule (or
 *   cleared after `webhooks.max_attempts` attempts), the subscription's failure
 *   streak incremented, `ap.ecommerce.webhook.failed` + {@see WebhookFailed}.
 * - When the streak reaches `webhooks.disable_after_failures`, the
 *   subscription is switched off and
 *   `ap.ecommerce.webhook.subscriptionDisabled` +
 *   {@see WebhookSubscriptionDisabled} fire so an operator can be told.
 *
 * Redirects are not followed, so a subscriber can't bounce a signed body
 * to a host the operator never configured, and every attempt re-checks the
 * endpoint with {@see WebhookUrlGuard} and pins the connection to the
 * vetted address.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Events\WebhookDelivered;
use ArtisanPackUI\Ecommerce\Events\WebhookFailed;
use ArtisanPackUI\Ecommerce\Events\WebhookSubscriptionDisabled;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Support\RequestContext;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookSigner;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookUrlGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class WebhookDeliveryService
{

    /**
     * Default backoff schedule in seconds (engine spec §8.2 step 5):
     * 1m, 5m, 15m, 30m, 1h, 2h, 4h, 8h, 12h, 24h. The delay after the
     * k-th failed attempt is the k-th entry (the last entry repeats).
     *
     * @since 1.0.0
     *
     * @var array<int, int>
     */
    public const DEFAULT_BACKOFF = [ 60, 300, 900, 1_800, 3_600, 7_200, 14_400, 28_800, 43_200, 86_400 ];

    /**
     * Default maximum attempts per delivery.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_MAX_ATTEMPTS = 10;

    /**
     * Maximum stored response-body length.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const RESPONSE_BODY_LIMIT = 2_000;

    /**
     * @since 1.0.0
     *
     * @param  WebhookUrlGuard  $urlGuard  SSRF guard for endpoint URLs.
     */
    public function __construct( protected WebhookUrlGuard $urlGuard )
    {
    }

    /**
     * Attempts one delivery. Returns whether it succeeded.
     *
     * A per-delivery lock keeps two workers from sending the same row at
     * once; a caller that loses the race gets `false` and does nothing.
     * When `$claim` is given (queued jobs), the attempt only proceeds if the
     * row still carries that claim — a job left over from an earlier claim
     * would otherwise send early and skip the backoff.
     *
     * @since 1.0.0
     *
     * @param  WebhookDelivery  $delivery  Delivery row.
     * @param  string|null      $claim     Expected `next_retry_at` (Y-m-d H:i:s).
     *
     * @return bool
     */
    public function attempt( WebhookDelivery $delivery, ?string $claim = null ): bool
    {
        $lock = Cache::lock( 'ecommerce:webhook-delivery:' . $delivery->id, $this->timeout() + 30 );

        if ( ! $lock->get() ) {
            return false;
        }

        try {
            $delivery->refresh();

            if ( $delivery->isDelivered() ) {
                return true;
            }

            if ( null !== $claim && $delivery->next_retry_at?->toDateTimeString() !== $claim ) {
                return false;
            }

            $subscription = $delivery->subscription;

            if ( null === $subscription || ! $subscription->is_active ) {
                // Nothing to send to — stop the sweep from picking it up.
                $delivery->forceFill( [ 'next_retry_at' => null ] )->save();

                return false;
            }

            return $this->send( $delivery, $subscription );
        } finally {
            $lock->release();
        }
    }

    /**
     * The retry delay after the `$attempts`-th failed attempt, or `null`
     * once `webhooks.max_attempts` attempts have been made.
     *
     * @since 1.0.0
     *
     * @param  int  $attempts  Attempts made so far (1-based).
     *
     * @return int|null
     */
    public function backoffAfter( int $attempts ): ?int
    {
        $max = max( 1, (int) config( 'artisanpack.ecommerce.webhooks.max_attempts', self::DEFAULT_MAX_ATTEMPTS ) );

        if ( $attempts >= $max ) {
            return null;
        }

        $schedule = array_values( array_map( 'intval', (array) config( 'artisanpack.ecommerce.webhooks.backoff_seconds', self::DEFAULT_BACKOFF ) ) );

        if ( [] === $schedule ) {
            $schedule = self::DEFAULT_BACKOFF;
        }

        return $schedule[ min( max( 0, $attempts - 1 ), count( $schedule ) - 1 ) ];
    }

    /**
     * Sends the request and records the outcome.
     *
     * @since 1.0.0
     *
     * @param  WebhookDelivery      $delivery      Delivery row.
     * @param  WebhookSubscription  $subscription  Its subscription.
     *
     * @return bool
     */
    protected function send( WebhookDelivery $delivery, WebhookSubscription $subscription ): bool
    {
        $payload  = (array) applyFilters( 'ap.ecommerce.webhook.delivering', (array) $delivery->payload, $subscription, $delivery->event );
        $body     = (string) json_encode( $payload, WebhookDispatcher::JSON_FLAGS );
        $response = null;

        $delivery->attempts = (int) $delivery->attempts + 1;

        // Re-vet the endpoint at send time and pin the connection to the
        // vetted address, so a DNS change since the subscription was saved
        // can't aim the delivery at an internal host.
        $address = $this->urlGuard->vettedAddress( (string) $subscription->url );

        if ( null === $address ) {
            $this->recordFailure( $delivery, $subscription, null, new RuntimeException( 'Webhook URL does not resolve to a public address.' ) );

            return false;
        }

        try {
            $response = Http::withOptions( $this->pinTo( (string) $subscription->url, $address ) )->withHeaders( [
                'Content-Type'           => 'application/json',
                'User-Agent'             => 'ArtisanPack-Ecommerce-Webhooks/1.0',
                WebhookSigner::HEADER    => WebhookSigner::header( $body, (string) $subscription->secret, Carbon::now()->getTimestamp() ),
                'X-ArtisanPack-Event'    => $delivery->event,
                'X-ArtisanPack-Delivery' => (string) $delivery->id,
                'X-Request-Id'           => RequestContext::requestIdOrGenerate(),
            ] )
                ->timeout( $this->timeout() )
                ->withoutRedirecting()
                ->withBody( $body, 'application/json' )
                ->post( $subscription->url );
        } catch ( Throwable $exception ) {
            $this->recordFailure( $delivery, $subscription, null, $exception );

            return false;
        }

        if ( $response->successful() ) {
            $this->recordSuccess( $delivery, $subscription, $response );

            return true;
        }

        $this->recordFailure(
            $delivery,
            $subscription,
            $response,
            new RuntimeException( sprintf( 'Webhook endpoint responded with HTTP %d.', $response->status() ) ),
        );

        return false;
    }

    /**
     * @since 1.0.0
     *
     * @param  WebhookDelivery      $delivery      Delivery row.
     * @param  WebhookSubscription  $subscription  Subscription.
     * @param  Response             $response      2xx response.
     *
     * @return void
     */
    protected function recordSuccess( WebhookDelivery $delivery, WebhookSubscription $subscription, Response $response ): void
    {
        $delivery->forceFill( [
            'response_status' => $response->status(),
            'response_body'   => $this->truncate( $response->body() ),
            'delivered_at'    => Carbon::now(),
            'next_retry_at'   => null,
        ] )->save();

        $subscription->forceFill( [
            'consecutive_failures' => 0,
            'last_success_at'      => Carbon::now(),
        ] )->save();

        doAction( 'ap.ecommerce.webhook.delivered', $delivery );
        Event::dispatch( new WebhookDelivered( $delivery ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  WebhookDelivery      $delivery      Delivery row.
     * @param  WebhookSubscription  $subscription  Subscription.
     * @param  Response|null        $response      Non-2xx response, or null on a transport error.
     * @param  Throwable            $reason        Why it failed.
     *
     * @return void
     */
    protected function recordFailure( WebhookDelivery $delivery, WebhookSubscription $subscription, ?Response $response, Throwable $reason ): void
    {
        $delay = $this->backoffAfter( (int) $delivery->attempts );

        $delivery->forceFill( [
            'response_status' => $response?->status(),
            'response_body'   => $this->truncate( null !== $response ? $response->body() : $reason->getMessage() ),
            'next_retry_at'   => null === $delay ? null : Carbon::now()->addSeconds( $delay ),
        ] )->save();

        WebhookSubscription::query()->whereKey( $subscription->id )->increment( 'consecutive_failures', 1, [
            'last_failure_at' => Carbon::now(),
        ] );

        $subscription->refresh();

        doAction( 'ap.ecommerce.webhook.failed', $delivery, $reason );
        Event::dispatch( new WebhookFailed( $delivery, $reason ) );

        $ceiling = max( 1, (int) config( 'artisanpack.ecommerce.webhooks.disable_after_failures', 10 ) );

        if ( $subscription->is_active && $subscription->consecutive_failures >= $ceiling ) {
            $subscription->forceFill( [ 'is_active' => false ] )->save();

            doAction( 'ap.ecommerce.webhook.subscriptionDisabled', $subscription );
            Event::dispatch( new WebhookSubscriptionDisabled( $subscription ) );
        }
    }

    /**
     * Guzzle options that pin the connection for `$url` to `$address`.
     *
     * @since 1.0.0
     *
     * @param  string  $url      Webhook URL.
     * @param  string  $address  Vetted IP address.
     *
     * @return array<string, mixed>
     */
    protected function pinTo( string $url, string $address ): array
    {
        $host = (string) parse_url( $url, PHP_URL_HOST );

        if ( ! defined( 'CURLOPT_RESOLVE' ) || false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
            return [];
        }

        $port = parse_url( $url, PHP_URL_PORT ) ?? ( 'http' === parse_url( $url, PHP_URL_SCHEME ) ? 80 : 443 );
        $ip   = false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? '[' . $address . ']' : $address;

        return [ 'curl' => [ CURLOPT_RESOLVE => [ sprintf( '%s:%d:%s', $host, $port, $ip ) ] ] ];
    }

    /**
     * Request timeout in seconds.
     *
     * @since 1.0.0
     *
     * @return int
     */
    protected function timeout(): int
    {
        return max( 1, (int) config( 'artisanpack.ecommerce.webhooks.timeout', 10 ) );
    }

    /**
     * Truncates a response body for storage.
     *
     * @since 1.0.0
     *
     * @param  string  $body  Body.
     *
     * @return string
     */
    protected function truncate( string $body ): string
    {
        return mb_substr( $body, 0, self::RESPONSE_BODY_LIMIT );
    }
}
