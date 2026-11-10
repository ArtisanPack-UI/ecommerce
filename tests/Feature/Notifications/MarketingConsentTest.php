<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerNotificationPreference;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use ArtisanPackUI\Ecommerce\Services\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

/*
 * #194: marketing notifications need the customer's marketing consent.
 */

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'artisanpack.ecommerce.notifications.preference_channels', [ 'mail', 'sms' ] );

    $this->preferences = app( NotificationPreferenceService::class );
} );

function storeMarketingRow( Customer $customer, string $channel, bool $enabled ): void
{
    CustomerNotificationPreference::query()->updateOrCreate(
        [ 'customer_id' => $customer->id, 'channel' => $channel, 'category' => 'marketing' ],
        [ 'is_enabled' => $enabled ],
    );
}

function marketingRow( array $rows, string $channel ): array
{
    return collect( $rows )->first( fn ( array $row ): bool => $channel === $row['channel'] && 'marketing' === $row['category'] );
}

it( 'ignores a stored "on" row for a customer without consent', function (): void {
    $customer = Customer::factory()->create( [ 'accepts_marketing' => false ] );
    storeMarketingRow( $customer, 'mail', true );

    expect( $this->preferences->allows( $customer, 'mail', 'marketing' ) )->toBeFalse()
        ->and( $this->preferences->allowsEmail( $customer->email, 'mail', 'marketing' ) )->toBeFalse()
        ->and( marketingRow( $this->preferences->all( $customer ), 'mail' )['is_enabled'] )->toBeFalse();
} );

it( 'honors a stored row for a customer with consent', function (): void {
    $customer = Customer::factory()->create( [ 'accepts_marketing' => true ] );

    expect( $this->preferences->allows( $customer, 'mail', 'marketing' ) )->toBeTrue();

    storeMarketingRow( $customer, 'mail', false );

    expect( $this->preferences->allows( $customer, 'mail', 'marketing' ) )->toBeFalse()
        ->and( $this->preferences->allows( $customer, 'sms', 'marketing' ) )->toBeTrue()
        ->and( marketingRow( $this->preferences->all( $customer ), 'sms' )['is_enabled'] )->toBeTrue();
} );

it( 'leaves other categories as they were', function (): void {
    $customer = Customer::factory()->create( [ 'accepts_marketing' => false ] );

    expect( $this->preferences->allows( $customer, 'mail', 'review-requests' ) )->toBeTrue()
        ->and( $this->preferences->allows( $customer, 'mail', 'transactional' ) )->toBeTrue();
} );

it( 'turns marketing off everywhere, and stores it off on every channel, when consent is withdrawn', function (): void {
    $customer = Customer::factory()->create( [ 'accepts_marketing' => true ] );
    storeMarketingRow( $customer, 'mail', true );

    app( CustomerService::class )->updateProfile( $customer, [ 'accepts_marketing' => false ] );

    $customer->refresh();

    expect( $this->preferences->allows( $customer, 'mail', 'marketing' ) )->toBeFalse()
        ->and( $this->preferences->allows( $customer, 'sms', 'marketing' ) )->toBeFalse()
        ->and( CustomerNotificationPreference::query()->where( 'customer_id', $customer->id )->where( 'category', 'marketing' )->orderBy( 'channel' )->pluck( 'is_enabled', 'channel' )->all() )
        ->toBe( [ 'mail' => false, 'sms' => false ] );
} );

it( 'keeps marketing off after consent is given again, until the customer turns it on', function (): void {
    $customer = Customer::factory()->create( [ 'accepts_marketing' => true ] );

    app( CustomerService::class )->updateProfile( $customer, [ 'accepts_marketing' => false ] );
    app( CustomerService::class )->updateProfile( $customer, [ 'accepts_marketing' => true ] );

    $customer->refresh();

    expect( $this->preferences->allows( $customer, 'mail', 'marketing' ) )->toBeFalse();

    $this->preferences->update( $customer, [ [ 'channel' => 'mail', 'category' => 'marketing', 'is_enabled' => true ] ] );

    expect( $this->preferences->allows( $customer, 'mail', 'marketing' ) )->toBeTrue();
} );

it( 'refuses to turn marketing on without consent, storing nothing', function (): void {
    $customer = Customer::factory()->create( [ 'accepts_marketing' => false ] );

    expect( fn () => $this->preferences->update( $customer, [
        [ 'channel' => 'mail', 'category' => 'review-requests', 'is_enabled' => false ],
        [ 'channel' => 'mail', 'category' => 'marketing', 'is_enabled' => true ],
    ] ) )->toThrow( ValidationException::class );

    expect( $customer->notificationPreferences()->count() )->toBe( 0 );
} );

it( 'always lets marketing be turned off, so unsubscribe keeps working', function (): void {
    $customer = Customer::factory()->create( [ 'accepts_marketing' => false ] );

    $this->preferences->update( $customer, [ [ 'channel' => 'mail', 'category' => 'marketing', 'is_enabled' => false ] ] );

    expect( $this->preferences->unsubscribe( $customer->email, 'mail', 'marketing' ) )->toBeTrue()
        ->and( $this->preferences->allows( $customer, 'mail', 'marketing' ) )->toBeFalse();
} );
