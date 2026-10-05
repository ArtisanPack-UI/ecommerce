<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Auth\AbilityCatalog;
use ArtisanPackUI\Ecommerce\Auth\CmsFrameworkPermissions;
use ArtisanPackUI\Ecommerce\Auth\EcommerceAuthorizer;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Stands in for cms-framework's helpers, recording what would be written.
 */
function recordingCmsPermissions(): CmsFrameworkPermissions
{
    return new class extends CmsFrameworkPermissions {
        /** @var array<string, string> */
        public array $roles = [];

        /** @var array<string, string> */
        public array $permissions = [];

        /** @var array<int, array{0: string, 1: string}> */
        public array $grants = [];

        public function isAvailable(): bool
        {
            return true;
        }

        protected function registerRole( string $slug, string $name ): void
        {
            $this->roles[ $slug ] = $name;
        }

        protected function registerPermission( string $slug, string $name ): void
        {
            $this->permissions[ $slug ] = $name;
        }

        protected function addPermissionToRole( string $role, string $permission ): void
        {
            $this->grants[] = [ $role, $permission ];
        }
    };
}

it( 'lists every engine ability from spec §6.18', function (): void {
    $abilities = AbilityCatalog::abilities();

    expect( $abilities )->toContain( 'ecommerce.order.edit-fulfilled', 'ecommerce.inventory.adjust', 'ecommerce.report.view' )
        ->and( $abilities )->toHaveCount( array_sum( array_map( 'count', AbilityCatalog::CORE ) ) );
} );

it( 'lets satellites add abilities to the catalog', function (): void {
    addFilter( 'ap.ecommerce.abilities.catalog', fn ( array $catalog ): array => $catalog + [ 'subscription' => [ 'viewAny', 'cancel', '' ] ] );

    expect( AbilityCatalog::all()['subscription'] )->toBe( [ 'viewAny', 'cancel' ] )
        ->and( AbilityCatalog::abilities() )->toContain( 'ecommerce.subscription.cancel' );
} );

it( 'registers every ability as a permission held by the shop-manager role', function (): void {
    $bridge = recordingCmsPermissions();

    $count = $bridge->sync();

    expect( $count )->toBe( count( AbilityCatalog::abilities() ) )
        ->and( $bridge->roles )->toBe( [ 'shop-manager' => 'Shop manager' ] )
        ->and( array_keys( $bridge->permissions ) )->toBe( AbilityCatalog::abilities() )
        ->and( $bridge->permissions['ecommerce.order.edit-fulfilled'] )->toBe( 'Ecommerce: Order — Edit Fulfilled' )
        ->and( $bridge->grants )->toContain( [ 'shop-manager', 'ecommerce.inventory.adjust' ] )
        ->and( $bridge->grants )->toHaveCount( $count );
} );

it( 'defines the abilities as Gates that RBAC can grant and that fall back to the umbrella', function (): void {
    $authorizer = app( EcommerceAuthorizer::class );
    $manager    = new GenericUser( [ 'id' => 7 ] );
    $clerk      = new GenericUser( [ 'id' => 8 ] );

    // Without the Gates, a seeded permission alone can't grant anything.
    Gate::before( fn ( $user, string $ability ): ?bool => 'ecommerce.order.viewAny' === $ability ? 7 === (int) $user->getAuthIdentifier() : null );

    expect( $authorizer->allows( $manager, 'order', 'viewAny' ) )->toBeFalse();

    recordingCmsPermissions()->defineGates();

    expect( $authorizer->allows( $manager, 'order', 'viewAny' ) )->toBeTrue()
        ->and( $authorizer->allows( $clerk, 'order', 'viewAny' ) )->toBeFalse()
        ->and( $authorizer->allows( $manager, 'order', 'refund' ) )->toBeFalse();

    Gate::define( 'ecommerce.admin', fn ( $user ): bool => 8 === (int) $user->getAuthIdentifier() );

    expect( $authorizer->allows( $clerk, 'order', 'refund' ) )->toBeTrue();
} );

it( 'leaves a host-defined ability alone', function (): void {
    Gate::define( 'ecommerce.report.view', fn (): bool => true );

    $defined = recordingCmsPermissions()->defineGates();

    expect( $defined )->toBe( count( AbilityCatalog::abilities() ) - 1 )
        ->and( app( EcommerceAuthorizer::class )->allows( new GenericUser( [ 'id' => 1 ] ), 'report', 'view' ) )->toBeTrue();
} );

it( 'is unavailable without cms-framework or when switched off', function (): void {
    expect( app( CmsFrameworkPermissions::class )->isAvailable() )->toBeFalse();

    config()->set( 'artisanpack.ecommerce.cms_framework.enabled', false );

    expect( app( CmsFrameworkPermissions::class )->isAvailable() )->toBeFalse();
} );

it( 'syncs through the artisan command when cms-framework is present', function (): void {
    $this->artisan( 'ecommerce:sync-permissions' )
        ->expectsOutputToContain( 'cms-framework is not installed' )
        ->assertSuccessful();

    $bridge = recordingCmsPermissions();
    app()->instance( CmsFrameworkPermissions::class, $bridge );

    $this->artisan( 'ecommerce:sync-permissions' )
        ->expectsOutputToContain( sprintf( 'Synced %d ecommerce permission(s) and the shop-manager role.', count( AbilityCatalog::abilities() ) ) )
        ->assertSuccessful();

    expect( $bridge->permissions )->not->toBeEmpty();
} );

it( 'syncs after forward migrations only, never on a pretend run or a rollback', function (): void {
    $bridge = recordingCmsPermissions();

    app()->getProvider( EcommerceServiceProvider::class )->bootCmsFrameworkPermissions( $bridge );

    event( new MigrationsEnded( 'up', [ 'pretend' => true ] ) );
    event( new MigrationsEnded( 'down' ) );

    expect( $bridge->permissions )->toBe( [] );

    event( new MigrationsEnded( 'up' ) );

    expect( $bridge->permissions )->toHaveCount( count( AbilityCatalog::abilities() ) )
        ->and( Gate::has( 'ecommerce.order.viewAny' ) )->toBeTrue();
} );

it( 'logs instead of failing the migration when the sync throws', function (): void {
    $bridge = new class extends CmsFrameworkPermissions {
        public function isAvailable(): bool
        {
            return true;
        }

        public function sync(): int
        {
            throw new RuntimeException( 'no such table: roles' );
        }
    };

    Log::shouldReceive( 'channel' )->with( 'ecommerce' )->andReturnSelf();
    Log::shouldReceive( 'warning' )->once()->withArgs( fn ( string $message ): bool => str_contains( $message, 'Could not sync' ) );

    app()->getProvider( EcommerceServiceProvider::class )->bootCmsFrameworkPermissions( $bridge );

    event( new MigrationsEnded( 'up' ) );
} );
