<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

require_once __DIR__ . '/ApiTestHelpers.php';

uses( RefreshDatabase::class );

it( 'lists the settings groups', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( '/api/ecommerce/v1/admin/settings' )
        ->assertOk()
        ->assertJsonPath( 'data.0.key', 'general' )
        ->assertJsonCount( 10, 'data' );
} );

it( 'shows a group with values and secret statuses, never secret values', function (): void {
    config()->set( 'artisanpack.ecommerce.gateways.stripe.secret_key', 'sk_test_secret' );

    $response = $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->getJson( '/api/ecommerce/v1/admin/settings/payments' )
        ->assertOk()
        ->assertJsonPath( 'data.key', 'payments' )
        ->assertJsonPath( 'data.settings.0.key', 'gateways.stripe.enabled' )
        ->assertJsonPath( 'data.settings.0.type', 'boolean' )
        ->assertJsonPath( 'data.secrets.0.key', 'artisanpack.ecommerce.gateways.stripe.secret_key' )
        ->assertJsonPath( 'data.secrets.0.configured', true );

    expect( $response->getContent() )->not->toContain( 'sk_test_secret' );
} );

it( 'returns 404 for an unknown group', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )->getJson( '/api/ecommerce/v1/admin/settings/nope' )->assertNotFound();
} );

it( 'updates and resets values in a group', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->patchJson( '/api/ecommerce/v1/admin/settings/checkout', [ 'values' => [ 'checkout.reservation_ttl_minutes' => 40 ] ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.settings.0.value', 40 )
        ->assertJsonPath( 'data.settings.0.stored', true )
        ->assertJsonPath( 'meta.changed', [ 'checkout.reservation_ttl_minutes' ] );

    $this->patchJson( '/api/ecommerce/v1/admin/settings/checkout', [ 'values' => [], 'reset' => [ 'checkout.reservation_ttl_minutes' ] ], idem() )
        ->assertOk()
        ->assertJsonPath( 'data.settings.0.stored', false );

    expect( app( SettingsRepository::class )->isStored( 'checkout.reservation_ttl_minutes' ) )->toBeFalse();
} );

it( 'refuses invalid values and unknown keys with a settings-write-failed problem', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->patchJson( '/api/ecommerce/v1/admin/settings/checkout', [ 'values' => [ 'checkout.reservation_ttl_minutes' => -1 ] ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'type', 'https://docs.artisanpack-ui.dev/ecommerce/problems/settings-write-failed' )
        ->assertJsonPath( 'errors.0.field', 'checkout.reservation_ttl_minutes' )
        ->assertJsonPath( 'errors.0.code', fn ( string $code ): bool => 1 === preg_match( '/^[a-z][a-z-]*$/', $code ) );

    $this->patchJson( '/api/ecommerce/v1/admin/settings/checkout', [ 'values' => [ 'tax.provider' => 'manual' ] ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'tax.provider' )
        ->assertJsonPath( 'errors.0.code', 'unknown-setting' );

    $this->patchJson( '/api/ecommerce/v1/admin/settings/checkout', [], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.field', 'values' );
} );

it( 'needs confirm_base_currency_change to change the base currency', function (): void {
    config()->set( 'artisanpack.ecommerce.base_currency', 'USD' );
    app( SettingsRepository::class )->refresh();

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->patchJson( '/api/ecommerce/v1/admin/settings/general', [ 'values' => [ 'base_currency' => 'EUR' ] ], idem() )
        ->assertStatus( 422 )
        ->assertJsonPath( 'errors.0.code', 'base-currency-change-unconfirmed' );

    $this->patchJson( '/api/ecommerce/v1/admin/settings/general', [ 'values' => [ 'base_currency' => 'EUR' ], 'confirm_base_currency_change' => true ], idem() )
        ->assertOk();

    expect( config( 'artisanpack.ecommerce.base_currency' ) )->toBe( 'EUR' );
} );

it( 'gates reads on settings.view and writes on settings.update', function (): void {
    $this->actingAs( ecommerceShopper(), 'sanctum' );

    $this->getJson( '/api/ecommerce/v1/admin/settings' )->assertForbidden();
    $this->getJson( '/api/ecommerce/v1/admin/settings/general' )->assertForbidden();
    $this->patchJson( '/api/ecommerce/v1/admin/settings/general', [ 'values' => [ 'notifications.store_name' => 'X' ] ], idem() )->assertForbidden();

    Gate::define( 'ecommerce.settings.view', fn (): bool => true );

    $this->getJson( '/api/ecommerce/v1/admin/settings/general' )->assertOk();
    $this->patchJson( '/api/ecommerce/v1/admin/settings/general', [ 'values' => [ 'notifications.store_name' => 'X' ] ], idem() )->assertForbidden();
} );

it( 'requires an Idempotency-Key on writes', function (): void {
    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->patchJson( '/api/ecommerce/v1/admin/settings/general', [ 'values' => [ 'notifications.store_name' => 'X' ] ] )
        ->assertStatus( 400 );
} );

it( 'refuses meta.tax_breakdown in an order update', function (): void {
    $order = ArtisanPackUI\Ecommerce\Models\Order::factory()->create();

    $this->actingAs( ecommerceAdmin(), 'sanctum' )
        ->patchJson( "/api/ecommerce/v1/orders/{$order->id}", [ 'meta' => [ 'tax_breakdown' => [] ] ], idem() )
        ->assertStatus( 422 );
} );
