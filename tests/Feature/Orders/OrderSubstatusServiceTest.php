<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\OrderSubstatusWriteException;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Registries\SubStatusRegistry;
use ArtisanPackUI\Ecommerce\Services\OrderSubstatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses( RefreshDatabase::class );

function substatuses(): OrderSubstatusService
{
    return app( OrderSubstatusService::class );
}

function substatusError( Closure $write ): OrderSubstatusWriteException
{
    try {
        $write();
    } catch ( OrderSubstatusWriteException $exception ) {
        return $exception;
    }

    throw new RuntimeException( 'Expected an OrderSubstatusWriteException.' );
}

it( 'creates a sub-status at the end of its system status', function (): void {
    $substatus = substatuses()->create( [
        'system_status' => 'processing',
        'key'           => 'printing',
        'label'         => 'Printing',
        'color'         => '#3b82f6',
        'icon'          => 'printer',
    ] );

    $existing = (int) OrderSubstatus::query()->where( 'system_status', 'processing' )->where( 'key', 'in-progress' )->value( 'position' );

    expect( $substatus->exists )->toBeTrue()
        ->and( $substatus->color )->toBe( '#3B82F6' )
        ->and( $substatus->icon )->toBe( 'printer' )
        ->and( $substatus->is_terminal )->toBeFalse()
        ->and( $substatus->position )->toBe( $existing + 1 );
} );

it( 'derives the key from the label when it is blank', function (): void {
    expect( substatuses()->create( [ 'system_status' => 'processing', 'label' => 'Quality Check' ] )->key )->toBe( 'quality-check' );
} );

it( 'refuses invalid input', function ( array $data, string $field, string $code ): void {
    $error = substatusError( fn () => substatuses()->create( $data ) );

    expect( $error->errors[0]['field'] )->toBe( $field )
        ->and( $error->errors[0]['code'] )->toBe( $code );
} )->with( [
    'unknown system status' => [ [ 'system_status' => 'shipped', 'label' => 'Shipped' ], 'system_status', 'unknown-system-status' ],
    'missing label'         => [ [ 'system_status' => 'processing', 'key' => 'x' ], 'label', 'required' ],
    'bad key'               => [ [ 'system_status' => 'processing', 'key' => 'Has Spaces', 'label' => 'X' ], 'key', 'invalid-key' ],
    'bad colour'            => [ [ 'system_status' => 'processing', 'label' => 'X', 'color' => 'blue' ], 'color', 'invalid-color' ],
    'short colour'          => [ [ 'system_status' => 'processing', 'label' => 'X', 'color' => '#FFF' ], 'color', 'invalid-color' ],
    'long label'            => [ [ 'system_status' => 'processing', 'label' => str_repeat( 'a', 121 ) ], 'label', 'too-long' ],
    'long icon'             => [ [ 'system_status' => 'processing', 'label' => 'X', 'icon' => str_repeat( 'a', 81 ) ], 'icon', 'too-long' ],
] );

it( 'keeps keys unique within a system status only', function (): void {
    substatuses()->create( [ 'system_status' => 'processing', 'key' => 'printing', 'label' => 'Printing' ] );

    expect( substatusError( fn () => substatuses()->create( [ 'system_status' => 'processing', 'key' => 'printing', 'label' => 'Again' ] ) )->errors[0]['code'] )
        ->toBe( 'key-taken' );

    expect( substatuses()->create( [ 'system_status' => 'pending', 'key' => 'printing', 'label' => 'Printing' ] )->exists )->toBeTrue();
} );

it( 'updates only the given fields and clears the colour', function (): void {
    $substatus = substatuses()->create( [ 'system_status' => 'processing', 'key' => 'printing', 'label' => 'Printing', 'color' => '#111111' ] );

    substatuses()->update( $substatus, [ 'label' => 'On the press', 'color' => null, 'is_terminal' => true ] );

    $fresh = $substatus->fresh();

    expect( $fresh->label )->toBe( 'On the press' )
        ->and( $fresh->key )->toBe( 'printing' )
        ->and( $fresh->color )->toBeNull()
        ->and( $fresh->is_terminal )->toBeTrue()
        ->and( $substatus->label )->toBe( 'On the press' );
} );

it( 'refuses to move a sub-status to another system status', function (): void {
    $substatus = substatuses()->create( [ 'system_status' => 'processing', 'key' => 'printing', 'label' => 'Printing' ] );

    expect( substatusError( fn () => substatuses()->update( $substatus, [ 'system_status' => 'pending' ] ) )->errors[0]['code'] )
        ->toBe( 'system-status-fixed' );

    expect( substatuses()->update( $substatus, [ 'system_status' => 'processing', 'label' => 'Same' ] )->label )->toBe( 'Same' );
} );

it( 'refuses an update to a key another sub-status uses', function (): void {
    $substatus = substatuses()->create( [ 'system_status' => 'processing', 'key' => 'printing', 'label' => 'Printing' ] );

    expect( substatusError( fn () => substatuses()->update( $substatus, [ 'key' => 'in-progress' ] ) )->errors[0]['code'] )->toBe( 'key-taken' );
    expect( substatuses()->update( $substatus, [ 'key' => 'printing' ] )->key )->toBe( 'printing' );
} );

it( 'reorders within a system status and appends unlisted ids', function (): void {
    $a = substatuses()->create( [ 'system_status' => 'processing', 'key' => 'a', 'label' => 'A' ] );
    $b = substatuses()->create( [ 'system_status' => 'processing', 'key' => 'b', 'label' => 'B' ] );

    $default = OrderSubstatus::query()->where( 'system_status', 'processing' )->where( 'key', 'in-progress' )->firstOrFail();

    $ordered = substatuses()->reorder( 'processing', [ $b->id, $a->id ] );

    expect( $ordered->pluck( 'key' )->all() )->toBe( [ 'b', 'a', 'in-progress' ] )
        ->and( $default->fresh()->position )->toBe( 2 );
} );

it( 'refuses a reorder with ids from another system status', function (): void {
    $pending = OrderSubstatus::query()->where( 'system_status', 'pending' )->firstOrFail();

    expect( substatusError( fn () => substatuses()->reorder( 'processing', [ $pending->id ] ) )->errors[0]['code'] )->toBe( 'unknown-id' );
    expect( substatusError( fn () => substatuses()->reorder( 'nope', [ $pending->id ] ) )->errors[0]['code'] )->toBe( 'unknown-system-status' );
} );

it( 'deletes an unused sub-status', function (): void {
    $substatus = substatuses()->create( [ 'system_status' => 'processing', 'key' => 'printing', 'label' => 'Printing' ] );

    substatuses()->delete( $substatus );

    expect( OrderSubstatus::query()->find( $substatus->id ) )->toBeNull();
} );

it( 'refuses to delete a sub-status in use, with counts', function (): void {
    $substatus = substatuses()->create( [ 'system_status' => 'processing', 'key' => 'printing', 'label' => 'Printing' ] );

    Order::factory()->count( 2 )->withSystemStatus( 'processing' )->create( [ 'substatus_id' => $substatus->id ] );
    OrderBoardAssignment::factory()->create( [ 'substatus_id' => $substatus->id ] );
    OrderBoardAssignment::factory()->removed()->create( [ 'substatus_id' => $substatus->id ] );
    KanbanColumn::factory()->create( [ 'substatus_id' => $substatus->id ] );

    $error = substatusError( fn () => substatuses()->delete( $substatus ) );

    expect( $error->errors[0]['code'] )->toBe( 'substatus-in-use' )
        ->and( $error->counts )->toBe( [ 'orders' => 2, 'assignments' => 1, 'columns' => 1 ] )
        ->and( $error->context()['counts'] )->toBe( [ 'orders' => 2, 'assignments' => 1, 'columns' => 1 ] )
        ->and( $error->getMessage() )->toContain( '2 orders' )->toContain( '1 board card' )->toContain( '1 kanban column' )
        ->and( OrderSubstatus::query()->find( $substatus->id ) )->not->toBeNull();
} );

it( 'refuses to delete the last sub-status of a system status', function (): void {
    $only = OrderSubstatus::query()->where( 'system_status', 'refunded' )->sole();

    expect( substatusError( fn () => substatuses()->delete( $only ) )->errors[0]['code'] )->toBe( 'last-substatus' );
} );

it( 'fires the lifecycle actions', function (): void {
    $fired = [];

    foreach ( [ 'created', 'updated', 'reordered', 'deleted' ] as $event ) {
        addAction( 'ap.ecommerce.orderSubstatus.' . $event, function () use ( &$fired, $event ): void {
            $fired[] = $event;
        } );
    }

    $substatus = substatuses()->create( [ 'system_status' => 'processing', 'key' => 'printing', 'label' => 'Printing' ] );
    substatuses()->update( $substatus, [ 'label' => 'Press' ] );
    substatuses()->reorder( 'processing', [ $substatus->id ] );
    substatuses()->delete( $substatus );

    expect( $fired )->toBe( [ 'created', 'updated', 'reordered', 'deleted' ] );
} );

it( 'serves the registry from cache and flushes it on every write', function (): void {
    $registry = app( SubStatusRegistry::class );

    expect( $registry->all()->pluck( 'system_status' )->unique()->values()->all() )
        ->toBe( [ 'pending', 'processing', 'complete', 'cancelled', 'refunded', 'failed' ] );

    $substatus = substatuses()->create( [ 'system_status' => 'processing', 'key' => 'printing', 'label' => 'Printing' ] );

    expect( $registry->forSystemStatus( 'processing' )->pluck( 'key' )->all() )->toBe( [ 'in-progress', 'printing' ] )
        ->and( $registry->get( $substatus->id )?->key )->toBe( 'printing' )
        ->and( $registry->get( 'printing', 'processing' )?->id )->toBe( $substatus->id )
        ->and( $registry->get( 'printing', 'pending' ) )->toBeNull();

    substatuses()->reorder( 'processing', [ $substatus->id ] );
    expect( $registry->forSystemStatus( 'processing' )->pluck( 'key' )->all() )->toBe( [ 'printing', 'in-progress' ] );

    substatuses()->update( $substatus, [ 'label' => 'Press' ] );
    expect( $registry->get( $substatus->id )?->label )->toBe( 'Press' );

    substatuses()->delete( $substatus );
    expect( $registry->get( $substatus->id ) )->toBeNull();
} );

it( 'flushes the registry on direct model writes', function (): void {
    $registry = app( SubStatusRegistry::class );
    $registry->all();

    $substatus = OrderSubstatus::factory()->create( [ 'system_status' => 'pending', 'key' => 'manual' ] );

    expect( $registry->get( 'manual', 'pending' )?->id )->toBe( $substatus->id );

    $substatus->delete();

    expect( $registry->get( 'manual', 'pending' ) )->toBeNull();
} );

it( 'derives clean, unique keys from labels', function ( string $label, string $key ): void {
    expect( substatuses()->create( [ 'system_status' => 'processing', 'label' => $label ] )->key )->toBe( $key );
} )->with( [
    'html is stripped first' => [ '<b>Rush</b>', 'rush' ],
    'non-Latin label'        => [ '保留中', 'substatus' ],
    'no dangling hyphen'     => [ str_repeat( 'a', 75 ) . ' bcd', str_repeat( 'a', 75 ) ],
] );

it( 'suffixes a derived key that a sibling already uses', function (): void {
    substatuses()->create( [ 'system_status' => 'processing', 'label' => 'Quality check' ] );

    expect( substatuses()->create( [ 'system_status' => 'processing', 'label' => 'Quality-check' ] )->key )->toBe( 'quality-check-2' )
        ->and( substatusError( fn () => substatuses()->create( [ 'system_status' => 'processing', 'key' => 'quality-check', 'label' => 'Again' ] ) )->errors[0]['code'] )->toBe( 'key-taken' );
} );

it( 'turns a unique-index race on the key into key-taken', function (): void {
    $racing = new class( app( SubStatusRegistry::class ) ) extends OrderSubstatusService {
        protected function assertKeyAvailable( string $systemStatus, string $key, ?int $ignoreId ): void
        {
        }
    };

    $error = substatusError( fn () => $racing->create( [ 'system_status' => 'processing', 'key' => 'in-progress', 'label' => 'Duplicate' ] ) );

    expect( $error->errors[0] )->toMatchArray( [ 'field' => 'key', 'code' => 'key-taken' ] );
} );

it( 'carries the in-use counts on the error entry', function (): void {
    $substatus = substatuses()->create( [ 'system_status' => 'processing', 'label' => 'Printing' ] );
    Order::factory()->withSystemStatus( 'processing' )->create( [ 'substatus_id' => $substatus->id ] );

    expect( substatusError( fn () => substatuses()->delete( $substatus ) )->errors[0]['counts'] )
        ->toBe( [ 'orders' => 1, 'assignments' => 0, 'columns' => 0 ] );
} );

it( 'deletes the second-to-last sub-status but not the last', function (): void {
    $extra = substatuses()->create( [ 'system_status' => 'refunded', 'label' => 'Partly refunded' ] );

    substatuses()->delete( $extra );

    expect( substatusError( fn () => substatuses()->delete( OrderSubstatus::query()->where( 'system_status', 'refunded' )->sole() ) )->errors[0]['code'] )->toBe( 'last-substatus' );
} );

it( 'treats a digit-only string as an id in the registry', function (): void {
    $id = (int) OrderSubstatus::query()->where( 'key', 'in-progress' )->value( 'id' );

    expect( app( SubStatusRegistry::class )->get( (string) $id )?->key )->toBe( 'in-progress' );
} );

it( 'forgets rows cached inside a transaction that rolls back', function (): void {
    $registry = app( SubStatusRegistry::class );

    try {
        DB::transaction( function () use ( $registry ): void {
            substatuses()->create( [ 'system_status' => 'pending', 'label' => 'Ghost' ] );

            expect( $registry->get( 'ghost', 'pending' ) )->not->toBeNull();

            throw new RuntimeException( 'roll back' );
        } );
    } catch ( RuntimeException ) {
        // Expected.
    }

    expect( $registry->get( 'ghost', 'pending' ) )->toBeNull()
        ->and( OrderSubstatus::query()->where( 'key', 'ghost' )->exists() )->toBeFalse();
} );

it( 'flushes the registry after the demo seeder writes or wipes sub-statuses', function (): void {
    $registry = app( SubStatusRegistry::class );
    $registry->all();

    app( ArtisanPackUI\Ecommerce\Demo\DemoSeeder::class )->seed( 2, 2, 1 );

    expect( $registry->get( 'picking', 'processing' ) )->not->toBeNull();

    app( ArtisanPackUI\Ecommerce\Demo\DemoSeeder::class )->wipe();

    expect( $registry->get( 'picking', 'processing' ) )->toBeNull();
} );
