<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Auth\ServiceActor;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\TaxClass;
use ArtisanPackUI\Ecommerce\Models\WebhookSubscription;
use ArtisanPackUI\Ecommerce\Policies\OrderPolicy;
use ArtisanPackUI\Ecommerce\Policies\ProductPolicy;
use ArtisanPackUI\Ecommerce\Policies\TaxRatePolicy;
use ArtisanPackUI\Ecommerce\Policies\WebhookSubscriptionPolicy;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses( RefreshDatabase::class );

it( 'registers the engine policy set', function (): void {
    expect( Gate::getPolicyFor( Order::class ) )->toBeInstanceOf( OrderPolicy::class )
        ->and( Gate::getPolicyFor( Product::class ) )->toBeInstanceOf( ProductPolicy::class )
        ->and( Gate::getPolicyFor( TaxClass::class ) )->toBeInstanceOf( TaxRatePolicy::class )
        ->and( Gate::getPolicyFor( WebhookSubscription::class ) )->toBeInstanceOf( WebhookSubscriptionPolicy::class );
} );

it( 'denies by default and falls back to the umbrella ecommerce.admin ability', function (): void {
    $user  = new GenericUser( [ 'id' => 1 ] );
    $order = Order::factory()->create();

    expect( Gate::forUser( $user )->allows( 'refund', $order ) )->toBeFalse();

    Gate::define( 'ecommerce.admin', fn (): bool => true );

    expect( Gate::forUser( $user )->allows( 'refund', $order ) )->toBeTrue()
        ->and( Gate::forUser( $user )->allows( 'viewAny', Product::class ) )->toBeTrue()
        ->and( Gate::forUser( $user )->allows( 'create', Refund::class ) )->toBeTrue();
} );

it( 'lets a specific ability override the umbrella and receive the model', function (): void {
    $user  = new GenericUser( [ 'id' => 1 ] );
    $order = Order::factory()->create();
    $seen  = null;

    Gate::define( 'ecommerce.admin', fn (): bool => true );
    Gate::define( 'ecommerce.order.edit-fulfilled', function ( $user, $subject ) use ( &$seen ): bool {
        $seen = $subject;

        return false;
    } );

    expect( Gate::forUser( $user )->allows( 'edit-fulfilled', $order ) )->toBeFalse()
        ->and( $seen?->is( $order ) )->toBeTrue()
        ->and( Gate::forUser( $user )->allows( 'cancel', $order ) )->toBeTrue();
} );

it( 'routes policy decisions through the ability filter with the subject', function (): void {
    $user     = new GenericUser( [ 'id' => 1 ] );
    $customer = Customer::factory()->create();
    $received = null;

    addFilter( 'ap.ecommerce.abilities.customer.delete', function ( bool $allowed, $user, $request, $subject ) use ( &$received ): bool {
        $received = $subject;

        return true;
    } );

    expect( Gate::forUser( $user )->allows( 'delete', $customer ) )->toBeTrue()
        ->and( $received?->is( $customer ) )->toBeTrue();
} );

it( 'lets a shopper view their own order but not someone else\'s', function (): void {
    $mine   = Order::factory()->create( [ 'customer_id' => Customer::factory()->create( [ 'user_id' => 5 ] )->id ] );
    $theirs = Order::factory()->create( [ 'customer_id' => Customer::factory()->create( [ 'user_id' => 6 ] )->id ] );
    $user   = new GenericUser( [ 'id' => 5 ] );

    expect( Gate::forUser( $user )->allows( 'view', $mine ) )->toBeTrue()
        ->and( Gate::forUser( $user )->allows( 'view', $theirs ) )->toBeFalse()
        ->and( Gate::forUser( $user )->allows( 'update', $mine ) )->toBeFalse();
} );

it( 'decides service actors by their configured abilities alone', function (): void {
    Gate::define( 'ecommerce.admin', fn (): bool => true );
    $order = Order::factory()->create();

    $reader = new ServiceActor( 'erp', [ 'ecommerce:orders.read' ] );

    expect( Gate::forUser( $reader )->allows( 'view', $order ) )->toBeTrue()
        ->and( Gate::forUser( $reader )->allows( 'refund', $order ) )->toBeFalse()
        ->and( Gate::forUser( new ServiceActor( 'root', [ '*' ] ) )->allows( 'refund', $order ) )->toBeTrue();
} );
