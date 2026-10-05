<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\SatelliteUninstaller;
use ArtisanPackUI\Ecommerce\Models\Satellite;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\Ecommerce\Satellites\SatelliteDescriptor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses( RefreshDatabase::class );

final class RecordingSatelliteUninstaller implements SatelliteUninstaller
{
    /** @var array<int, array{string, bool}> */
    public static array $calls = [];

    public function uninstall( SatelliteDescriptor $satellite, bool $purge ): void
    {
        self::$calls[] = [ $satellite->packageName, $purge ];
    }
}

beforeEach( function (): void {
    RecordingSatelliteUninstaller::$calls = [];

    $this->migrations = sys_get_temp_dir() . '/satellite-migrations-' . uniqid();
    File::ensureDirectoryExists( $this->migrations );
    File::put( $this->migrations . '/2026_06_01_000001_create_acme_subscriptions_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create( 'acme_subscriptions', function ( Blueprint $table ): void {
            $table->id();
            $table->string( 'plan' );
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'acme_subscriptions' );
    }
};
PHP );

    $this->artisan( 'migrate', [ '--path' => $this->migrations, '--realpath' => true ] )->assertExitCode( 0 );

    $this->registerSubscriptions = function (): bool {
        return app( SatelliteRegistry::class )->register( [
            'package_name'    => 'acme/ecommerce-subscriptions',
            'version'         => '1.0.0',
            'migration_paths' => [ $this->migrations ],
            'tables'          => [ 'acme_subscriptions' ],
            'columns'         => [ 'ecommerce_carts' => [ 'currency' ] ],
            'uninstaller'     => RecordingSatelliteUninstaller::class,
        ] );
    };
} );

afterEach( function (): void {
    File::deleteDirectory( $this->migrations );
} );

it( 'uninstalls a satellite and preserves its data by default', function (): void {
    ( $this->registerSubscriptions )();
    DB::table( 'acme_subscriptions' )->insert( [ 'plan' => 'gold' ] );

    $this->artisan( 'ecommerce:satellite:uninstall', [ 'package' => 'acme/ecommerce-subscriptions', '--force' => true ] )
        ->expectsOutputToContain( 'Its data is preserved' )
        ->assertExitCode( 0 );

    expect( Satellite::query()->sole()->isUninstalled() )->toBeTrue()
        ->and( Schema::hasTable( 'acme_subscriptions' ) )->toBeTrue()
        ->and( DB::table( 'acme_subscriptions' )->count() )->toBe( 1 )
        ->and( RecordingSatelliteUninstaller::$calls )->toBe( [ [ 'acme/ecommerce-subscriptions', false ] ] )
        ->and( app( SatelliteRegistry::class )->isActive( 'acme/ecommerce-subscriptions' ) )->toBeFalse();
} );

it( 'drops the satellite tables with --purge', function (): void {
    ( $this->registerSubscriptions )();

    $this->artisan( 'ecommerce:satellite:uninstall', [ 'package' => 'acme/ecommerce-subscriptions', '--purge' => true, '--force' => true ] )
        ->expectsOutputToContain( 'Dropped table acme_subscriptions' )
        ->assertExitCode( 0 );

    expect( Schema::hasTable( 'acme_subscriptions' ) )->toBeFalse()
        ->and( RecordingSatelliteUninstaller::$calls )->toBe( [ [ 'acme/ecommerce-subscriptions', true ] ] )
        ->and( Satellite::query()->sole()->isUninstalled() )->toBeTrue();
} );

it( 'purges a satellite that no longer boots from its stored migration paths', function (): void {
    ( $this->registerSubscriptions )();
    app( SatelliteRegistry::class )->sync();
    app()->forgetInstance( SatelliteRegistry::class );

    $this->artisan( 'ecommerce:satellite:uninstall', [ 'package' => 'acme/ecommerce-subscriptions', '--purge' => true, '--force' => true ] )
        ->assertExitCode( 0 );

    expect( Schema::hasTable( 'acme_subscriptions' ) )->toBeFalse();
} );

it( 'asks for confirmation and can be cancelled', function (): void {
    ( $this->registerSubscriptions )();

    $this->artisan( 'ecommerce:satellite:uninstall', [ 'package' => 'acme/ecommerce-subscriptions' ] )
        ->expectsConfirmation( 'Uninstall "acme/ecommerce-subscriptions"? Its data will be preserved.', 'no' )
        ->assertExitCode( 1 );

    expect( Satellite::query()->sole()->isUninstalled() )->toBeFalse();
} );

it( 'fails for an unknown satellite', function (): void {
    $this->artisan( 'ecommerce:satellite:uninstall', [ 'package' => 'acme/nope', '--force' => true ] )
        ->expectsOutputToContain( 'Unknown satellite' )
        ->assertExitCode( 1 );
} );

it( 'reinstalls an uninstalled satellite without data loss', function (): void {
    ( $this->registerSubscriptions )();
    DB::table( 'acme_subscriptions' )->insert( [ 'plan' => 'gold' ] );

    $this->artisan( 'ecommerce:satellite:uninstall', [ 'package' => 'acme/ecommerce-subscriptions', '--force' => true ] )->assertExitCode( 0 );
    $this->artisan( 'ecommerce:satellite:reinstall', [ 'package' => 'acme/ecommerce-subscriptions' ] )
        ->expectsOutputToContain( 'reinstalled' )
        ->assertExitCode( 0 );

    expect( Satellite::query()->sole()->isUninstalled() )->toBeFalse()
        ->and( app( SatelliteRegistry::class )->isActive( 'acme/ecommerce-subscriptions' ) )->toBeTrue()
        ->and( DB::table( 'acme_subscriptions' )->count() )->toBe( 1 );

    $this->artisan( 'ecommerce:satellite:reinstall', [ 'package' => 'acme/nope' ] )->assertExitCode( 1 );
} );

it( 'audits orphaned tables and columns from removed satellites', function (): void {
    Satellite::factory()->uninstalled()->create( [
        'package_name'  => 'acme/ecommerce-subscriptions',
        'owned_tables'  => [ 'acme_subscriptions', 'acme_gone' ],
        'owned_columns' => [ 'ecommerce_carts' => [ 'currency', 'no_such_column' ] ],
    ] );
    Satellite::factory()->create( [
        'package_name' => 'acme/ecommerce-removed',
        'owned_tables' => [ 'ecommerce_orders' ],
    ] );
    app( SatelliteRegistry::class )->register( [
        'package_name' => 'acme/ecommerce-active',
        'version'      => '1.0.0',
        'tables'       => [ 'ecommerce_orders' ],
    ] );

    expect( Artisan::call( 'ecommerce:satellite:audit', [ '--json' => true ] ) )->toBe( 0 );

    $report = json_decode( Artisan::output(), true );

    expect( $report['orphans'] )->toBe( [
        [ 'package' => 'acme/ecommerce-subscriptions', 'reason' => 'uninstalled', 'kind' => 'table', 'table' => 'acme_subscriptions', 'column' => null ],
        [ 'package' => 'acme/ecommerce-subscriptions', 'reason' => 'uninstalled', 'kind' => 'column', 'table' => 'ecommerce_carts', 'column' => 'currency' ],
    ] )
        ->and( collect( $report['satellites'] )->pluck( 'status', 'package' )->all() )->toBe( [
            'acme/ecommerce-active'        => 'active',
            'acme/ecommerce-removed'       => 'not-registered',
            'acme/ecommerce-subscriptions' => 'uninstalled',
        ] );

    $this->artisan( 'ecommerce:satellite:audit', [ '--fail-on-orphans' => true ] )
        ->expectsOutputToContain( '2 orphaned tables or columns found.' )
        ->assertExitCode( 1 );
} );

it( 'reports a clean audit', function (): void {
    $this->artisan( 'ecommerce:satellite:audit', [ '--fail-on-orphans' => true ] )
        ->expectsOutputToContain( 'No orphaned satellite tables or columns found.' )
        ->assertExitCode( 0 );
} );

it( 'refuses to uninstall non-interactively without --force', function (): void {
    ( $this->registerSubscriptions )();

    $this->artisan( 'ecommerce:satellite:uninstall', [ 'package' => 'acme/ecommerce-subscriptions', '--purge' => true, '--no-interaction' => true ] )
        ->expectsOutputToContain( 'Re-run with --force' )
        ->assertExitCode( 1 );

    expect( Schema::hasTable( 'acme_subscriptions' ) )->toBeTrue()
        ->and( Satellite::query()->sole()->isUninstalled() )->toBeFalse()
        ->and( RecordingSatelliteUninstaller::$calls )->toBe( [] );
} );

it( 'refuses to uninstall in production without --force', function (): void {
    ( $this->registerSubscriptions )();
    app()->detectEnvironment( fn (): string => 'production' );

    $this->artisan( 'ecommerce:satellite:uninstall', [ 'package' => 'acme/ecommerce-subscriptions' ] )
        ->expectsOutputToContain( 'Re-run with --force' )
        ->assertExitCode( 1 );

    expect( Satellite::query()->sole()->isUninstalled() )->toBeFalse();
} );

it( 'refuses to purge a satellite whose migration path is the application\'s migrations directory', function (): void {
    app( SatelliteRegistry::class )->register( [
        'package_name'    => 'acme/ecommerce-greedy',
        'version'         => '1.0.0',
        'migration_paths' => [ 'database/migrations' ],
        'uninstaller'     => RecordingSatelliteUninstaller::class,
    ] );
    File::ensureDirectoryExists( database_path( 'migrations' ) );

    $this->artisan( 'ecommerce:satellite:uninstall', [ 'package' => 'acme/ecommerce-greedy', '--purge' => true, '--force' => true ] )
        ->expectsOutputToContain( 'Refusing to roll back' )
        ->assertExitCode( 1 );

    expect( Schema::hasTable( 'acme_subscriptions' ) )->toBeTrue()
        ->and( Satellite::query()->where( 'package_name', 'acme/ecommerce-greedy' )->sole()->isUninstalled() )->toBeFalse()
        ->and( RecordingSatelliteUninstaller::$calls )->toBe( [] );
} );

it( 'refuses to purge a path that contains the application migrations', function (): void {
    app( SatelliteRegistry::class )->register( [
        'package_name'    => 'acme/ecommerce-greedy',
        'version'         => '1.0.0',
        'migration_paths' => [ base_path() ],
    ] );

    $this->artisan( 'ecommerce:satellite:uninstall', [ 'package' => 'acme/ecommerce-greedy', '--purge' => true, '--force' => true ] )
        ->expectsOutputToContain( 'Refusing to roll back' )
        ->assertExitCode( 1 );
} );
