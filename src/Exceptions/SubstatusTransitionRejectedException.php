<?php

/**
 * SubstatusTransitionRejectedException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Services\OrderStatusMachine::setSubstatus()}
 * when an `ap.ecommerce.order.canTransitionSubstatus` filter vetoes a
 * sub-status change. Extends {@see EcommerceException} (and so
 * `RuntimeException`), which callers caught before this type existed.
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
class SubstatusTransitionRejectedException extends EcommerceException
{
}
