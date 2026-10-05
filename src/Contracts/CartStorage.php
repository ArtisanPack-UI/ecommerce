<?php

/**
 * CartStorage contract.
 *
 * Persistence seam for {@see \ArtisanPackUI\Ecommerce\Models\Cart} carts.
 * The engine writes and reads carts exclusively through this contract so
 * high-traffic stores can swap the default database-backed implementation
 * for a Redis-backed one (or anything else) without touching cart services.
 *
 * Engine spec §4.8.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Contracts;

use ArtisanPackUI\Ecommerce\Models\Cart;

/**
 * CartStorage contract.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
interface CartStorage
{
    /**
     * Looks up a cart by its opaque guest token.
     *
     * Implementations MUST return the persisted {@see Cart} with its
     * items relation eager-loadable, or `null` when no cart matches.
     *
     * @since 1.0.0
     *
     * @param  string  $token  Opaque session-scoped cart token.
     *
     * @return Cart|null
     */
    public function find( string $token ): ?Cart;

    /**
     * Looks up the active cart for an authenticated customer.
     *
     * @since 1.0.0
     *
     * @param  int  $customerId  Customer id.
     *
     * @return Cart|null
     */
    public function findForCustomer( int $customerId ): ?Cart;

    /**
     * Persists the given cart, including any dirty items.
     *
     * Implementations MUST round-trip the cart's `token`, `customer_id`,
     * `currency`, `meta`, and item collection so a subsequent
     * {@see self::find()} / {@see self::findForCustomer()} returns
     * equivalent state.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart to persist.
     *
     * @return void
     */
    public function persist( Cart $cart ): void;

    /**
     * Deletes the given cart and all of its items.
     *
     * After this call a subsequent lookup by the cart's token MUST
     * return `null`.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart to remove.
     *
     * @return void
     */
    public function delete( Cart $cart ): void;
}
