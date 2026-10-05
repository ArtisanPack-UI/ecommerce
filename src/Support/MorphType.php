<?php

/**
 * MorphType.
 *
 * The engine's morph map and helpers for reading stored morph types. The
 * provider registers {@see self::MAP} with merge semantics (it never calls
 * `enforceMorphMap()` for the host), so polymorphic columns store stable
 * aliases such as `ecommerce.product` instead of class names, and keep
 * working in hosts that enforce a morph map of their own.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Support;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Coupon;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class MorphType
{
    /**
     * Alias => model for every engine model stored in a polymorphic column:
     * stockables and priceables, reservables, activity-log subjects, and
     * notifiables.
     *
     * @since 1.0.0
     *
     * @var array<string, class-string<Model>>
     */
    public const MAP = [
        'ecommerce.product'   => Product::class,
        'ecommerce.variant'   => ProductVariant::class,
        'ecommerce.price'     => ProductPrice::class,
        'ecommerce.customer'  => Customer::class,
        'ecommerce.promotion' => Promotion::class,
        'ecommerce.coupon'    => Coupon::class,
        'ecommerce.cart'      => Cart::class,
        'ecommerce.order'     => Order::class,
    ];

    /**
     * Adds {@see self::MAP} to Eloquent's morph map, keeping any aliases the
     * host registered.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function register(): void
    {
        Relation::morphMap( self::MAP );
    }

    /**
     * The model class a stored morph type points at, or the type itself when
     * it isn't an alias.
     *
     * @since 1.0.0
     *
     * @param  string|null  $type  Stored morph type.
     *
     * @return string|null
     */
    public static function classOf( ?string $type ): ?string
    {
        if ( null === $type || '' === $type ) {
            return null;
        }

        return Relation::getMorphedModel( $type ) ?? $type;
    }

    /**
     * Whether a stored morph type points at `$class`, as an alias or as a
     * class name.
     *
     * @since 1.0.0
     *
     * @param  string|null          $type   Stored morph type.
     * @param  class-string<Model>  $class  Model class.
     *
     * @return bool
     */
    public static function is( ?string $type, string $class ): bool
    {
        return null !== $type && ( $class === self::classOf( $type ) || ( new $class() )->getMorphClass() === $type );
    }

    /**
     * The short class name a stored morph type points at (`ProductVariant`),
     * for API output that shouldn't expose class names or aliases.
     *
     * @since 1.0.0
     *
     * @param  string|null  $type  Stored morph type.
     *
     * @return string|null
     */
    public static function basename( ?string $type ): ?string
    {
        $class = self::classOf( $type );

        return null === $class ? null : class_basename( $class );
    }
}
