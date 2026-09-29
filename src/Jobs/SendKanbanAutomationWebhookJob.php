<?php

/**
 * SendKanbanAutomationWebhookJob.
 *
 * Delivers the payload of a `webhook` kanban automation. The URL is
 * re-vetted against {@see WebhookUrlGuard} at send time and the connection
 * pinned to the vetted address, so a DNS change after the automation was
 * saved can't aim the request at an internal host. Non-2xx responses throw
 * so the queue retries with backoff.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Jobs;

use ArtisanPackUI\Ecommerce\Services\WebhookDispatcher;
use ArtisanPackUI\Ecommerce\Support\RequestContext;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookSigner;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SendKanbanAutomationWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @since 1.0.0
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * @since 1.0.0
     *
     * @param  string                $url      Endpoint.
     * @param  array<string, mixed>  $payload  JSON body.
     * @param  string|null           $secret   Signing secret, if any.
     */
    public function __construct(
        public readonly string $url,
        public readonly array $payload,
        public readonly ?string $secret = null,
    ) {
        $this->onConnection( config( 'artisanpack.ecommerce.webhooks.connection' ) );
        $this->onQueue( config( 'artisanpack.ecommerce.webhooks.queue' ) );
    }

    /**
     * Seconds to wait before each retry.
     *
     * @since 1.0.0
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [ 60, 300 ];
    }

    /**
     * @since 1.0.0
     *
     * @param  WebhookUrlGuard  $urlGuard  SSRF guard.
     *
     * @throws RuntimeException When the URL no longer vets or the endpoint fails.
     *
     * @return void
     */
    public function handle( WebhookUrlGuard $urlGuard ): void
    {
        $address = $urlGuard->vettedAddress( $this->url );

        if ( null === $address ) {
            $this->fail( new RuntimeException( 'Kanban automation webhook URL does not resolve to a public address.' ) );

            return;
        }

        $body    = (string) json_encode( $this->payload, WebhookDispatcher::JSON_FLAGS );
        $headers = [
            'Content-Type'        => 'application/json',
            'User-Agent'          => 'ArtisanPack-Ecommerce-Webhooks/1.0',
            'X-ArtisanPack-Event' => 'kanban.automation',
            'X-Request-Id'        => RequestContext::requestIdOrGenerate(),
        ];

        if ( null !== $this->secret ) {
            $headers[ WebhookSigner::HEADER ] = WebhookSigner::header( $body, $this->secret, Carbon::now()->getTimestamp() );
        }

        $response = Http::withOptions( WebhookUrlGuard::pinOptions( $this->url, $address ) )
            ->withHeaders( $headers )
            ->timeout( max( 1, (int) config( 'artisanpack.ecommerce.webhooks.timeout', 10 ) ) )
            ->withoutRedirecting()
            ->withBody( $body, 'application/json' )
            ->post( $this->url );

        if ( ! $response->successful() ) {
            throw new RuntimeException( sprintf( 'Kanban automation webhook responded with HTTP %d.', $response->status() ) );
        }
    }
}
