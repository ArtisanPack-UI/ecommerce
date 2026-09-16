<?php

/**
 * DatabaseCartStorage.
 *
 * The reference {@see \ArtisanPackUI\Ecommerce\Contracts\CartStorage}
 * implementation — persists carts against the `carts` / `cart_items` tables
 * using the standard Eloquent models. Engine spec §4.8.
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

use ArtisanPackUI\Ecommerce\Contracts\CartStorage;
use ArtisanPackUI\Ecommerce\Models\Cart;
use Illuminate\Support\Facades\DB;

/**
 * Database-backed cart storage.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DatabaseCartStorage implements CartStorage
{
    /**
     * @since 1.0.0
     *
     * @param  string  $token  Opaque cart token.
     *
     * @return Cart|null
     */
    public function find( string $token ): ?Cart
    {
        return Cart::query()
            ->with( 'items' )
            ->where( 'token', $token )
            ->first();
    }

    /**
     * @since 1.0.0
     *
     * @param  int  $customerId  Customer id.
     *
     * @return Cart|null
     */
    public function findForCustomer( int $customerId ): ?Cart
    {
        return Cart::query()
            ->with( 'items' )
            ->where( 'customer_id', $customerId )
            ->whereNull( 'completed_order_id' )
            ->latest( 'id' )
            ->first();
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart to persist.
     *
     * @return void
     */
    public function persist( Cart $cart ): void
    {
        DB::transaction( function () use ( $cart ): void {
            $cart->save();

            if ( $cart->relationLoaded( 'items' ) ) {
                foreach ( $cart->items as $item ) {
                    $item->cart_id = $cart->id;
                    $item->save();
                }
            }
        } );
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart to remove.
     *
     * @return void
     */
    public function delete( Cart $cart ): void
    {
        DB::transaction( function () use ( $cart ): void {
            $cart->items()->delete();
            $cart->delete();
        } );
    }
}
