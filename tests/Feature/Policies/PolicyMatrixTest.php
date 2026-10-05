<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Auth\EcommerceAuthorizer;
use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\LicenseKey;
use ArtisanPackUI\Ecommerce\Models\NotificationTemplate;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\Policies\CouponPolicy;
use ArtisanPackUI\Ecommerce\Policies\CustomerPolicy;
use ArtisanPackUI\Ecommerce\Policies\DigitalFilePolicy;
use ArtisanPackUI\Ecommerce\Policies\EcommercePolicy;
use ArtisanPackUI\Ecommerce\Policies\KanbanBoardPolicy;
use ArtisanPackUI\Ecommerce\Policies\KanbanCardPolicy;
use ArtisanPackUI\Ecommerce\Policies\LicenseKeyPolicy;
use ArtisanPackUI\Ecommerce\Policies\NotificationTemplatePolicy;
use ArtisanPackUI\Ecommerce\Policies\OrderSubstatusPolicy;
use ArtisanPackUI\Ecommerce\Policies\PromotionPolicy;
use ArtisanPackUI\Ecommerce\Policies\RefundPolicy;
use ArtisanPackUI\Ecommerce\Policies\ReviewPolicy;
use ArtisanPackUI\Ecommerce\Policies\ShippingZonePolicy;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\Fixtures\ApiUser;

uses( RefreshDatabase::class );

/*
 * Batch K: a direct matrix over every policy that had no test of its own.
 * Each case is [ model class, policy class, resource, action, whether the
 * action takes the model ]. Every policy defers to EcommerceAuthorizer, so
 * each (policy, action) is checked for an admin, a plain shopper, a guest,
 * a resource-specific Gate ability, the ability filter, and a narrowed
 * token.
 */

afterEach( function (): void {
    foreach ( pmCases() as [ , , $resource, $action ] ) {
        removeAllFilters( sprintf( 'ap.ecommerce.abilities.%s.%s', $resource, $action ) );
    }
} );

/**
 * Every (policy, action) under test.
 *
 * @return array<string, array{0: class-string, 1: class-string, 2: string, 3: string, 4: bool}>
 */
function pmCases(): array
{
    $cases = [];
    $add   = static function ( string $model, string $policy, string $resource, array $actions ) use ( &$cases ): void {
        foreach ( $actions as $action => $withSubject ) {
            $cases[ "{$resource}.{$action}" ] = [ $model, $policy, $resource, $action, $withSubject ];
        }
    };

    $crud = [ 'viewAny' => false, 'view' => true, 'create' => false, 'update' => true, 'delete' => true ];

    $add( Coupon::class, CouponPolicy::class, 'coupon', [ 'create' => false, 'update' => true, 'delete' => true ] );
    $add( Customer::class, CustomerPolicy::class, 'customer', [ 'viewAny' => false, 'view' => true, 'update' => true, 'delete' => true ] );
    $add( DigitalFile::class, DigitalFilePolicy::class, 'digitalFile', [ 'viewAny' => false, 'create' => false, 'update' => true, 'delete' => true ] );
    $add( KanbanBoard::class, KanbanBoardPolicy::class, 'kanbanBoard', $crud );
    $add( OrderBoardAssignment::class, KanbanCardPolicy::class, 'kanbanCard', [ 'move' => true ] );
    $add( LicenseKey::class, LicenseKeyPolicy::class, 'licenseKey', [ 'view' => true, 'revoke' => true ] );
    $add( NotificationTemplate::class, NotificationTemplatePolicy::class, 'notificationTemplate', [ 'viewAny' => false, 'view' => true, 'update' => true ] );
    $add( OrderSubstatus::class, OrderSubstatusPolicy::class, 'orderSubstatus', $crud );
    $add( Promotion::class, PromotionPolicy::class, 'promotion', $crud );
    $add( Refund::class, RefundPolicy::class, 'refund', [ 'view' => true, 'create' => false ] );
    $add( ProductReview::class, ReviewPolicy::class, 'review', [ 'viewAny' => false, 'view' => true, 'moderate' => true, 'delete' => true ] );
    $add( ShippingZone::class, ShippingZonePolicy::class, 'shippingZone', [ 'viewAny' => false, 'create' => false, 'update' => true, 'delete' => true ] );

    return $cases;
}

/**
 * Whether `$user` (null: a guest) may perform the case, through the Gate as
 * a host app would ask it.
 */
function pmAllows( ?object $user, string $model, string $action, bool $withSubject ): bool
{
    $argument = $withSubject ? $model::factory()->create() : $model;

    return Gate::forUser( $user )->allows( $action, $argument );
}

it( 'maps each model to its policy', function ( string $model, string $policy ): void {
    expect( Gate::getPolicyFor( $model ) )->toBeInstanceOf( $policy );
} )->with( fn (): array => pmCases() );

it( 'allows an admin through the umbrella ability', function ( string $model, string $policy, string $resource, string $action, bool $withSubject ): void {
    Gate::define( 'ecommerce.admin', fn ( $user ): bool => 1 === (int) $user->getAuthIdentifier() );

    expect( pmAllows( new GenericUser( [ 'id' => 1 ] ), $model, $action, $withSubject ) )->toBeTrue();
} )->with( fn (): array => pmCases() );

it( 'denies a signed-in shopper without the ability', function ( string $model, string $policy, string $resource, string $action, bool $withSubject ): void {
    Gate::define( 'ecommerce.admin', fn ( $user ): bool => 1 === (int) $user->getAuthIdentifier() );
    Customer::factory()->create( [ 'user_id' => 2 ] );

    expect( pmAllows( new GenericUser( [ 'id' => 2 ] ), $model, $action, $withSubject ) )->toBeFalse();
} )->with( fn (): array => pmCases() );

it( 'denies a guest', function ( string $model, string $policy, string $resource, string $action, bool $withSubject ): void {
    Gate::define( 'ecommerce.admin', fn (): bool => true );

    expect( pmAllows( null, $model, $action, $withSubject ) )->toBeFalse()
        ->and( app( EcommerceAuthorizer::class )->allows( null, $resource, $action ) )->toBeFalse();
} )->with( fn (): array => pmCases() );

it( 'lets the resource-specific ability decide over the umbrella, with the model', function ( string $model, string $policy, string $resource, string $action, bool $withSubject ): void {
    $seen = 'unset';

    Gate::define( 'ecommerce.admin', fn (): bool => false );
    Gate::define( sprintf( 'ecommerce.%s.%s', $resource, $action ), function ( $user, $subject = null ) use ( &$seen ): bool {
        $seen = $subject;

        return true;
    } );

    expect( pmAllows( new GenericUser( [ 'id' => 9 ] ), $model, $action, $withSubject ) )->toBeTrue();

    $withSubject
        ? expect( $seen )->toBeInstanceOf( $model )
        : expect( $seen )->toBeNull();
} )->with( fn (): array => pmCases() );

it( 'lets the ability filter veto an admin', function ( string $model, string $policy, string $resource, string $action, bool $withSubject ): void {
    Gate::define( 'ecommerce.admin', fn (): bool => true );
    addFilter( sprintf( 'ap.ecommerce.abilities.%s.%s', $resource, $action ), fn (): bool => false );

    expect( pmAllows( new GenericUser( [ 'id' => 1 ] ), $model, $action, $withSubject ) )->toBeFalse();
} )->with( fn (): array => pmCases() );

it( 'limits a narrowed token to the scope the action needs', function ( string $model, string $policy, string $resource, string $action, bool $withSubject ): void {
    Gate::define( 'ecommerce.admin', fn (): bool => true );

    $scoped = Sanctum::actingAs( ApiUser::make( 1 ), [ TokenAbilities::forAction( $resource, $action ) ] );
    expect( pmAllows( $scoped, $model, $action, $withSubject ) )->toBeTrue();

    $other = Sanctum::actingAs( ApiUser::make( 1 ), [ 'ecommerce:unrelated.write' ] );
    expect( pmAllows( $other, $model, $action, $withSubject ) )->toBeFalse();

    $storefront = Sanctum::actingAs( ApiUser::make( 1 ), [ TokenAbilities::STOREFRONT ] );
    expect( pmAllows( $storefront, $model, $action, $withSubject ) )->toBeFalse();
} )->with( fn (): array => pmCases() );

it( 'lets a shopper view and update their own customer record, and nobody else\'s', function (): void {
    Gate::define( 'ecommerce.admin', fn ( $user ): bool => 1 === (int) $user->getAuthIdentifier() );

    $mine   = Customer::factory()->create( [ 'user_id' => 2 ] );
    $theirs = Customer::factory()->create( [ 'user_id' => 3 ] );
    $guest  = Customer::factory()->create( [ 'user_id' => null ] );
    $owner  = new GenericUser( [ 'id' => 2 ] );

    expect( Gate::forUser( $owner )->allows( 'view', $mine ) )->toBeTrue()
        ->and( Gate::forUser( $owner )->allows( 'update', $mine ) )->toBeTrue()
        ->and( Gate::forUser( $owner )->allows( 'delete', $mine ) )->toBeFalse()
        ->and( Gate::forUser( $owner )->allows( 'viewAny', Customer::class ) )->toBeFalse()
        ->and( Gate::forUser( $owner )->allows( 'view', $theirs ) )->toBeFalse()
        ->and( Gate::forUser( $owner )->allows( 'update', $theirs ) )->toBeFalse()
        ->and( Gate::forUser( $owner )->allows( 'view', $guest ) )->toBeFalse()
        ->and( Gate::forUser( null )->allows( 'view', $mine ) )->toBeFalse();
} );

it( 'needs a storefront-capable token for a shopper to reach their own customer record', function (): void {
    $mine = Customer::factory()->create( [ 'user_id' => 2 ] );

    expect( Gate::forUser( Sanctum::actingAs( ApiUser::make( 2 ), [ TokenAbilities::STOREFRONT ] ) )->allows( 'view', $mine ) )->toBeTrue()
        ->and( Gate::forUser( Sanctum::actingAs( ApiUser::make( 2 ), [ 'ecommerce:orders.read' ] ) )->allows( 'view', $mine ) )->toBeFalse();
} );

it( 'asks the authorizer for the subclass\'s resource from the base policy', function (): void {
    $policy = new class( app( EcommerceAuthorizer::class ) ) extends EcommercePolicy {
        public const RESOURCE = 'widget';

        public function inspect( GenericUser $user ): bool
        {
            return $this->decide( $user, 'inspect' );
        }
    };

    Gate::define( 'ecommerce.widget.inspect', fn ( $user ): bool => 5 === (int) $user->getAuthIdentifier() );

    expect( $policy->inspect( new GenericUser( [ 'id' => 5 ] ) ) )->toBeTrue()
        ->and( $policy->inspect( new GenericUser( [ 'id' => 6 ] ) ) )->toBeFalse();
} );
