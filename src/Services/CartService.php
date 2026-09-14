<?php

/**
 * CartService.
 *
 * Owns the write path against the `carts` and `cart_items` tables:
 * create a cart, add/update/remove lines, and rotate the session token on
 * login (engine spec §3.13, §3.14, §6.1).
 *
 * Line dedupe keys off `(product_id, product_variant_id, options_hash)` so
 * that adding the same variant with the same options increments the
 * existing line's quantity instead of creating a duplicate.
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

use ArtisanPackUI\Ecommerce\Events\CartCreated;
use ArtisanPackUI\Ecommerce\Events\CartUpdated;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CartService
{
    /**
     * @since 1.0.0
     *
     * @param  ConfigRepository  $config  Injected to resolve `artisanpack.ecommerce.base_currency` for new carts.
     */
    public function __construct(
        protected ConfigRepository $config,
    ) {
    }

    /**
     * Creates a new DB-backed cart with a freshly-minted opaque token.
     *
     * Fires `ap.ecommerce.cart.creating` (filter) before persist so listeners
     * can seed attributes (e.g. campaign meta) and `ap.ecommerce.cart.created`
     * (action) after. Also dispatches the {@see CartCreated} event.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  Column overrides (currency, customer_id, email, meta, …).
     *
     * @return Cart
     */
    public function create( array $attributes = [] ): Cart
    {
        $currency = (string) ( $attributes['currency'] ?? $this->config->get( 'artisanpack.ecommerce.base_currency', 'USD' ) );

        $attributes = array_replace(
            [
                'token'             => $this->generateToken(),
                'currency'          => $currency,
                'subtotal_currency' => $currency,
                'discount_currency' => $currency,
                'tax_currency'      => $currency,
                'shipping_currency' => $currency,
                'total_currency'    => $currency,
                'meta'              => [],
            ],
            $attributes,
        );

        $attributes = (array) applyFilters( 'ap.ecommerce.cart.creating', $attributes );

        $cart = new Cart( $attributes );
        $cart->save();

        doAction( 'ap.ecommerce.cart.created', $cart );
        Event::dispatch( new CartCreated( $cart ) );

        return $cart;
    }

    /**
     * Retrieves a cart by its opaque session token.
     *
     * @since 1.0.0
     *
     * @param  string  $token
     *
     * @return Cart|null
     */
    public function findByToken( string $token ): ?Cart
    {
        return Cart::query()->where( 'token', $token )->first();
    }

    /**
     * Adds a line to the cart or increments the quantity of the matching
     * existing line.
     *
     * Two lines match when they share `product_id`, `product_variant_id`,
     * and the canonicalized `options_hash`. When they match, the quantities
     * sum; the unit price is not re-priced from `$line`.
     *
     * Fires `ap.ecommerce.cart.itemAdding` (filter) before persist so
     * listeners can veto (`null`) or modify the line, and
     * `ap.ecommerce.cart.itemAdded` (action) after. Also dispatches
     * {@see CartUpdated}.
     *
     * Required `$line` keys: `product_id`, `quantity`, `unit_price_amount`,
     * `unit_price_currency`. Optional: `product_variant_id`, `options`,
     * `meta`.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart
     * @param  array<string, mixed>  $line
     *
     * @throws InvalidArgumentException When required keys are missing or currencies disagree with the cart.
     *
     * @return CartItem|null The persisted (or updated) line, or null if a filter vetoed the add.
     */
    public function addItem( Cart $cart, array $line ): ?CartItem
    {
        $filtered = applyFilters( 'ap.ecommerce.cart.itemAdding', $line, $cart );
        if ( null === $filtered ) {
            return null;
        }
        $line = (array) $filtered;

        $this->validateLine( $line );

        if ( $line['unit_price_currency'] !== $cart->currency ) {
            throw new InvalidArgumentException(
                sprintf(
                    'Cart currency is %s; line priced in %s cannot be added without an explicit merge decision.',
                    $cart->currency,
                    $line['unit_price_currency'],
                ),
            );
        }

        $options     = (array) ( $line['options'] ?? [] );
        $optionsHash = CartItem::hashOptions( $options );
        $variantId   = $line['product_variant_id'] ?? null;
        $quantity    = (int) $line['quantity'];

        return DB::transaction( function () use ( $cart, $line, $options, $optionsHash, $variantId, $quantity ): CartItem {
            $existing = CartItem::query()
                ->where( 'cart_id', $cart->id )
                ->where( 'product_id', $line['product_id'] )
                ->where( 'product_variant_id', $variantId )
                ->where( 'options_hash', $optionsHash )
                ->lockForUpdate()
                ->first();

            if ( null !== $existing ) {
                $existing->quantity += $quantity;
                $existing->line_subtotal_amount = $existing->unit_price_amount * $existing->quantity;
                $existing->line_total_amount    = $existing->line_subtotal_amount;
                $existing->save();

                doAction( 'ap.ecommerce.cart.itemUpdated', $existing, $cart );
                Event::dispatch( new CartUpdated( $cart->fresh() ?? $cart, [ 'item_id' => $existing->id, 'action' => 'quantity_summed' ] ) );

                return $existing;
            }

            $unit  = (int) $line['unit_price_amount'];
            $total = $unit * $quantity;

            $item = new CartItem( [
                'cart_id'                => $cart->id,
                'product_id'             => (int) $line['product_id'],
                'product_variant_id'     => $variantId,
                'quantity'               => $quantity,
                'unit_price_amount'      => $unit,
                'unit_price_currency'    => (string) $line['unit_price_currency'],
                'line_subtotal_amount'   => $total,
                'line_subtotal_currency' => (string) $line['unit_price_currency'],
                'line_total_amount'      => $total,
                'line_total_currency'    => (string) $line['unit_price_currency'],
                'options'                => $options,
                'meta'                   => (array) ( $line['meta'] ?? [] ),
                'options_hash'           => $optionsHash,
            ] );

            $item->save();

            doAction( 'ap.ecommerce.cart.itemAdded', $item, $cart );
            Event::dispatch( new CartUpdated( $cart->fresh() ?? $cart, [ 'item_id' => $item->id, 'action' => 'added' ] ) );

            return $item;
        } );
    }

    /**
     * Sets an existing line's quantity to `$quantity`. Removes the line when
     * `$quantity <= 0`.
     *
     * @since 1.0.0
     *
     * @param  Cart      $cart
     * @param  CartItem  $item
     * @param  int       $quantity
     *
     * @return CartItem|null The refreshed line, or null if it was removed.
     */
    public function updateItemQuantity( Cart $cart, CartItem $item, int $quantity ): ?CartItem
    {
        if ( $quantity <= 0 ) {
            $this->removeItem( $cart, $item );

            return null;
        }

        return DB::transaction( function () use ( $cart, $item, $quantity ): CartItem {
            $locked = CartItem::query()->lockForUpdate()->findOrFail( $item->id );

            $locked->quantity             = $quantity;
            $locked->line_subtotal_amount = $locked->unit_price_amount * $quantity;
            $locked->line_total_amount    = $locked->line_subtotal_amount;
            $locked->save();

            doAction( 'ap.ecommerce.cart.itemUpdated', $locked, $cart );
            Event::dispatch( new CartUpdated( $cart->fresh() ?? $cart, [ 'item_id' => $locked->id, 'action' => 'quantity_set' ] ) );

            return $locked;
        } );
    }

    /**
     * Removes a line from the cart.
     *
     * @since 1.0.0
     *
     * @param  Cart      $cart
     * @param  CartItem  $item
     *
     * @return void
     */
    public function removeItem( Cart $cart, CartItem $item ): void
    {
        DB::transaction( function () use ( $cart, $item ): void {
            $item->delete();

            doAction( 'ap.ecommerce.cart.itemRemoved', $item, $cart );
            Event::dispatch( new CartUpdated( $cart->fresh() ?? $cart, [ 'item_id' => $item->id, 'action' => 'removed' ] ) );
        } );
    }

    /**
     * Rotates the cart's opaque session token.
     *
     * Called after a guest→user merge (or on any successful login) to
     * prevent cross-session cart hijacking where an attacker who observed
     * the pre-login token could resume the authenticated cart from a
     * different browser.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart
     *
     * @return Cart
     */
    public function rotateToken( Cart $cart ): Cart
    {
        return DB::transaction( function () use ( $cart ): Cart {
            $locked = Cart::query()->lockForUpdate()->findOrFail( $cart->id );

            $previous       = $locked->token;
            $locked->token  = $this->generateToken();
            $locked->save();

            doAction( 'ap.ecommerce.cart.tokenRotated', $locked, $previous );
            Event::dispatch( new CartUpdated( $locked, [ 'action' => 'token_rotated' ] ) );

            return $locked;
        } );
    }

    /**
     * Generates a fresh 40-char opaque cart token.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function generateToken(): string
    {
        return Str::random( 40 );
    }

    /**
     * Asserts that `$line` carries the required keys.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $line
     *
     * @throws InvalidArgumentException
     *
     * @return void
     */
    protected function validateLine( array $line ): void
    {
        $required = [ 'product_id', 'quantity', 'unit_price_amount', 'unit_price_currency' ];

        foreach ( $required as $key ) {
            if ( ! array_key_exists( $key, $line ) ) {
                throw new InvalidArgumentException( sprintf( 'Cart line missing required key "%s".', $key ) );
            }
        }

        if ( (int) $line['quantity'] <= 0 ) {
            throw new InvalidArgumentException( 'Cart line quantity must be a positive integer.' );
        }
    }
}
