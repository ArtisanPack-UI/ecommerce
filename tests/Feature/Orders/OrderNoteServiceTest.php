<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderNote;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Services\OrderNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->service = app( OrderNoteService::class );
    $this->order   = Order::factory()->create();
} );

it( 'adds an internal note and records it on the timeline', function (): void {
    $note = $this->service->add( $this->order, "  Called the customer.\nThey will pick up.  ", 5 );

    expect( $note->body )->toBe( "Called the customer.\nThey will pick up." )
        ->and( $note->author_user_id )->toBe( 5 )
        ->and( $note->is_customer_visible )->toBeFalse();

    $entry = OrderTimelineEntry::query()->where( 'order_id', $this->order->id )->sole();

    expect( $entry->event_type )->toBe( 'note.added' )
        ->and( $entry->actor_user_id )->toBe( 5 )
        ->and( $entry->payload['note_id'] )->toBe( $note->id )
        ->and( $entry->payload['excerpt'] )->toBe( 'Called the customer.' );
} );

it( 'adds a customer-visible note', function (): void {
    $note = $this->service->add( $this->order, 'Shipping Monday.', null, true );

    expect( $note->is_customer_visible )->toBeTrue()
        ->and( $this->service->list( $this->order, true )->modelKeys() )->toBe( [ $note->id ] );
} );

it( 'rejects empty and over-long notes', function ( string $body ): void {
    $this->service->add( $this->order, $body );
} )->with( [
    'blank'    => '   ',
    'too long' => str_repeat( 'a', OrderNoteService::MAX_BODY_LENGTH + 1 ),
] )->throws( InvalidArgumentException::class );

it( 'lists notes newest first', function (): void {
    $first  = $this->service->add( $this->order, 'First' );
    $second = $this->service->add( $this->order, 'Second' );

    expect( $this->service->list( $this->order )->modelKeys() )->toBe( [ $second->id, $first->id ] )
        ->and( $this->service->list( $this->order, true ) )->toHaveCount( 0 );
} );

it( 'deletes a note and keeps a record of it on the timeline', function (): void {
    $note = $this->service->add( $this->order, 'Wrong order' );

    $this->service->delete( $note, 9 );

    expect( OrderNote::query()->count() )->toBe( 0 );

    $entry = OrderTimelineEntry::query()->where( 'event_type', 'note.deleted' )->sole();

    expect( $entry->actor_user_id )->toBe( 9 )
        ->and( $entry->payload )->toBe( [ 'note_id' => $note->id, 'excerpt' => 'Wrong order' ] );
} );
