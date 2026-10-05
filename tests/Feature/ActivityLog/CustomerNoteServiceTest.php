<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\ActivityLogEntry;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerNote;
use ArtisanPackUI\Ecommerce\Services\CustomerNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->notes    = app( CustomerNoteService::class );
    $this->customer = Customer::factory()->create();
} );

afterEach( function (): void {
    removeAllActions( 'ap.ecommerce.customer.noteAdded' );
} );

it( 'adds a note with its author and records note.added', function (): void {
    $fired = false;
    addAction( 'ap.ecommerce.customer.noteAdded', function () use ( &$fired ): void {
        $fired = true;
    } );

    $note  = $this->notes->add( $this->customer, "  Prefers\nphone contact  ", 4 );
    $entry = ActivityLogEntry::query()->forSubject( $this->customer )->where( 'event_type', 'note.added' )->first();

    expect( $note->body )->toBe( "Prefers\nphone contact" )
        ->and( $note->author_user_id )->toBe( 4 )
        ->and( $note->customer->is( $this->customer ) )->toBeTrue()
        ->and( $this->customer->notes()->count() )->toBe( 1 )
        ->and( $entry->actor_user_id )->toBe( 4 )
        ->and( $entry->payload )->toBe( [ 'note_id' => $note->id, 'excerpt' => 'Prefers phone contact' ] )
        ->and( $fired )->toBeTrue();
} );

it( 'rejects an empty or oversized note', function ( string $body ): void {
    expect( fn () => $this->notes->add( $this->customer, $body ) )->toThrow( InvalidArgumentException::class )
        ->and( CustomerNote::query()->count() )->toBe( 0 );
} )->with( [
    'empty'     => [ '   ' ],
    'too long'  => [ fn (): string => str_repeat( 'a', CustomerNoteService::MAX_BODY_LENGTH + 1 ) ],
] );

it( 'deletes a note and records note.deleted', function (): void {
    $note = $this->notes->add( $this->customer, 'Temporary' );

    $this->notes->delete( $note, 9 );

    $entry = ActivityLogEntry::query()->forSubject( $this->customer )->where( 'event_type', 'note.deleted' )->first();

    expect( CustomerNote::query()->count() )->toBe( 0 )
        ->and( $entry->actor_user_id )->toBe( 9 )
        ->and( $entry->payload['note_id'] )->toBe( $note->id );
} );

it( 'lists a customer\'s notes newest first', function (): void {
    $first  = $this->notes->add( $this->customer, 'First' );
    $second = $this->notes->add( $this->customer, 'Second' );
    $this->notes->add( Customer::factory()->create(), 'Elsewhere' );

    expect( $this->notes->list( $this->customer )->modelKeys() )->toBe( [ $second->id, $first->id ] );
} );

it( 'removes notes when the customer is deleted', function (): void {
    $this->notes->add( $this->customer, 'Gone soon' );

    $this->customer->delete();

    expect( CustomerNote::query()->count() )->toBe( 0 );
} );
