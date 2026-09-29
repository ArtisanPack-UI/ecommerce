<?php

/**
 * ReviewModerator contract test.
 *
 * Satellites that ship a {@see ReviewModerator} extend this suite and
 * implement {@see self::moderator()}. Engine spec §4.16.
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

use ArtisanPackUI\Ecommerce\Contracts\ReviewModerator;
use ArtisanPackUI\Ecommerce\Models\ProductReview;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

/**
 * Contract test for {@see ReviewModerator} implementations.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class ReviewModeratorContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The verdicts a moderator may return.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const VERDICTS = [ 'approve', 'reject', 'spam', 'pending' ];

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_key_is_a_non_empty_string(): void
    {
        $this->assertNotSame( '', trim( $this->moderator()->key() ) );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function test_moderate_returns_a_known_verdict_for_every_review_shape(): void
    {
        foreach ( $this->reviews() as $label => $review ) {
            $this->assertContains(
                $this->moderator()->moderate( $review ),
                self::VERDICTS,
                sprintf( 'Verdict for the "%s" review must be one of: %s.', $label, implode( ', ', self::VERDICTS ) ),
            );
        }
    }

    /**
     * The review service applies the verdict; the moderator only decides.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_moderate_does_not_change_the_review(): void
    {
        $review = ProductReview::factory()->create();

        $this->moderator()->moderate( $review );

        $this->assertSame( ProductReview::STATUS_PENDING, $review->fresh()->status );
        $this->assertFalse( $review->isDirty(), 'moderate() must not modify the review it is given.' );
    }

    /**
     * Review shapes the moderator is exercised with. Override to add
     * fixtures your moderator treats specially.
     *
     * @since 1.0.0
     *
     * @return array<string, ProductReview>
     */
    protected function reviews(): array
    {
        return [
            'typical'           => ProductReview::factory()->create(),
            'empty body'        => ProductReview::factory()->create( [ 'title' => null, 'body' => null ] ),
            'verified purchase' => ProductReview::factory()->create( [ 'is_verified_purchase' => true, 'rating' => 1 ] ),
            'long body'         => ProductReview::factory()->create( [ 'body' => str_repeat( 'Great product. ', 500 ) ] ),
        ];
    }

    /**
     * Provides the concrete {@see ReviewModerator} under test.
     *
     * @since 1.0.0
     *
     * @return ReviewModerator
     */
    abstract protected function moderator(): ReviewModerator;

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
