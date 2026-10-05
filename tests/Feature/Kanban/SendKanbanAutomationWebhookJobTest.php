<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Jobs\SendKanbanAutomationWebhookJob;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookSigner;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookUrlGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses( Illuminate\Foundation\Testing\RefreshDatabase::class );

it( 'posts the payload signed with the automation secret', function (): void {
    Http::fake( [ 'hooks.example.test/*' => Http::response( 'ok' ) ] );
    $automation = ArtisanPackUI\Ecommerce\Models\KanbanAutomation::factory()->create( [ 'trigger_config' => [ 'url' => 'https://hooks.example.test/kanban', 'secret' => 'shh' ] ] );

    app()->call( [ new SendKanbanAutomationWebhookJob( 'https://hooks.example.test/kanban', [ 'order' => [ 'id' => 5 ] ], $automation->id ), 'handle' ] );

    Http::assertSent( fn ( Request $request ): bool => 'https://hooks.example.test/kanban' === $request->url()
        && 'kanban.automation' === $request->header( 'X-ArtisanPack-Event' )[0]
        && WebhookSigner::verify( $request->header( WebhookSigner::HEADER )[0], $request->body(), 'shh' )
        && 5 === $request->data()['order']['id'] );
} );

it( 'sends unsigned when no secret is configured', function (): void {
    Http::fake( [ '*' => Http::response( 'ok' ) ] );

    app()->call( [ new SendKanbanAutomationWebhookJob( 'https://hooks.example.test/kanban', [] ), 'handle' ] );

    Http::assertSent( fn ( Request $request ): bool => ! $request->hasHeader( WebhookSigner::HEADER ) );
} );

it( 'throws on a non-2xx response so the queue retries', function (): void {
    Http::fake( [ '*' => Http::response( 'nope', 503 ) ] );

    $job = new SendKanbanAutomationWebhookJob( 'https://hooks.example.test/kanban', [] );

    expect( fn () => app()->call( [ $job, 'handle' ] ) )->toThrow( RuntimeException::class, 'HTTP 503' )
        ->and( $job->tries )->toBe( 3 );
} );

it( 're-vets the URL at send time and never calls a private address', function (): void {
    Http::fake();
    WebhookUrlGuard::resolveUsing( static fn (): array => [ '10.0.0.5' ] );

    try {
        app()->call( [ new SendKanbanAutomationWebhookJob( 'https://hooks.example.test/kanban', [] ), 'handle' ] );
    } catch ( RuntimeException $e ) {
        // Outside a worker, fail() rethrows; on the queue it marks the job failed.
        expect( $e->getMessage() )->toContain( 'public address' );
    }

    Http::assertNothingSent();

    WebhookUrlGuard::resolveUsing( null );
} );

it( 'keeps the secret out of the database row and the queued job', function (): void {
    Illuminate\Support\Facades\Queue::fake();
    $automation = ArtisanPackUI\Ecommerce\Models\KanbanAutomation::factory()->create( [ 'trigger_config' => [ 'url' => 'https://hooks.example.test/kanban', 'secret' => 'super-secret-value' ] ] );

    dispatch( new SendKanbanAutomationWebhookJob( 'https://hooks.example.test/kanban', [ 'order' => [ 'email' => 'jane@example.com' ] ], $automation->id ) );

    $raw = (string) Illuminate\Support\Facades\DB::table( 'ecommerce_kanban_automations' )->where( 'id', $automation->id )->value( 'trigger_config' );

    expect( $raw )->not->toContain( 'super-secret-value' )
        ->and( $automation->fresh()->trigger_config['secret'] )->toBe( 'super-secret-value' );

    Illuminate\Support\Facades\Queue::assertPushed( SendKanbanAutomationWebhookJob::class, function ( SendKanbanAutomationWebhookJob $job ): bool {
        return $job instanceof Illuminate\Contracts\Queue\ShouldBeEncrypted
            && ! str_contains( serialize( $job ), 'super-secret-value' );
    } );
} );

it( 'skips a delivery whose automation was deleted after it was queued', function (): void {
    Http::fake();
    $automation = ArtisanPackUI\Ecommerce\Models\KanbanAutomation::factory()->create( [ 'trigger_config' => [ 'url' => 'https://hooks.example.test/kanban', 'secret' => 'shh' ] ] );
    $job        = new SendKanbanAutomationWebhookJob( 'https://hooks.example.test/kanban', [], $automation->id );
    $automation->delete();

    app()->call( [ $job, 'handle' ] );

    Http::assertNothingSent();
} );

it( 'logs a delivery that gave up', function (): void {
    $logger = Mockery::spy();
    Illuminate\Support\Facades\Log::shouldReceive( 'channel' )->with( 'ecommerce' )->andReturn( $logger );

    ( new SendKanbanAutomationWebhookJob( 'https://hooks.example.test/kanban', [], 7 ) )->failed( new RuntimeException( 'HTTP 503' ) );

    $logger->shouldHaveReceived( 'warning' )->once()->withArgs( fn ( string $message, array $context ): bool => 7 === $context['automation_id'] && 'HTTP 503' === $context['error'] );
} );
