<?php

/**
 * NotificationTemplateRegistry.
 *
 * The notification catalog (parent plan §14.4): every
 * {@see NotificationTemplate} definition the store can send, keyed by
 * template key. The engine registers its catalog from
 * {@see \ArtisanPackUI\Ecommerce\Notifications\NotificationCatalog};
 * satellites add their own from their service-provider `boot()`.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Registries;

use ArtisanPackUI\Ecommerce\Contracts\NotificationTemplate;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @extends AbstractContractRegistry<NotificationTemplate>
 *
 * @method NotificationTemplate get( string $key )
 */
class NotificationTemplateRegistry extends AbstractContractRegistry
{
    /**
     * @since 1.0.0
     *
     * @return class-string<NotificationTemplate>
     */
    protected function contract(): string
    {
        return NotificationTemplate::class;
    }
}
