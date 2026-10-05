<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\ActivityLogEntry;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerNote;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Services\ActivityLogService;
use ArtisanPackUI\Ecommerce\Services\CustomerNoteService;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    $this->service = app( ActivityLogService::class );
} );

afterEach( function (): void {
    removeAllFilters( 'ap.ecommerce.activity.recording' );
    removeAllActions( 'ap.ecommerce.activity.recorded' );
} );

it( 'creates the activity log and customer notes tables', function (): void {
    expect( Schema::hasColumns( 'ecommerce_activity_log', [ 'id', 'subject_type', 'subject_id', 'actor_user_id', 'event_type', 'payload', 'created_at' ] ) )->toBeTrue()
        ->and( Schema::hasColumn( 'ecommerce_activity_log', 'updated_at' ) )->toBeFalse()
        ->and( Schema::hasColumns( 'ecommerce_customer_notes', [ 'id', 'customer_id', 'author_user_id', 'body', 'created_at', 'updated_at' ] ) )->toBeTrue();
} );

it( 'records an entry against a subject with the signed-in user as actor', function (): void {
    $product = Product::factory()->create();
    $this->actingAs( new GenericUser( [ 'id' => 7 ] ) );

    $entry = $this->service->record( $product, 'product.custom', [ 'foo' => 'bar' ] );

    expect( $entry )->toBeInstanceOf( ActivityLogEntry::class )
        ->and( $entry->subject_type )->toBe( $product->getMorphClass() )
        ->and( $entry->subject_id )->toBe( $product->id )
        ->and( $entry->actor_user_id )->toBe( 7 )
        ->and( $entry->payload )->toBe( [ 'foo' => 'bar' ] )
        ->and( $entry->created_at )->not->toBeNull()
        ->and( $entry->subject->is( $product ) )->toBeTrue();
} );

it( 'prefers an explicit actor over the signed-in user', function (): void {
    $this->actingAs( new GenericUser( [ 'id' => 7 ] ) );

    expect( $this->service->record( Product::factory()->create(), 'product.custom', [], 3 )->actor_user_id )->toBe( 3 );
} );

it( 'lets the recording filter skip or change an entry', function (): void {
    $product = Product::factory()->create();

    addFilter( 'ap.ecommerce.activity.recording', fn ( array $attributes ): array|false => 'product.secret' === $attributes['event_type'] ? false : $attributes + [ 'payload' => [] ] );

    expect( $this->service->record( $product, 'product.secret' ) )->toBeNull()
        ->and( $this->service->record( $product, 'product.shown' ) )->not->toBeNull();
} );

it( 'fires the recorded action', function (): void {
    $captured = null;
    addAction( 'ap.ecommerce.activity.recorded', function ( ActivityLogEntry $entry ) use ( &$captured ): void {
        $captured = $entry->event_type;
    } );

    $this->service->record( Product::factory()->create(), 'product.custom' );

    expect( $captured )->toBe( 'product.custom' );
} );

it( 'records nothing when the activity log is disabled', function (): void {
    config()->set( 'artisanpack.ecommerce.activity_log.enabled', false );

    $product = Product::factory()->create();
    $product->update( [ 'name' => 'Renamed' ] );

    expect( $this->service->record( $product, 'product.custom' ) )->toBeNull()
        ->and( ActivityLogEntry::query()->count() )->toBe( 0 );
} );

it( 'lists a subject\'s entries newest first and only its own', function (): void {
    $product = Product::factory()->create();
    $other   = Product::factory()->create();

    $this->service->record( $product, 'product.first' );
    $this->service->record( $product, 'product.second' );
    $this->service->record( $other, 'product.other' );

    expect( $this->service->forSubject( $product )->pluck( 'event_type' )->all() )
        ->toBe( [ 'product.second', 'product.first', 'product.created' ] );
} );

it( 'keeps entries append-only', function (): void {
    $entry = $this->service->record( Product::factory()->create(), 'product.custom' );

    expect( fn () => $entry->update( [ 'event_type' => 'x' ] ) )->toThrow( LogicException::class )
        ->and( fn () => $entry->delete() )->toThrow( LogicException::class )
        ->and( fn () => ActivityLogEntry::query()->update( [ 'event_type' => 'x' ] ) )->toThrow( LogicException::class )
        ->and( fn () => ActivityLogEntry::query()->delete() )->toThrow( LogicException::class );
} );

it( 'documents every event type the engine records', function (): void {
    expect( array_keys( ActivityLogService::EVENT_TYPES ) )->toContain(
        'product.updated',
        'variant.created',
        'price.deleted',
        'inventory.adjusted',
        'customer.updated',
        'note.added',
        'note.deleted',
        'promotion.created',
        'coupon.deleted',
    );
} );

it( 'scrubs a customer\'s notes and personal data', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'jane@example.test', 'first_name' => 'Jane' ] );
    $other    = Customer::factory()->create( [ 'email' => 'bob@example.test' ] );

    $customer->update( [ 'phone' => '555-0100' ] );
    app( CustomerNoteService::class )->add( $customer, 'Prefers phone contact' );
    app( CustomerNoteService::class )->add( $other, 'Keep me' );

    $scrubbed = $this->service->scrubCustomer( $customer );

    $payloads = $this->service->forSubject( $customer )->get()->pluck( 'payload', 'event_type' );

    expect( $scrubbed )->toBe( 3 )
        ->and( CustomerNote::query()->where( 'customer_id', $customer->id )->count() )->toBe( 0 )
        ->and( CustomerNote::query()->where( 'customer_id', $other->id )->count() )->toBe( 1 )
        ->and( $payloads['customer.created']['email'] )->toBe( ActivityLogService::REDACTED )
        ->and( $payloads['customer.created']['first_name'] )->toBe( ActivityLogService::REDACTED )
        ->and( $payloads['customer.updated']['changes']['phone'] )->toBe( [ 'before' => null, 'after' => ActivityLogService::REDACTED ] )
        ->and( $payloads['note.added']['excerpt'] )->toBe( ActivityLogService::REDACTED )
        ->and( $payloads['note.added']['note_id'] )->toBeInt()
        ->and( $this->service->forSubject( $other )->where( 'event_type', 'customer.created' )->first()->payload['email'] )->toBe( 'bob@example.test' );
} );

it( 'pauses recording inside withoutRecording, so anonymizing after a scrub leaves no personal data', function (): void {
    $customer = Customer::factory()->create( [ 'email' => 'real@example.test' ] );
    $service  = app( ActivityLogService::class );

    Illuminate\Support\Facades\DB::transaction( function () use ( $service, $customer ): void {
        $service->withoutRecording( fn () => $customer->update( [ 'email' => 'anon@example.invalid' ] ) );
        $service->scrubCustomer( $customer );
    } );

    $payloads = json_encode( ActivityLogEntry::query()->pluck( 'payload' )->all() );

    expect( $payloads )->not->toContain( 'real@example.test' )
        ->and( $service->enabled() )->toBeTrue();
} );
