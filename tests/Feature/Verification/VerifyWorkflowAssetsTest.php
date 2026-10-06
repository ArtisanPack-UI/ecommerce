<?php

declare( strict_types=1 );

/*
 * GitHub renames a release asset whose name starts with a dot to
 * `default.<name>`, so the signed report must reach a satellite's release
 * under a name without one (#186). These read the shipped workflow files.
 */

/**
 * A file in the package root.
 */
function verifyWorkflowFile( string $path ): string
{
    return (string) file_get_contents( dirname( __DIR__, 3 ) . '/' . $path );
}

/**
 * The lines of a `files:` / `path:` block scalar that follows `$key`.
 *
 * @return array<int, string>
 */
function verifyWorkflowBlock( string $yaml, string $key ): array
{
    preg_match_all( '/^(\s*)' . preg_quote( $key, '/' ) . ':\s*\|\s*\n((?:\1\s+\S.*\n?)+)/m', $yaml, $matches );

    return array_values( array_filter( array_map( 'trim', explode( "\n", implode( "\n", $matches[2] ) ) ) ) );
}

it( 'puts the report in the signed artifact under a release-safe name, and keeps the dotfile', function (): void {
    $workflow = verifyWorkflowFile( '.github/workflows/verify-satellite.yml' );

    expect( $workflow )->toContain( 'cp report/.ecommerce-verify-report.json report/ecommerce-verify-report.json' )
        ->and( verifyWorkflowBlock( $workflow, 'path' ) )->toContain(
            'report/ecommerce-verify-report.json',
            'report/.ecommerce-verify-report.json',
            'report/verify-report.sig',
        );
} );

it( 'attaches no release asset whose name starts with a dot in the stub', function (): void {
    $files = verifyWorkflowBlock( verifyWorkflowFile( 'stubs/workflows/verify-satellite.yml' ), 'files' );

    expect( $files )->toBe( [ 'verification/ecommerce-verify-report.json', 'verification/verify-report.sig' ] );

    foreach ( $files as $file ) {
        expect( basename( $file ) )->not->toStartWith( '.' );
    }
} );

it( 'pins the stub to a released engine tag, never a branch', function (): void {
    preg_match( '/verify-satellite\.yml@(\S+)/', verifyWorkflowFile( 'stubs/workflows/verify-satellite.yml' ), $match );

    expect( $match[1] ?? '' )->toMatch( '/^v\d+\.\d+\.\d+$/' );
} );
