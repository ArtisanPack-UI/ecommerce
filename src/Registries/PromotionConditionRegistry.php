<?php

/**
 * PromotionConditionRegistry.
 *
 * Runtime registry of {@see \ArtisanPackUI\Ecommerce\Contracts\PromotionCondition}
 * implementations. Promotion eligibility rules. Core registers min-subtotal, cart-contains-product, customer-in-group, day-of-week, customer-first-order. Engine spec §5 row 7.
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

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @extends AbstractContractRegistry<PromotionCondition>
 *
 * @method PromotionCondition get( string $key )
 */
class PromotionConditionRegistry extends AbstractContractRegistry
{
    /**
     * @since 1.0.0
     *
     * @return class-string<PromotionCondition>
     */
    protected function contract(): string
    {
        return PromotionCondition::class;
    }
}
