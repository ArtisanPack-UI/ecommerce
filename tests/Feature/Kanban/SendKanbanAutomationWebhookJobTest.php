<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Jobs\SendKanbanAutomationWebhookJob;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookSigner;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookUrlGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it( 'posts the payload signed with the automation secret', function (): void {
    Http::fake( [ 'hooks.example.test/*' => Http::response( 'ok' ) ] );

    app()->call( [ new SendKanbanAutomationWebhookJob( 'https://hooks.example.test/kanban', [ 'order' => [ 'id' => 5 ] ], 'shh' ), 'handle' ] );

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
