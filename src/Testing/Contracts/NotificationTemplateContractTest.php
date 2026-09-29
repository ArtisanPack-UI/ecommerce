<?php

/**
 * NotificationTemplate contract test.
 *
 * Satellites that register a {@see NotificationTemplate} extend this suite
 * and implement {@see self::template()}. The suite proves the definition is
 * well-formed and that its default copy compiles in the notification
 * sandbox, references only declared variables, and renders its own preview
 * data. Engine spec §4.14.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Testing\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\NotificationTemplate;
use ArtisanPackUI\Ecommerce\Exceptions\NotificationTemplateException;
use ArtisanPackUI\Ecommerce\Notifications\NotificationTemplateRenderer;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Contract test for {@see NotificationTemplate} implementations.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class NotificationTemplateContractTest extends TestCase
{
    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_identity_is_well_formed(): void
    {
        $template = $this->template();

        $this->assertMatchesRegularExpression( '/^[a-z0-9:-]+(\.[a-z0-9-]+)+$/', $template->key(), 'Keys are lowercase, dot-separated segments (e.g. "order.shipped.customer").' );
        $this->assertNotSame( '', trim( $template->label() ) );
        $this->assertNotSame( '', trim( $template->channel() ) );
        $this->assertNotSame( '', trim( $template->category() ) );
        $this->assertNotSame( '', trim( $template->defaultBody() ) );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_variables_are_dotted_paths_covered_by_the_preview_data(): void
    {
        $template = $this->template();

        $this->assertNotEmpty( $template->variables() );

        foreach ( $template->variables() as $variable ) {
            $this->assertMatchesRegularExpression( '/^[A-Z][A-Za-z]*(\.(\*|[a-z_][a-z0-9_]*))*$/', $variable, sprintf( '"%s" must be a dotted path such as Order.items.*.name.', $variable ) );
            $this->assertArrayHasKey( strtok( $variable, '.' ), $template->previewData(), sprintf( 'previewData() must include the "%s" root.', strtok( $variable, '.' ) ) );
        }
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_default_copy_compiles_in_the_sandbox_and_renders_its_preview(): void
    {
        $template = $this->template();
        $renderer = $this->app->make( NotificationTemplateRenderer::class );

        try {
            $renderer->validate(
                [ 'subject' => $template->defaultSubject(), 'body' => $template->defaultBody() ],
                $template->variables(),
                $template->previewData(),
                'mail' === $template->channel(),
            );
        } catch ( NotificationTemplateException $exception ) {
            $this->fail( 'Default copy is invalid: ' . json_encode( $exception->errors ) );
        }

        $rendered = $renderer->renderTemplate( $template->key(), $template->defaultSubject(), $template->defaultBody(), $template->previewData(), $template->channel() );

        $this->assertNotSame( '', trim( $rendered['body'] ) );
        $this->assertStringNotContainsString( '{{', $rendered['body'] );
    }

    /**
     * Provides the concrete {@see NotificationTemplate} under test.
     *
     * @since 1.0.0
     *
     * @return NotificationTemplate
     */
    abstract protected function template(): NotificationTemplate;

    /**
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  Test application.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders( $app ): array
    {
        return [ EcommerceServiceProvider::class ];
    }

    /**
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  Test application.
     */
    protected function defineEnvironment( $app ): void
    {
        $app['config']->set( 'app.key', 'base64:' . base64_encode( random_bytes( 32 ) ) );
        $app['config']->set( 'database.default', 'testbench' );
        $app['config']->set( 'database.connections.testbench', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ] );
    }
}
