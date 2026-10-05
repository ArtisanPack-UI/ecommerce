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
 * The job is encrypted on the queue (its payload carries customer data) and
 * never holds the signing secret: it reads the secret from the automation's
 * encrypted config when it runs, and drops the delivery if the automation
 * has been deleted since.
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

use ArtisanPackUI\Ecommerce\Models\KanbanAutomation;
use ArtisanPackUI\Ecommerce\Services\WebhookDispatcher;
use ArtisanPackUI\Ecommerce\Support\RequestContext;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookSigner;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class SendKanbanAutomationWebhookJob implements ShouldBeEncrypted, ShouldQueue
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
     * The signing secret is never part of the job: it is read from the
     * automation's (encrypted) config when the job runs.
     *
     * @since 1.0.0
     *
     * @param  string                $url           Endpoint.
     * @param  array<string, mixed>  $payload       JSON body.
     * @param  int|null              $automationId  Automation whose `secret` signs the request, if any.
     */
    public function __construct(
        public readonly string $url,
        public readonly array $payload,
        public readonly ?int $automationId = null,
    ) {
        $this->onConnection( config( 'artisanpack.ecommerce.webhooks.connection' ) );
        $this->onQueue( config( 'artisanpack.ecommerce.webhooks.queue' ) );
        // Only once the card move that triggered it has committed.
        $this->afterCommit();
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

        $secret = $this->secret();

        if ( false === $secret ) {
            // The automation was deleted after the job was queued.
            return;
        }

        if ( null !== $secret ) {
            $headers[ WebhookSigner::HEADER ] = WebhookSigner::header( $body, $secret, Carbon::now()->getTimestamp() );
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

    /**
     * Logs a delivery that gave up after its last retry.
     *
     * @since 1.0.0
     *
     * @param  Throwable  $exception  Why it failed.
     *
     * @return void
     */
    public function failed( Throwable $exception ): void
    {
        Log::channel( 'ecommerce' )->warning( 'Kanban automation webhook failed.', [
            'automation_id' => $this->automationId,
            'url'           => $this->url,
            'error'         => $exception->getMessage(),
        ] );
    }

    /**
     * The automation's signing secret: null when it has none (or the job
     * isn't tied to an automation), false when the automation is gone.
     *
     * @since 1.0.0
     *
     * @return false|string|null
     */
    protected function secret(): string|false|null
    {
        if ( null === $this->automationId ) {
            return null;
        }

        $automation = KanbanAutomation::query()->find( $this->automationId );

        if ( null === $automation ) {
            return false;
        }

        $secret = $automation->trigger_config['secret'] ?? null;

        return is_string( $secret ) && '' !== $secret ? $secret : null;
    }
}
