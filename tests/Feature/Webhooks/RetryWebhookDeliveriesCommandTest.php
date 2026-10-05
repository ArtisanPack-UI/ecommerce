<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Jobs\DeliverWebhookJob;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses( RefreshDatabase::class );

it( 'queues due deliveries and claims them so the next sweep skips them', function (): void {
    Carbon::setTestNow( '2026-09-28 12:00:00' );
    Queue::fake();

    $due       = WebhookDelivery::factory()->create( [ 'next_retry_at' => Carbon::now()->subMinute() ] );
    $notYet    = WebhookDelivery::factory()->create( [ 'next_retry_at' => Carbon::now()->addMinute() ] );
    $exhausted = WebhookDelivery::factory()->create( [ 'next_retry_at' => null ] );
    $delivered = WebhookDelivery::factory()->create( [ 'next_retry_at' => Carbon::now()->subMinute(), 'delivered_at' => Carbon::now() ] );

    $this->artisan( 'ecommerce:retry-webhook-deliveries' )->assertSuccessful();

    Queue::assertPushed( DeliverWebhookJob::class, 1 );
    Queue::assertPushed( DeliverWebhookJob::class, fn ( DeliverWebhookJob $job ): bool => $job->deliveryId === $due->id );

    expect( $due->refresh()->next_retry_at->greaterThan( Carbon::now() ) )->toBeTrue()
        ->and( $notYet->refresh()->next_retry_at->equalTo( Carbon::now()->addMinute() ) )->toBeTrue()
        ->and( $exhausted->refresh()->next_retry_at )->toBeNull();

    $this->artisan( 'ecommerce:retry-webhook-deliveries' )->assertSuccessful();

    Queue::assertPushed( DeliverWebhookJob::class, 1 );

    Carbon::setTestNow();
} );

it( 'is scheduled every minute', function (): void {
    $events = collect( app( Illuminate\Console\Scheduling\Schedule::class )->events() )
        ->filter( fn ( $event ): bool => str_contains( (string) $event->command, 'ecommerce:retry-webhook-deliveries' ) );

    expect( $events )->toHaveCount( 1 )
        ->and( $events->first()->expression )->toBe( '* * * * *' );
} );
