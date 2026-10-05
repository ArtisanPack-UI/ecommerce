<?php

/**
 * SatelliteManifest.
 *
 * What the verifier needs to know about the satellite under test, read
 * from its `composer.json`: package name, version, the PSR-4 namespaces
 * its production code lives in, and the directories its tests live in.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing\Verification;

use InvalidArgumentException;

/**
 * Satellite metadata read from `composer.json`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class SatelliteManifest
{
    /**
     * @since 1.0.0
     *
     * @param  string             $path        Absolute path to the satellite root.
     * @param  string             $package     Composer package name.
     * @param  string             $version     Version being verified.
     * @param  array<int, string> $namespaces  Production PSR-4 prefixes (each ending in `\`).
     * @param  array<int, string> $testPaths   Absolute directories scanned for contract tests.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $package,
        public readonly string $version,
        public readonly array $namespaces,
        public readonly array $testPaths,
    ) {
    }

    /**
     * Reads the manifest for the satellite rooted at `$path`.
     *
     * @since 1.0.0
     *
     * @param  string              $path             Satellite root directory.
     * @param  string|null         $versionOverride  Version to report instead of composer.json's (e.g. the git tag).
     * @param  array<int, string>  $extraNamespaces  Additional namespace prefixes to attribute to the satellite.
     * @param  array<int, string>  $extraTestPaths   Additional test directories (relative to `$path` or absolute).
     *
     * @throws InvalidArgumentException When `composer.json` is missing or unreadable.
     *
     * @return self
     */
    public static function fromPath(
        string $path,
        ?string $versionOverride = null,
        array $extraNamespaces = [],
        array $extraTestPaths = [],
    ): self {
        $root     = rtrim( $path, DIRECTORY_SEPARATOR );
        $composer = $root . DIRECTORY_SEPARATOR . 'composer.json';

        if ( ! is_file( $composer ) ) {
            throw new InvalidArgumentException( __( 'No composer.json found at :path.', [ 'path' => $root ] ) );
        }

        $data = json_decode( (string) file_get_contents( $composer ), true );

        if ( ! is_array( $data ) ) {
            throw new InvalidArgumentException( __( 'composer.json at :path is not valid JSON.', [ 'path' => $root ] ) );
        }

        $namespaces = array_keys( (array) ( $data['autoload']['psr-4'] ?? [] ) );

        foreach ( $extraNamespaces as $namespace ) {
            $namespaces[] = $namespace;
        }

        $namespaces = array_values( array_unique( array_filter( array_map(
            static fn ( $namespace ): string => '' === trim( (string) $namespace, '\\' ) ? '' : trim( (string) $namespace, '\\' ) . '\\',
            $namespaces,
        ) ) ) );

        $testPaths = [];

        foreach ( (array) ( $data['autoload-dev']['psr-4'] ?? [] ) as $dirs ) {
            foreach ( (array) $dirs as $dir ) {
                $testPaths[] = (string) $dir;
            }
        }

        if ( [] === $testPaths ) {
            $testPaths[] = 'tests';
        }

        foreach ( $extraTestPaths as $dir ) {
            $testPaths[] = $dir;
        }

        $testPaths = array_values( array_unique( array_filter( array_map(
            static function ( string $dir ) use ( $root ): ?string {
                $absolute = str_starts_with( $dir, DIRECTORY_SEPARATOR ) ? $dir : $root . DIRECTORY_SEPARATOR . $dir;
                $real     = realpath( $absolute );

                return false === $real || ! is_dir( $real ) ? null : $real;
            },
            $testPaths,
        ) ) ) );

        $version = null !== $versionOverride && '' !== $versionOverride
            ? $versionOverride
            : (string) ( $data['version'] ?? 'dev' );

        return new self(
            $root,
            (string) ( $data['name'] ?? basename( $root ) ),
            $version,
            $namespaces,
            $testPaths,
        );
    }

    /**
     * Whether `$class` lives inside one of the satellite's namespaces.
     *
     * @since 1.0.0
     *
     * @param  string  $class  Class FQCN.
     *
     * @return bool
     */
    public function owns( string $class ): bool
    {
        $class = ltrim( $class, '\\' );

        foreach ( $this->namespaces as $namespace ) {
            if ( str_starts_with( $class, $namespace ) ) {
                return true;
            }
        }

        return false;
    }
}
