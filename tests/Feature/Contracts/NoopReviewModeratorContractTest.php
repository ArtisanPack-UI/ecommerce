<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\ReviewModerator;
use ArtisanPackUI\Ecommerce\Reviews\NoopReviewModerator;
use ArtisanPackUI\Ecommerce\Testing\Contracts\ReviewModeratorContractTest;

/**
 * Verifies the default {@see NoopReviewModerator} satisfies the shared
 * {@see ReviewModeratorContractTest} suite.
 *
 * @since 1.0.0
 */
final class NoopReviewModeratorContractTest extends ReviewModeratorContractTest
{
    public function test_it_leaves_every_review_for_a_human(): void
    {
        $this->assertInstanceOf( NoopReviewModerator::class, $this->moderator() );

        foreach ( $this->reviews() as $review ) {
            $this->assertSame( 'pending', $this->moderator()->moderate( $review ) );
        }
    }

    protected function moderator(): ReviewModerator
    {
        return $this->app->make( ReviewModerator::class );
    }
}
