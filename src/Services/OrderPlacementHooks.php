<?php

/**
 * OrderPlacementHooks.
 *
 * The engine ships no checkout: whatever turns a cart into an order (a
 * storefront satellite, or the host application's own checkout) persists
 * the order itself. This class gives that code one place to fire the two
 * order-placement hooks the engine's listeners rely on, with the same
 * payloads and return-value checks everywhere:
 *
 * - {@see self::placing()} — `ap.ecommerce.order.placing` (filter), run on
 *   the order attributes before the order row is written;
 * - {@see self::placed()} — `ap.ecommerce.order.placed` (action), run once
 *   the order and its lines are persisted. The engine listens to it for
 *   the confirmation notification, kanban routing, and so on.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use UnexpectedValueException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderPlacementHooks
{
    /**
     * Runs the attributes of an order about to be created from `$cart`
     * through `ap.ecommerce.order.placing` (filter). Call it before
     * persisting the order and use the returned attributes.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  Order attributes about to be persisted.
     * @param  Cart                  $cart        Cart the order is placed from.
     *
     * @throws UnexpectedValueException When a listener returns something other than an array.
     *
     * @return array<string, mixed>
     */
    public function placing( array $attributes, Cart $cart ): array
    {
        $filtered = applyFilters( 'ap.ecommerce.order.placing', $attributes, $cart );

        if ( ! is_array( $filtered ) ) {
            throw new UnexpectedValueException( sprintf(
                'ap.ecommerce.order.placing must return an array, %s returned.',
                get_debug_type( $filtered ),
            ) );
        }

        return $filtered;
    }

    /**
     * Fires `ap.ecommerce.order.placed` (action). Call it once the order
     * and its lines are persisted (after the transaction commits, so
     * listeners see the committed rows).
     *
     * @since 1.0.0
     *
     * @param  Order  $order  The placed order.
     *
     * @return void
     */
    public function placed( Order $order ): void
    {
        doAction( 'ap.ecommerce.order.placed', $order );
    }
}
