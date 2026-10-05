<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\ActivityLogEntry;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\DigitalDownloadEvent;
use ArtisanPackUI\Ecommerce\Models\InboundWebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses( RefreshDatabase::class );

/**
 * An inbound webhook received `$daysAgo` days ago.
 */
function pruneInbound( int $daysAgo ): InboundWebhookDelivery
{
    return InboundWebhookDelivery::query()->create( [
        'provider'        => 'stripe',
        'verified'        => true,
        'payload_hash'    => str_repeat( 'c', 64 ),
        'payload'         => '{}',
        'response_status' => 200,
        'received_at'     => Carbon::now()->subDays( $daysAgo ),
    ] );
}

it( 'removes ledger rows past their retention and keeps newer ones', function (): void {
    $oldInbound = pruneInbound( 91 );
    $newInbound = pruneInbound( 89 );

    $oldActivity = ActivityLogEntry::factory()->create( [ 'created_at' => Carbon::now()->subDays( 366 ) ] );
    $newActivity = ActivityLogEntry::factory()->create( [ 'created_at' => Carbon::now()->subDays( 364 ) ] );

    $download = DigitalDownload::factory()->create();
    $oldEvent = DigitalDownloadEvent::query()->create( [ 'digital_download_id' => $download->id, 'event_type' => DigitalDownloadEvent::TYPE_DOWNLOAD ] );
    $oldEvent->forceFill( [ 'created_at' => Carbon::now()->subDays( 400 ) ] )->saveQuietly();
    $newEvent = DigitalDownloadEvent::query()->create( [ 'digital_download_id' => $download->id, 'event_type' => DigitalDownloadEvent::TYPE_DOWNLOAD ] );

    $this->artisan( 'ecommerce:prune-ledgers' )->assertSuccessful();

    expect( InboundWebhookDelivery::query()->pluck( 'id' )->all() )->toBe( [ $newInbound->id ] )
        ->and( DigitalDownloadEvent::query()->pluck( 'id' )->all() )->toBe( [ $newEvent->id ] )
        ->and( InboundWebhookDelivery::query()->find( $oldInbound->id ) )->toBeNull()
        ->and( ActivityLogEntry::query()->find( $oldActivity->id ) )->toBeNull()
        ->and( ActivityLogEntry::query()->find( $newActivity->id ) )->not->toBeNull();
} );

it( 'prunes only finished outbound deliveries', function (): void {
    $old         = Carbon::now()->subDays( 100 );
    $delivered   = WebhookDelivery::factory()->create( [ 'created_at' => $old, 'delivered_at' => $old, 'next_retry_at' => null, 'attempts' => 1 ] );
    $exhausted   = WebhookDelivery::factory()->create( [ 'created_at' => $old, 'next_retry_at' => null, 'attempts' => 10 ] );
    $retrying    = WebhookDelivery::factory()->create( [ 'created_at' => $old, 'next_retry_at' => Carbon::now()->addHour(), 'attempts' => 3 ] );
    $parked      = WebhookDelivery::factory()->create( [ 'created_at' => $old, 'next_retry_at' => null, 'attempts' => 2 ] );
    $newFinished = WebhookDelivery::factory()->create( [ 'created_at' => Carbon::now()->subDays( 10 ), 'delivered_at' => Carbon::now(), 'next_retry_at' => null, 'attempts' => 1 ] );

    $this->artisan( 'ecommerce:prune-ledgers' )->assertSuccessful();

    expect( WebhookDelivery::query()->orderBy( 'id' )->pluck( 'id' )->all() )->toBe( [ $retrying->id, $parked->id, $newFinished->id ] )
        ->and( WebhookDelivery::query()->whereKey( [ $delivered->id, $exhausted->id ] )->exists() )->toBeFalse();
} );

it( 'keeps a ledger forever when its retention is 0', function (): void {
    config()->set( 'artisanpack.ecommerce.retention.inbound_webhooks_days', 0 );

    $ancient = pruneInbound( 5_000 );

    $this->artisan( 'ecommerce:prune-ledgers' )->assertSuccessful();

    expect( InboundWebhookDelivery::query()->find( $ancient->id ) )->not->toBeNull();
} );

it( 'deletes a large backlog in batches', function (): void {
    foreach ( range( 1, 2_050 ) as $i ) {
        pruneInbound( 200 );
    }

    pruneInbound( 1 );

    $this->artisan( 'ecommerce:prune-ledgers' )
        ->expectsOutputToContain( 'Pruned 2050 inbound webhook(s).' )
        ->assertSuccessful();

    expect( InboundWebhookDelivery::query()->count() )->toBe( 1 );
} );
