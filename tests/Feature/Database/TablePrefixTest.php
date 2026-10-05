<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Ecommerce;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses( RefreshDatabase::class );

afterEach( function (): void {
    Ecommerce::$runsMigrations = true;
} );

it( 'prefixes every engine table with ecommerce_', function (): void {
    expect( ( new Order() )->getTable() )->toBe( 'ecommerce_orders' );

    $tables = array_map( static fn ( array $table ): string => $table['name'], Schema::getTables() );
    $engine = array_values( array_filter( $tables, static fn ( string $table ): bool => ! in_array( $table, [ 'migrations', 'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'personal_access_tokens', 'sqlite_sequence' ], true ) ) );

    expect( $engine )->not->toBeEmpty()
        ->and( array_filter( $engine, static fn ( string $table ): bool => ! str_starts_with( $table, 'ecommerce_' ) ) )->toBe( [] );
} );

it( 'points every model and pivot at an ecommerce_ table that exists', function (): void {
    foreach ( glob( dirname( __DIR__, 3 ) . '/src/Models/*.php' ) as $file ) {
        $class = 'ArtisanPackUI\\Ecommerce\\Models\\' . basename( $file, '.php' );

        if ( ! is_subclass_of( $class, Model::class ) || ( new ReflectionClass( $class ) )->isAbstract() ) {
            continue;
        }

        $model = new $class();

        expect( $model->getTable() )->toStartWith( 'ecommerce_' )
            ->and( Schema::hasTable( $model->getTable() ) )->toBeTrue( "{$class} uses missing table {$model->getTable()}." );
    }

    foreach ( [ [ ArtisanPackUI\Ecommerce\Models\Product::class, 'categories' ], [ ArtisanPackUI\Ecommerce\Models\Product::class, 'tags' ] ] as [ $class, $relation ] ) {
        $pivot = ( new $class() )->{$relation}();

        expect( $pivot )->toBeInstanceOf( BelongsToMany::class )
            ->and( $pivot->getTable() )->toStartWith( 'ecommerce_' )
            ->and( Schema::hasTable( $pivot->getTable() ) )->toBeTrue();
    }
} );

it( 'stops loading its migrations after ignoreMigrations()', function (): void {
    $path  = realpath( dirname( __DIR__, 3 ) . '/database/migrations' );
    $paths = fn (): array => array_map( 'realpath', app( 'migrator' )->paths() );

    expect( $paths() )->toContain( $path );

    $result = Ecommerce::ignoreMigrations();

    expect( $result )->toBeInstanceOf( Ecommerce::class )
        ->and( Ecommerce::shouldRunMigrations() )->toBeFalse();

    // Registering again (as a fresh boot would) no longer adds the path.
    $migrator = app( 'migrator' );
    ( fn () => $this->paths = [] )->call( $migrator );
    ( fn () => $this->registerMigrations() )->call( new EcommerceServiceProvider( app() ) );

    expect( $paths() )->not->toContain( $path );
} );

it( 'reports the installed version or dev', function (): void {
    expect( Ecommerce::version() )->toBeString()->not->toBeEmpty()
        ->and( ecommerce()::version() )->toBe( Ecommerce::version() );
} );
