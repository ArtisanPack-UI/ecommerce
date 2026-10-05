<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\IdempotencyConflictException;
use ArtisanPackUI\Ecommerce\Models\IdempotencyRecord;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Support\IdempotentAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'runs a call once per scope and key and replays its result', function (): void {
    $action = app( IdempotentAction::class );
    $runs   = 0;
    $order  = Order::factory()->create( [ 'customer_note' => 'first' ] );

    $call = function () use ( &$runs, $order ): array {
        ++$runs;

        return [ 'order' => $order, 'count' => $runs ];
    };

    $first = $action->run( 'checkout.finalize:cart:1', 'click-1', $call );

    // The replay re-fetches models, so it sees current data.
    $order->update( [ 'customer_note' => 'changed' ] );
    $replay = $action->run( 'checkout.finalize:cart:1', 'click-1', $call );

    expect( $runs )->toBe( 1 )
        ->and( $first['count'] )->toBe( 1 )
        ->and( $replay['count'] )->toBe( 1 )
        ->and( $replay['order'] )->toBeInstanceOf( Order::class )
        ->and( $replay['order']->customer_note )->toBe( 'changed' );

    // A new key, or the same key in another scope, runs again.
    $action->run( 'checkout.finalize:cart:1', 'click-2', $call );
    $action->run( 'checkout.finalize:cart:2', 'click-1', $call );

    expect( $runs )->toBe( 3 );
} );

it( 'does not keep a call that threw, so it can be retried', function (): void {
    $action = app( IdempotentAction::class );

    expect( fn () => $action->run( 'refund:order:1', 'k', static fn () => throw new RuntimeException( 'gateway down' ) ) )
        ->toThrow( RuntimeException::class );

    expect( IdempotencyRecord::query()->count() )->toBe( 0 )
        ->and( $action->run( 'refund:order:1', 'k', static fn (): string => 'done' ) )->toBe( 'done' );
} );

it( 'treats reusing a key for different inputs as a conflict', function (): void {
    $action = app( IdempotentAction::class );

    $action->run( 'refund:order:1', 'k', static fn (): int => 1, fingerprint: 'amount=100' );

    $action->run( 'refund:order:1', 'k', static fn (): int => 2, fingerprint: 'amount=999' );
} )->throws( IdempotencyConflictException::class, 'different request' );

it( 'gives up on a call with the same key that is still running', function (): void {
    config()->set( 'artisanpack.ecommerce.idempotency.wait_ms', 30 );
    IdempotencyRecord::query()->create( [
        'actor_scope'     => IdempotentAction::ACTOR_SCOPE,
        'endpoint_key'    => 'checkout.finalize:cart:1',
        'idempotency_key' => 'k',
        'request_hash'    => hash( 'sha256', '' ),
        'locked_at'       => now(),
        'expires_at'      => now()->addDay(),
    ] );

    app( IdempotentAction::class )->run( 'checkout.finalize:cart:1', 'k', static fn (): int => 1 );
} )->throws( IdempotencyConflictException::class, 'still being processed' );
