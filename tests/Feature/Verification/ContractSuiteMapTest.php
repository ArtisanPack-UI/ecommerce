<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Testing\Verification\ContractSuiteMap;
use PHPUnit\Framework\TestCase;

/*
 * The satellite contract test kit ships in the Composer dist (audit A1).
 * Every class the verifier can reach must exist and be a runnable suite.
 */

it( 'maps every registry to an existing contract', function ( string $registry, string $contract ): void {
    expect( class_exists( $registry ) )->toBeTrue( "{$registry} does not exist." )
        ->and( interface_exists( $contract ) )->toBeTrue( "{$contract} is not an interface." );
} )->with( fn (): array => array_map( null, array_keys( ContractSuiteMap::REGISTRIES ), array_values( ContractSuiteMap::REGISTRIES ) ) );

it( 'maps every contract to an abstract contract test suite in src/Testing/Contracts', function ( string $contract, string $suite ): void {
    $reflection = new ReflectionClass( $suite );

    expect( interface_exists( $contract ) )->toBeTrue()
        ->and( $reflection->isAbstract() )->toBeTrue()
        ->and( $reflection->isSubclassOf( TestCase::class ) )->toBeTrue()
        ->and( $reflection->getFileName() )->toContain( 'src/Testing/Contracts/' )
        ->and( ContractSuiteMap::suiteFor( $contract ) )->toBe( $suite );
} )->with( fn (): array => array_map( null, array_keys( ContractSuiteMap::SUITES ), array_values( ContractSuiteMap::SUITES ) ) );

it( 'ships every contract test suite it maps', function (): void {
    $shipped = array_map(
        static fn ( string $path ): string => basename( $path, '.php' ),
        glob( dirname( __DIR__, 3 ) . '/src/Testing/Contracts/*ContractTest.php' ) ?: [],
    );

    $mapped = array_map( static fn ( string $suite ): string => ContractSuiteMap::shortName( $suite ), array_values( ContractSuiteMap::SUITES ) );

    expect( array_diff( $mapped, $shipped ) )->toBe( [] );
} );
