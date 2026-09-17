<?php

declare( strict_types=1 );

use Illuminate\Support\Facades\File;

beforeEach( function (): void {
    $this->tmp = sys_get_temp_dir() . '/pci-lint-' . uniqid();
    File::ensureDirectoryExists( $this->tmp );
} );

afterEach( function (): void {
    if ( is_dir( $this->tmp ) ) {
        File::deleteDirectory( $this->tmp );
    }
} );

it( 'passes when no forbidden substrings are present', function (): void {
    File::put( $this->tmp . '/2026_01_01_000001_create_orders_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::create( 'orders', function ( Blueprint $table ) {
            $table->id();
            $table->string( 'reference' );
            $table->timestamps();
        } );
    }
};
PHP );

    $this->artisan( 'ecommerce:lint:pci-columns', [ '--path' => [ $this->tmp ] ] )
        ->expectsOutputToContain( 'PCI column lint passed.' )
        ->assertExitCode( 0 );
} );

it( 'fails on a card_number column', function (): void {
    File::put( $this->tmp . '/2026_01_01_000002_bad_migration.php', <<<'PHP'
<?php

return new class extends Migration {
    public function up(): void
    {
        Schema::create( 'payments', function ( Blueprint $table ) {
            $table->string( 'card_number' );
        } );
    }
};
PHP );

    $this->artisan( 'ecommerce:lint:pci-columns', [ '--path' => [ $this->tmp ] ] )
        ->assertExitCode( 1 );
} );

it( 'matches the required forbidden substrings case-insensitively', function ( string $needle ): void {
    File::put( $this->tmp . '/2026_01_01_000003_migration.php', "<?php\n\$table->string( '{$needle}' );\n" );

    $this->artisan( 'ecommerce:lint:pci-columns', [ '--path' => [ $this->tmp ] ] )
        ->assertExitCode( 1 );
} )->with( [
    'card_number' => 'card_number',
    'cvv'         => 'CVV',
    'card_cvc'    => 'Card_CVC',
    'pan'         => 'PAN',
    'card_expiry' => 'card_expiry',
] );

it( 'allows a violation with an inline pci-lint:ignore annotation', function (): void {
    File::put( $this->tmp . '/2026_01_01_000004_migration.php', <<<'PHP'
<?php

return new class extends Migration {
    public function up(): void
    {
        Schema::create( 'legacy_import', function ( Blueprint $table ) {
            $table->string( 'card_number' ); // pci-lint:ignore reason: read-only import mirror; drop after backfill
        } );
    }
};
PHP );

    $this->artisan( 'ecommerce:lint:pci-columns', [ '--path' => [ $this->tmp ] ] )
        ->expectsOutputToContain( 'pci-lint:ignore' )
        ->expectsOutputToContain( 'PCI column lint passed.' )
        ->assertExitCode( 0 );
} );

it( 'rejects an ignore annotation without a reason', function (): void {
    File::put( $this->tmp . '/2026_01_01_000005_migration.php', "<?php\n\$table->string( 'card_number' ); // pci-lint:ignore reason:  \n" );

    $this->artisan( 'ecommerce:lint:pci-columns', [ '--path' => [ $this->tmp ] ] )
        ->assertExitCode( 1 );
} );

it( 'does not accept an ignore annotation embedded in a string literal', function (): void {
    File::put(
        $this->tmp . '/2026_01_01_000007_migration.php',
        "<?php\n\$table->string( 'card_number pci-lint:ignore reason: bypass attempt' );\n",
    );

    $this->artisan( 'ecommerce:lint:pci-columns', [ '--path' => [ $this->tmp ] ] )
        ->assertExitCode( 1 );
} );

it( 'requires the ignore annotation to live in a line comment', function (): void {
    File::put(
        $this->tmp . '/2026_01_01_000008_migration.php',
        "<?php\n\$table->string( 'cvv' ); // pci-lint:ignore reason: line comment allowed\n",
    );

    $this->artisan( 'ecommerce:lint:pci-columns', [ '--path' => [ $this->tmp ] ] )
        ->assertExitCode( 0 );
} );

it( 'catches double-quoted escape sequences that decode to a forbidden name', function (): void {
    File::put(
        $this->tmp . '/2026_01_01_000009_migration.php',
        "<?php\n\$table->string( \"card_\\x6eumber\" );\n",
    );

    $this->artisan( 'ecommerce:lint:pci-columns', [ '--path' => [ $this->tmp ] ] )
        ->assertExitCode( 1 );
} );

it( 'rejects an ignore annotation inside a block comment', function (): void {
    File::put(
        $this->tmp . '/2026_01_01_000010_migration.php',
        "<?php\n\$table->string( 'card_number' ); /* pci-lint:ignore reason: block comment not accepted */\n",
    );

    $this->artisan( 'ecommerce:lint:pci-columns', [ '--path' => [ $this->tmp ] ] )
        ->assertExitCode( 1 );
} );

it( 'scans the engine bundled migrations by default', function (): void {
    $this->artisan( 'ecommerce:lint:pci-columns' )
        ->expectsOutputToContain( 'PCI column lint passed.' )
        ->assertExitCode( 0 );
} );

it( 'scans recursively into nested subdirectories', function (): void {
    File::ensureDirectoryExists( $this->tmp . '/nested/deep' );
    File::put(
        $this->tmp . '/nested/deep/2026_01_01_000006_migration.php',
        "<?php\n\$table->string( 'cvv' );\n",  // already a string literal — matches
    );

    $this->artisan( 'ecommerce:lint:pci-columns', [ '--path' => [ $this->tmp ] ] )
        ->assertExitCode( 1 );
} );
