<?php

declare( strict_types=1 );

use Illuminate\Support\Facades\DB;

/*
 * MySQL rejects table, index, and constraint names longer than 64 characters
 * (Postgres truncates past 63). The suite runs on SQLite, which has no limit,
 * so a long name Laravel generates — `{table}_{columns}_foreign` — only fails
 * on a host's first `migrate`. This compiles every engine migration to MySQL
 * SQL without a server (in pretend mode) and checks each name it creates.
 */
it( 'creates no table, index, or constraint name longer than MySQL allows', function (): void {
    config()->set( 'database.connections.mysql_pretend', [
        'driver'    => 'mysql',
        'host'      => '127.0.0.1',
        'database'  => 'pretend',
        'username'  => 'pretend',
        'password'  => '',
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix'    => '',
    ] );

    $default = config( 'database.default' );
    config()->set( 'database.default', 'mysql_pretend' );

    // Nothing runs in pretend mode, but seeding inserts still ask PDO to
    // quote values; any PDO will do, so no MySQL server is needed.
    $connection = DB::connection( 'mysql_pretend' )->setPdo( new PDO( 'sqlite::memory:' ) );

    try {
        $queries = $connection->pretend( static function (): void {
            foreach ( glob( dirname( __DIR__, 3 ) . '/database/migrations/*.php' ) as $file ) {
                ( require $file )->up();
            }
        } );
    } finally {
        config()->set( 'database.default', $default );
        DB::purge( 'mysql_pretend' );
    }

    $sql = implode( "\n", array_column( $queries, 'query' ) );

    preg_match_all( '/(?:create table|constraint|index|unique|key)\s+`([^`]+)`/i', $sql, $matches );

    $names = array_values( array_unique( $matches[1] ) );
    $long  = array_values( array_filter( $names, static fn ( string $name ): bool => strlen( $name ) > 64 ) );

    expect( $names )->not->toBeEmpty()
        ->and( $sql )->toContain( 'ecommerce_product_variant_option_values' )
        ->and( $long )->toBe( [] );
} );
