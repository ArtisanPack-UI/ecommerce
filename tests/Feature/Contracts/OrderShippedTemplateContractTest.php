<?php

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\NotificationTemplate;
use ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog;
use ArtisanPackUI\Ecommerce\Registries\NotificationTemplateRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\NotificationTemplateContractTest;

/**
 * Verifies a catalog definition satisfies the shared
 * {@see NotificationTemplateContractTest} suite (every catalog entry is
 * also exercised by NotificationTemplateRendererTest).
 *
 * @since 1.0.0
 */
final class OrderShippedTemplateContractTest extends NotificationTemplateContractTest
{
    protected function template(): NotificationTemplate
    {
        return $this->app->make( NotificationTemplateRegistry::class )->get( NotificationCatalog::ORDER_SHIPPED );
    }
}
