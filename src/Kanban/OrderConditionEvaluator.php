<?php

/**
 * OrderConditionEvaluator.
 *
 * Evaluates a condition tree against a placed {@see Order}. Used for kanban
 * board `routing_rules` and automation `conditions` (parent plan §9.2 /
 * §9.4). Leaves reuse the promotion condition registry, so every promotion
 * condition — core or satellite — is also a routing rule. Tree shape:
 *
 * ```json
 * { "all": [ node, … ] }                      every child passes (empty → pass)
 * { "any": [ node, … ] }                      at least one child passes (empty → fail)
 * { "not": node }                             child fails
 * { "type": "min-subtotal", "config": { … } } registered condition
 * [ node, … ]                                 shorthand for "all"
 * ```
 *
 * An empty tree (`{}` / `[]`) passes. Leaf conditions that implement
 * {@see OrderAwarePromotionCondition} judge the order directly; the rest
 * are evaluated against an unsaved cart rebuilt from the order's lines.
 * Like the promotion engine, unknown types, malformed nodes, trees nested
 * past {@see self::MAX_DEPTH}, and conditions that throw all fail closed.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Kanban;

use ArtisanPackUI\Ecommerce\Contracts\OrderAwarePromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderConditionEvaluator
{
    /**
     * Deepest nesting accepted before a tree fails closed.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_DEPTH = 10;

    /**
     * Cart views of orders, keyed by order id, so a tree with several
     * leaves rebuilds the cart once.
     *
     * @since 1.0.0
     *
     * @var array<int, Cart>
     */
    private array $carts = [];

    /**
     * @since 1.0.0
     *
     * @param  PromotionConditionRegistry  $conditions  Registered conditions.
     */
    public function __construct( private readonly PromotionConditionRegistry $conditions )
    {
    }

    /**
     * Whether `$order` satisfies `$tree`.
     *
     * @since 1.0.0
     *
     * @param  array<int|string, mixed>  $tree   Condition tree.
     * @param  Order                     $order  Order being evaluated.
     *
     * @return bool
     */
    public function passes( array $tree, Order $order ): bool
    {
        try {
            return $this->node( $tree, $order, 0 );
        } finally {
            unset( $this->carts[ (int) $order->id ] );
        }
    }

    /**
     * Whether `$tree` is well-formed and every leaf names a registered
     * condition. Used to validate admin input before it is stored.
     *
     * @since 1.0.0
     *
     * @param  mixed  $tree   Candidate tree.
     * @param  int    $depth  Current depth.
     *
     * @return string|null Error message, or `null` when valid.
     */
    public function validate( mixed $tree, int $depth = 0 ): ?string
    {
        if ( ! is_array( $tree ) ) {
            return __( 'Each condition node must be an object.' );
        }

        if ( $depth > self::MAX_DEPTH ) {
            return __( 'Conditions may be nested at most :depth levels deep.', [ 'depth' => self::MAX_DEPTH ] );
        }

        if ( [] === $tree ) {
            return null;
        }

        if ( array_is_list( $tree ) ) {
            return $this->validateChildren( $tree, $depth );
        }

        if ( array_key_exists( 'all', $tree ) || array_key_exists( 'any', $tree ) ) {
            $children = $tree['all'] ?? $tree['any'];

            return is_array( $children ) && array_is_list( $children )
                ? $this->validateChildren( $children, $depth )
                : __( '"all" and "any" must hold a list of conditions.' );
        }

        if ( array_key_exists( 'not', $tree ) ) {
            return $this->validate( $tree['not'], $depth + 1 );
        }

        $type = $tree['type'] ?? null;

        if ( ! is_string( $type ) || ! $this->conditions->has( $type ) ) {
            return __( 'Unknown condition type ":type".', [ 'type' => is_string( $type ) ? $type : '' ] );
        }

        if ( array_key_exists( 'config', $tree ) && ! is_array( $tree['config'] ) ) {
            return __( 'Condition config must be an object.' );
        }

        return null;
    }

    /**
     * Evaluates one node.
     *
     * @since 1.0.0
     *
     * @param  mixed  $node   Node.
     * @param  Order  $order  Order.
     * @param  int    $depth  Current depth.
     *
     * @return bool
     */
    protected function node( mixed $node, Order $order, int $depth ): bool
    {
        if ( ! is_array( $node ) || $depth > self::MAX_DEPTH ) {
            return false;
        }

        if ( [] === $node ) {
            return true;
        }

        if ( array_is_list( $node ) ) {
            $node = [ 'all' => $node ];
        }

        if ( array_key_exists( 'all', $node ) ) {
            if ( ! is_array( $node['all'] ) ) {
                return false;
            }

            foreach ( $node['all'] as $child ) {
                if ( ! $this->node( $child, $order, $depth + 1 ) ) {
                    return false;
                }
            }

            return true;
        }

        if ( array_key_exists( 'any', $node ) ) {
            if ( ! is_array( $node['any'] ) ) {
                return false;
            }

            foreach ( $node['any'] as $child ) {
                if ( $this->node( $child, $order, $depth + 1 ) ) {
                    return true;
                }
            }

            return false;
        }

        if ( array_key_exists( 'not', $node ) ) {
            return is_array( $node['not'] ) && [] !== $node['not'] && ! $this->node( $node['not'], $order, $depth + 1 );
        }

        return $this->leaf( $node, $order );
    }

    /**
     * Evaluates a `{ type, config }` leaf through the condition registry.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $node   Leaf.
     * @param  Order                 $order  Order.
     *
     * @return bool
     */
    protected function leaf( array $node, Order $order ): bool
    {
        $type   = $node['type'] ?? null;
        $config = $node['config'] ?? [];

        if ( ! is_string( $type ) || ! is_array( $config ) || ! $this->conditions->has( $type ) ) {
            Log::channel( 'ecommerce' )->warning( 'Kanban condition type is not registered or malformed; failing closed.', [
                'order_id' => $order->id,
                'type'     => is_string( $type ) ? $type : null,
            ] );

            return false;
        }

        try {
            $condition = $this->conditions->get( $type );

            return $condition instanceof OrderAwarePromotionCondition
                ? $condition->evaluateOrder( $order, $config )
                : $condition->evaluate( $this->cartFor( $order ), $config );
        } catch ( Throwable $e ) {
            Log::channel( 'ecommerce' )->error( 'Kanban condition threw; failing closed.', [
                'order_id' => $order->id,
                'type'     => $type,
                'error'    => $e->getMessage(),
            ] );

            return false;
        }
    }

    /**
     * An unsaved cart mirroring `$order`, with `items.product` and
     * `customer` set so cart-shaped conditions never touch the database for
     * the cart itself.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return Cart
     */
    protected function cartFor( Order $order ): Cart
    {
        if ( isset( $this->carts[ (int) $order->id ] ) ) {
            return $this->carts[ (int) $order->id ];
        }

        $currency = (string) $order->currency;
        $lines    = $order->relationLoaded( 'items' ) ? $order->items->loadMissing( 'product' ) : $order->items()->with( 'product' )->get();

        $items = $lines->map( static function ( OrderItem $line ) use ( $currency ): CartItem {
            $subtotal = (int) $line->unit_price_amount * (int) $line->quantity;
            $item     = new CartItem( [
                'product_id'             => $line->product_id,
                'product_variant_id'     => $line->product_variant_id,
                'quantity'               => (int) $line->quantity,
                'unit_price_amount'      => (int) $line->unit_price_amount,
                'unit_price_currency'    => $currency,
                'line_subtotal_amount'   => $subtotal,
                'line_subtotal_currency' => $currency,
                'line_total_amount'      => $subtotal - (int) $line->discount_amount,
                'line_total_currency'    => $currency,
                'options'                => (array) ( $line->product_snapshot['options'] ?? [] ),
                'meta'                   => (array) ( $line->meta ?? [] ),
            ] );

            return $item->setRelation( 'product', $line->product );
        } );

        $cart = new Cart( [
            'customer_id'       => $order->customer_id,
            'email'             => $order->email,
            'currency'          => $currency,
            'subtotal_amount'   => (int) $order->subtotal_amount,
            'subtotal_currency' => $currency,
            'discount_amount'   => (int) $order->discount_amount,
            'discount_currency' => $currency,
            'tax_amount'        => (int) $order->tax_amount,
            'tax_currency'      => $currency,
            'shipping_amount'   => (int) $order->shipping_amount,
            'shipping_currency' => $currency,
            'total_amount'      => (int) $order->total_amount,
            'total_currency'    => $currency,
            'meta'              => (array) ( $order->meta ?? [] ),
        ] );

        $cart->setRelation( 'items', new Collection( $items->all() ) );
        $cart->setRelation( 'customer', null === $order->customer_id ? null : $order->customer );

        return $this->carts[ (int) $order->id ] = $cart;
    }

    /**
     * Validates each child of a list node.
     *
     * @since 1.0.0
     *
     * @param  array<int, mixed>  $children  Children.
     * @param  int                $depth     Parent depth.
     *
     * @return string|null
     */
    private function validateChildren( array $children, int $depth ): ?string
    {
        foreach ( $children as $child ) {
            $error = $this->validate( $child, $depth + 1 );

            if ( null !== $error ) {
                return $error;
            }
        }

        return null;
    }
}
