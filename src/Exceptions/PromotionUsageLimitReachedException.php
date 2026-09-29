<?php

/**
 * PromotionUsageLimitReachedException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Services\PromotionEngine::recordUsage()}
 * when a promotion hit its `usage_limit_total` between evaluation and order
 * placement (a concurrent checkout claimed the last use). The placement
 * transaction should roll back and re-evaluate the cart.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Exceptions;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PromotionUsageLimitReachedException extends EcommerceException
{
}
