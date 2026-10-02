<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\SettingsWriteException;
use ArtisanPackUI\Ecommerce\Models\EcommerceSetting;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\SettingsRegistry;
use ArtisanPackUI\Ecommerce\Settings\CoreSettings;
use ArtisanPackUI\Ecommerce\Settings\SettingDefinition;
use ArtisanPackUI\Ecommerce\Settings\SettingSecret;
use ArtisanPackUI\Ecommerce\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses( RefreshDatabase::class );

function settings(): SettingsRepository
{
    return app( SettingsRepository::class );
}

it( 'registers every core group and allow-lists the core keys', function (): void {
    $registry = app( SettingsRegistry::class );

    expect( array_keys( $registry->groups() ) )->toBe( CoreSettings::GROUPS )
        ->and( $registry->has( 'tax.provider' ) )->toBeTrue()
        ->and( $registry->has( 'base_currency' ) )->toBeTrue()
        ->and( $registry->definition( 'checkout.reservation_ttl_minutes' )?->group )->toBe( 'checkout' );
} );

it( 'falls back to config when nothing is stored', function (): void {
    config()->set( 'artisanpack.ecommerce.checkout.reservation_ttl_minutes', 15 );

    expect( settings()->get( 'checkout.reservation_ttl_minutes' ) )->toBe( 15 )
        ->and( ecommerceSetting( 'checkout.reservation_ttl_minutes' ) )->toBe( 15 )
        ->and( settings()->isStored( 'checkout.reservation_ttl_minutes' ) )->toBeFalse();
} );

it( 'stores a value, overlays it on config, and caches it', function (): void {
    $changes = settings()->update( 'checkout', [ 'checkout.reservation_ttl_minutes' => '30' ] );

    expect( $changes['checkout.reservation_ttl_minutes']['to'] )->toBe( 30 )
        ->and( EcommerceSetting::query()->where( 'key', 'checkout.reservation_ttl_minutes' )->value( 'value' ) )->toBe( 30 )
        ->and( config( 'artisanpack.ecommerce.checkout.reservation_ttl_minutes' ) )->toBe( 30 )
        ->and( settings()->get( 'checkout.reservation_ttl_minutes' ) )->toBe( 30 )
        ->and( Cache::get( SettingsRepository::CACHE_KEY ) )->toBe( [ 'checkout.reservation_ttl_minutes' => 30 ] );
} );

it( 'busts the cache on write so another process reads the new value', function (): void {
    settings()->update( 'checkout', [ 'checkout.reservation_ttl_minutes' => 20 ] );
    settings()->update( 'checkout', [ 'checkout.reservation_ttl_minutes' => 25 ] );

    // A fresh repository (another request) reads through the cache.
    $fresh = new SettingsRepository( app( SettingsRegistry::class ), config() );

    expect( $fresh->get( 'checkout.reservation_ttl_minutes' ) )->toBe( 25 );
} );

it( 'applies stored values to config when a key is defined, as on the next boot', function (): void {
    EcommerceSetting::query()->create( [ 'key' => 'tax.prices_include_tax', 'value' => true ] );
    config()->set( 'artisanpack.ecommerce.tax.prices_include_tax', false );

    settings()->refresh();

    expect( config( 'artisanpack.ecommerce.tax.prices_include_tax' ) )->toBeTrue();
} );

it( 'resets a stored value back to the config default', function (): void {
    config()->set( 'artisanpack.ecommerce.reviews.allow_guests', true );
    settings()->refresh();

    settings()->update( 'reviews', [ 'reviews.allow_guests' => false ] );

    expect( config( 'artisanpack.ecommerce.reviews.allow_guests' ) )->toBeFalse();

    $changes = settings()->forget( 'reviews', [ 'reviews.allow_guests' ] );

    expect( $changes['reviews.allow_guests']['reset'] )->toBeTrue()
        ->and( settings()->isStored( 'reviews.allow_guests' ) )->toBeFalse()
        ->and( config( 'artisanpack.ecommerce.reviews.allow_guests' ) )->toBeTrue();
} );

it( 'skips values that equal the current one', function (): void {
    config()->set( 'artisanpack.ecommerce.kanban.stale_after_days', 3 );

    expect( settings()->update( 'kanban', [ 'kanban.stale_after_days' => 3 ] ) )->toBe( [] )
        ->and( EcommerceSetting::query()->count() )->toBe( 0 );
} );

it( 'fires the updated hook after commit with the changes', function (): void {
    $seen = null;

    addAction( 'ap.ecommerce.settings.updated', function ( string $group, array $changes ) use ( &$seen ): void {
        $seen = [ $group, array_keys( $changes ) ];
    } );

    settings()->update( 'general', [ 'notifications.store_name' => 'Acme' ] );

    expect( $seen )->toBe( [ 'general', [ 'notifications.store_name' ] ] );
} );

it( 'refuses unknown groups, keys from another group, and keys that are not allow-listed', function (): void {
    expect( fn () => settings()->update( 'nope', [] ) )->toThrow( SettingsWriteException::class );

    try {
        settings()->update( 'general', [ 'tax.provider' => 'manual', 'api.services' => [] ] );
        $this->fail( 'Expected a refusal.' );
    } catch ( SettingsWriteException $exception ) {
        expect( array_column( $exception->errors, 'field' ) )->toBe( [ 'tax.provider', 'api.services' ] )
            ->and( array_unique( array_column( $exception->errors, 'code' ) ) )->toBe( [ 'unknown-setting' ] );
    }

    expect( EcommerceSetting::query()->count() )->toBe( 0 );
} );

it( 'validates values against the definition and the type', function ( string $group, string $key, mixed $value ): void {
    try {
        settings()->update( $group, [ $key => $value ] );
        $this->fail( 'Expected a validation failure.' );
    } catch ( SettingsWriteException $exception ) {
        expect( $exception->errors[0]['field'] )->toBe( $key )
            ->and( $exception->errors[0]['code'] )->toBe( 'invalid' )
            ->and( $exception->messagesByField() )->toHaveKey( $key );
    }

    expect( settings()->isStored( $key ) )->toBeFalse();
} )->with( [
    'integer below min'      => [ 'checkout', 'checkout.reservation_ttl_minutes', 0 ],
    'not an integer'         => [ 'checkout', 'checkout.reservation_ttl_minutes', 'soon' ],
    'bad e-mail'             => [ 'general', 'notifications.support_email', 'not-an-email' ],
    'unknown currency'       => [ 'general', 'base_currency', 'ZZZ' ],
    'bad time zone'          => [ 'general', 'timezone', 'Mars/Olympus' ],
    'unregistered provider'  => [ 'tax', 'tax.provider', 'avalara' ],
    'bad list entry'         => [ 'notifications', 'notifications.admin_emails', [ 'ops@example.com', 'nope' ] ],
    'unknown fraud provider' => [ 'payments', 'fraud.provider', 'always-approve,nope' ],
    'not a boolean'          => [ 'reviews', 'reviews.allow_guests', 'maybe' ],
] );

it( 'normalizes submitted values to the type', function (): void {
    settings()->update( 'general', [ 'base_currency' => 'eur', 'notifications.store_name' => '  Acme  ' ], true );
    settings()->update( 'notifications', [ 'notifications.admin_emails' => [ ' ops@example.com ', '' ] ] );
    settings()->update( 'reviews', [ 'reviews.allow_guests' => '0' ] );

    expect( settings()->get( 'base_currency' ) )->toBe( 'EUR' )
        ->and( settings()->get( 'notifications.store_name' ) )->toBe( 'Acme' )
        ->and( settings()->get( 'notifications.admin_emails' ) )->toBe( [ 'ops@example.com' ] )
        ->and( settings()->get( 'reviews.allow_guests' ) )->toBeFalse();
} );

it( 'needs confirmation to change the base currency and leaves historical orders alone', function (): void {
    config()->set( 'artisanpack.ecommerce.base_currency', 'USD' );
    settings()->refresh();

    $order = Order::factory()->create( [ 'currency' => 'EUR', 'base_currency' => 'USD', 'fx_rate_to_base_e8' => 108_000_000 ] );

    try {
        settings()->update( 'general', [ 'base_currency' => 'GBP' ] );
        $this->fail( 'Expected an unconfirmed base-currency change to be refused.' );
    } catch ( SettingsWriteException $exception ) {
        expect( $exception->errors[0]['code'] )->toBe( 'base-currency-change-unconfirmed' );
    }

    $seen = null;
    addAction( 'ap.ecommerce.settings.baseCurrencyChanged', function ( string $from, string $to ) use ( &$seen ): void {
        $seen = [ $from, $to ];
    } );

    settings()->update( 'general', [ 'base_currency' => 'GBP' ], true );

    $order->refresh();

    expect( config( 'artisanpack.ecommerce.base_currency' ) )->toBe( 'GBP' )
        ->and( $seen )->toBe( [ 'USD', 'GBP' ] )
        ->and( $order->base_currency )->toBe( 'USD' )
        ->and( $order->fx_rate_to_base_e8 )->toBe( 108_000_000 );
} );

it( 'never stores or returns secrets, and reports whether each is configured', function (): void {
    config()->set( 'artisanpack.ecommerce.gateways.stripe.secret_key', 'sk_test_123' );
    config()->set( 'artisanpack.ecommerce.gateways.stripe.webhook_secret', null );

    $secrets = settings()->secrets( 'payments' );

    expect( $secrets )->toMatchArray( [
        'artisanpack.ecommerce.gateways.stripe.secret_key'     => true,
        'artisanpack.ecommerce.gateways.stripe.webhook_secret' => false,
    ] )
        ->and( json_encode( settings()->values( 'payments' ) ) )->not->toContain( 'sk_test_123' )
        ->and( fn () => settings()->update( 'payments', [ 'gateways.stripe.secret_key' => 'sk_live' ] ) )->toThrow( SettingsWriteException::class );
} );

it( 'refuses to allow-list a secret as an editable setting', function (): void {
    $registry = app( SettingsRegistry::class );

    $registry->define( new SettingDefinition( 'gateways.stripe.secret_key', 'payments', 'string', 'Secret' ) );
} )->throws( InvalidArgumentException::class );

it( 'lets satellites add groups, keys with their own config path, and secrets', function (): void {
    config()->set( 'artisanpack.ecommerce-paypal.enabled', false );

    app( SettingsRegistry::class )
        ->addGroup( 'paypal', 'PayPal', 55 )
        ->define( [ 'key' => 'paypal.enabled', 'group' => 'paypal', 'type' => 'boolean', 'label' => 'Enable PayPal', 'config_key' => 'artisanpack.ecommerce-paypal.enabled' ] )
        ->addSecret( new SettingSecret( 'artisanpack.ecommerce-paypal.client_secret', 'paypal', 'Client secret' ) );

    settings()->update( 'paypal', [ 'paypal.enabled' => true ] );

    expect( config( 'artisanpack.ecommerce-paypal.enabled' ) )->toBeTrue()
        ->and( array_keys( app( SettingsRegistry::class )->groups() ) )->toContain( 'paypal' )
        ->and( settings()->secrets( 'paypal' ) )->toBe( [ 'artisanpack.ecommerce-paypal.client_secret' => false ] );
} );

it( 'refuses definitions for unknown groups and duplicate keys', function (): void {
    $registry = app( SettingsRegistry::class );

    expect( fn () => $registry->define( new SettingDefinition( 'x.y', 'missing', 'string', 'X' ) ) )->toThrow( InvalidArgumentException::class )
        ->and( fn () => $registry->define( new SettingDefinition( 'tax.provider', 'tax', 'string', 'Again' ) ) )->toThrow( InvalidArgumentException::class )
        ->and( fn () => new SettingDefinition( 'x.y', 'tax', 'colour', 'X' ) )->toThrow( InvalidArgumentException::class );
} );

it( 'serves config before the settings table exists', function (): void {
    Illuminate\Support\Facades\Schema::drop( 'ecommerce_settings' );
    Cache::forget( SettingsRepository::CACHE_KEY );
    config()->set( 'artisanpack.ecommerce.checkout.reservation_ttl_minutes', 45 );

    $repository = new SettingsRepository( app( SettingsRegistry::class ), config() );

    expect( $repository->stored() )->toBe( [] )
        ->and( $repository->get( 'checkout.reservation_ttl_minutes' ) )->toBe( 45 );
} );

it( 'reads the admin value in queue workers that started before the change', function (): void {
    EcommerceSetting::query()->create( [ 'key' => 'kanban.stale_after_days', 'value' => 9 ] );
    Cache::forget( SettingsRepository::CACHE_KEY );

    $job = Mockery::mock( Illuminate\Contracts\Queue\Job::class );
    $job->allows( 'payload' )->andReturn( [] );

    event( new Illuminate\Queue\Events\JobProcessing( 'sync', $job ) );

    expect( config( 'artisanpack.ecommerce.kanban.stale_after_days' ) )->toBe( 9 );
} );

it( 'keeps stored values out of config while config:cache runs', function (): void {
    EcommerceSetting::query()->create( [ 'key' => 'kanban.stale_after_days', 'value' => 9 ] );
    config()->set( 'artisanpack.ecommerce.kanban.stale_after_days', 3 );

    settings()->setOverlayEnabled( false );
    settings()->refresh();

    expect( config( 'artisanpack.ecommerce.kanban.stale_after_days' ) )->toBe( 3 )
        ->and( settings()->get( 'kanban.stale_after_days' ) )->toBe( 9 );

    settings()->setOverlayEnabled( true );
    settings()->refresh();

    expect( config( 'artisanpack.ecommerce.kanban.stale_after_days' ) )->toBe( 9 );
} );

it( 'does not revert config the host sets at runtime on keys nothing stored', function (): void {
    config()->set( 'artisanpack.ecommerce.kanban.stale_after_days', 12 );

    settings()->refresh();

    expect( config( 'artisanpack.ecommerce.kanban.stale_after_days' ) )->toBe( 12 );
} );

it( 'refuses payment settings that would break checkout', function (): void {
    config()->set( 'artisanpack.ecommerce.gateways.stripe.secret_key', null );

    try {
        settings()->update( 'payments', [ 'gateways.stripe.enabled' => true ] );
        $this->fail( 'Expected Stripe without a key to be refused.' );
    } catch ( SettingsWriteException $exception ) {
        expect( $exception->errors[0]['code'] )->toBe( 'gateway-not-configured' );
    }

    config()->set( 'artisanpack.ecommerce.gateways.stripe.secret_key', 'sk_test_123' );
    config()->set( 'artisanpack.ecommerce.gateways.stripe.enabled', true );
    config()->set( 'artisanpack.ecommerce.fraud.provider', 'stripe-radar' );
    settings()->refresh();

    try {
        settings()->update( 'payments', [ 'gateways.stripe.enabled' => false ] );
        $this->fail( 'Expected disabling Stripe under a Radar chain to be refused.' );
    } catch ( SettingsWriteException $exception ) {
        expect( $exception->errors[0]['code'] )->toBe( 'fraud-provider-unavailable' );
    }

    expect( settings()->isStored( 'gateways.stripe.enabled' ) )->toBeFalse();
} );

it( 'answers a non-string fraud chain with a validation error', function (): void {
    expect( fn () => settings()->update( 'payments', [ 'fraud.provider' => [ 'always-approve' ] ] ) )->toThrow( SettingsWriteException::class );
} );

it( 'refuses a select value when the setting has no options', function (): void {
    ArtisanPackUI\Ecommerce\Models\TaxClass::query()->delete();

    expect( fn () => settings()->update( 'tax', [ 'tax.default_class' => 'anything' ] ) )->toThrow( SettingsWriteException::class );
} );

it( 'refuses a definition that contains or sits inside a secret', function (): void {
    $registry = app( SettingsRegistry::class );

    expect( fn () => $registry->define( new SettingDefinition( 'stripe.all', 'payments', 'map', 'Stripe', configKey: 'artisanpack.ecommerce.gateways.stripe' ) ) )->toThrow( InvalidArgumentException::class )
        ->and( fn () => $registry->define( new SettingDefinition( 'stripe.key.part', 'payments', 'string', 'Part', configKey: 'artisanpack.ecommerce.gateways.stripe.secret_key.part' ) ) )->toThrow( InvalidArgumentException::class );
} );
