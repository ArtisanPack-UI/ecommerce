<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses( RefreshDatabase::class );

it( 'deletes only records past expires_at', function (): void {
    $expired = IdempotencyRecord::query()->create( [
        'actor_scope'     => 'ip:1.1.1.1',
        'endpoint_key'    => 'x',
        'idempotency_key' => 'expired',
        'request_hash'    => str_repeat( 'a', 64 ),
        'expires_at'      => Carbon::now()->subMinute(),
    ] );

    $fresh = IdempotencyRecord::query()->create( [
        'actor_scope'     => 'ip:1.1.1.1',
        'endpoint_key'    => 'x',
        'idempotency_key' => 'fresh',
        'request_hash'    => str_repeat( 'b', 64 ),
        'expires_at'      => Carbon::now()->addHour(),
    ] );

    $this->artisan( 'ecommerce:prune-idempotency-records' )
        ->expectsOutputToContain( 'Pruned 1 expired idempotency record(s).' )
        ->assertExitCode( 0 );

    expect( IdempotencyRecord::query()->find( $expired->id ) )->toBeNull();
    expect( IdempotencyRecord::query()->find( $fresh->id ) )->not->toBeNull();
} );
