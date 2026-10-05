<?php

/**
 * OrderNotEditableException.
 *
 * Thrown when an attempt is made to apply an edit to an order in a state that
 * does not accept that class of change (e.g. line-item changes on a fully
 * fulfilled order without the `orders.edit-fulfilled` override). The service
 * itself does not enforce role-based overrides — callers are expected to gate
 * that upstream — but it does enforce the domain-level rule that certain
 * fields become read-only past certain lifecycle points. Plan §7.6.
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
class OrderNotEditableException extends EcommerceException
{
}
