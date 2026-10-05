<?php

declare( strict_types=1 );

namespace Tests;

use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use ArtisanPackUI\Ecommerce\Webhooks\WebhookUrlGuard;
use Laravel\Sanctum\SanctumServiceProvider;
use Laravel\Scout\ScoutServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Rebing\GraphQL\GraphQLServiceProvider;

/**
 * Base Test Case
 *
 * Provides base functionality for all package tests.
 *
 * @since   1.0.0
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Webhook hosts in tests (hooks.example.test, …) don't resolve;
        // treat them as public unless a test says otherwise.
        WebhookUrlGuard::resolveUsing( static fn (): array => [ '93.184.216.34' ] );
    }

    /**
     * Tear down the test environment.
     */
    protected function tearDown(): void
    {
        WebhookUrlGuard::resolveUsing( null );

        parent::tearDown();
    }

    /**
     * Gets package providers.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     *
     * @return array<int, class-string> Array of service provider class names.
     */
    protected function getPackageProviders( $app ): array
    {
        // rebing/graphql-laravel is optional; the CI "optional dependencies"
        // job removes it and runs the `optional-dependencies` group.
        return array_values( array_filter( [
            SanctumServiceProvider::class,
            ScoutServiceProvider::class,
            class_exists( GraphQLServiceProvider::class ) ? GraphQLServiceProvider::class : null,
            EcommerceServiceProvider::class,
        ] ) );
    }

    /**
     * Defines environment setup.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     */
    protected function defineEnvironment( $app ): void
    {
        // Setup app key for encryption
        $app['config']->set( 'app.key', 'base64:' . base64_encode( random_bytes( 32 ) ) );

        // Setup default database to use sqlite :memory:
        $app['config']->set( 'database.default', 'testbench' );
        $app['config']->set( 'database.connections.testbench', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ] );
    }
}
