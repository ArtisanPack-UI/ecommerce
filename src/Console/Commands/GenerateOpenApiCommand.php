<?php

/**
 * GenerateOpenApiCommand.
 *
 * `php artisan ecommerce:generate-openapi` — writes the OpenAPI 3.1 spec for
 * the REST API (parent plan §12.1) built by {@see OpenApiGenerator}. The
 * release workflow runs it (via `vendor/bin/testbench`) with
 * `--spec-version` set to the tag, and attaches the file to each GitHub
 * release.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Console\Commands;

use ArtisanPackUI\Ecommerce\OpenApi\OpenApiGenerator;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class GenerateOpenApiCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ecommerce:generate-openapi
        {--output= : File to write (defaults to storage/app/ecommerce-openapi.json)}
        {--stdout : Print the spec instead of writing a file}
        {--spec-version= : The spec\'s info.version, e.g. the release tag (1.2.3 or v1.2.3). Defaults to the installed engine version}';

    /**
     * @var string
     */
    protected $description = 'Generate the OpenAPI 3.1 spec for the ecommerce REST API.';

    /**
     * @since 1.0.0
     * @since 1.0.2 Takes `--spec-version`.
     *
     * @param  OpenApiGenerator  $generator  Spec builder.
     * @param  Filesystem        $files      Filesystem.
     *
     * @return int
     */
    public function handle( OpenApiGenerator $generator, Filesystem $files ): int
    {
        $version = $this->option( 'spec-version' );

        try {
            $spec = $generator->generate( is_string( $version ) && '' !== $version ? $version : null );
        } catch ( InvalidArgumentException $exception ) {
            $this->error( $exception->getMessage() );

            return self::FAILURE;
        }

        $json = (string) json_encode( $spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";

        if ( (bool) $this->option( 'stdout' ) ) {
            $this->output->write( $json );

            return self::SUCCESS;
        }

        $path = (string) ( $this->option( 'output' ) ?: storage_path( 'app/ecommerce-openapi.json' ) );

        $files->ensureDirectoryExists( dirname( $path ) );
        $files->put( $path, $json );

        $this->info( sprintf(
            'Wrote OpenAPI %s spec for %d path(s) to %s.',
            $spec['openapi'],
            count( $spec['paths'] ),
            $path,
        ) );

        return self::SUCCESS;
    }
}
