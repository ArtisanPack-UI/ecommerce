<?php

/**
 * PromotionActionRegistry.
 *
 * Runtime registry of {@see \ArtisanPackUI\Ecommerce\Contracts\PromotionAction}
 * implementations. Promotion discount effects. Core registers percent-off-cart, fixed-off-cart, percent-off-product, free-shipping, buy-x-get-y, add-free-item, tiered-discount. Engine spec §5 row 8.
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

use ArtisanPackUI\Ecommerce\Contracts\PromotionAction;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @extends AbstractContractRegistry<PromotionAction>
 *
 * @method PromotionAction get( string $key )
 */
class PromotionActionRegistry extends AbstractContractRegistry
{
    /**
     * @since 1.0.0
     *
     * @return class-string<PromotionAction>
     */
    protected function contract(): string
    {
        return PromotionAction::class;
    }
}
