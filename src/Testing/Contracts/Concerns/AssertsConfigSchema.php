<?php

/**
 * AssertsConfigSchema.
 *
 * Shared check for the configurable contract suites (engine issue #149):
 * an entry that implements {@see DescribesConfig} must declare a
 * well-formed schema, and the suite's own "good" config fixture must
 * satisfy it — otherwise the admin form would refuse a config the entry
 * itself accepts. Entries without a schema pass.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing\Contracts\Concerns;

use ArtisanPackUI\Ecommerce\Contracts\DescribesConfig;
use ArtisanPackUI\Ecommerce\Support\ConfigSchema;
use Illuminate\Validation\ValidationException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
trait AssertsConfigSchema
{
    /**
     * @since 1.0.0
     *
     * @param  object                     $entry    Registry entry under test.
     * @param  array<string, mixed>|null  $fixture  A config the entry accepts, when the suite has one.
     *
     * @return void
     */
    protected function assertDeclaredConfigSchemaIsWellFormed( object $entry, ?array $fixture = null ): void
    {
        if ( ! $entry instanceof DescribesConfig ) {
            $this->addToAssertionCount( 1 );

            return;
        }

        $problems = ConfigSchema::problems( $entry->configSchema() );

        $this->assertSame( [], $problems, 'The declared config schema is malformed: ' . implode( ' ', $problems ) );

        if ( null === $fixture ) {
            return;
        }

        try {
            ConfigSchema::validate( $entry, $fixture );
        } catch ( ValidationException $exception ) {
            $this->fail( 'The suite\'s config fixture breaks the declared schema: ' . json_encode( $exception->errors() ) );
        }

        $this->addToAssertionCount( 1 );
    }
}
