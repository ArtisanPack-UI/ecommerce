<?php

/**
 * CartItem model.
 *
 * A single line on a {@see Cart}. The paired `options_hash` column is the
 * canonicalized SHA-256 of the JSON options payload and is what the cart
 * merge and dedupe indexes use to detect "same line" between carts and
 * within a single cart.
 *
 * Engine spec §3.14.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Models;

use ArtisanPackUI\Ecommerce\Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CartItem Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                       $id
 * @property int                       $cart_id
 * @property int                       $product_id
 * @property int|null                  $product_variant_id
 * @property int                       $quantity
 * @property int                       $unit_price_amount
 * @property string                    $unit_price_currency
 * @property int                       $line_subtotal_amount
 * @property string                    $line_subtotal_currency
 * @property int                       $line_total_amount
 * @property string                    $line_total_currency
 * @property array<string, mixed>      $options
 * @property array<string, mixed>      $meta
 * @property string                    $options_hash
 * @property Cart                      $cart
 * @property Product                   $product
 * @property ProductVariant|null       $variant
 */
class CartItem extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'cart_items';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'cart_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'unit_price_amount',
        'unit_price_currency',
        'line_subtotal_amount',
        'line_subtotal_currency',
        'line_total_amount',
        'line_total_currency',
        'options',
        'meta',
        'options_hash',
    ];

    /**
     * The cart this line belongs to.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo( Cart::class );
    }

    /**
     * The product being purchased.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo( Product::class );
    }

    /**
     * The optional variant chosen on the product.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo( ProductVariant::class, 'product_variant_id' );
    }

    /**
     * Canonicalizes a cart-line options array and returns its SHA-256 hash.
     *
     * Associative keys are recursively sorted before encoding so semantically
     * equivalent option payloads (e.g. `{a:1,b:2}` vs `{b:2,a:1}`) collapse
     * to the same hash — which is what the dedupe index and cart-merge
     * logic rely on to detect a "same line".
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $options
     *
     * @return string
     */
    public static function hashOptions( array $options ): string
    {
        $canonical = self::canonicalize( $options );

        return hash( 'sha256', (string) json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cart_id'              => 'integer',
            'product_id'           => 'integer',
            'product_variant_id'   => 'integer',
            'quantity'             => 'integer',
            'unit_price_amount'    => 'integer',
            'line_subtotal_amount' => 'integer',
            'line_total_amount'    => 'integer',
            'options'              => 'array',
            'meta'                 => 'array',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return CartItemFactory
     */
    protected static function newFactory(): CartItemFactory
    {
        return CartItemFactory::new();
    }

    /**
     * Recursively sorts associative array keys so equivalent payloads canonicalize equally.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value
     *
     * @return mixed
     */
    private static function canonicalize( mixed $value ): mixed
    {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        if ( ! array_is_list( $value ) ) {
            ksort( $value );
        }

        foreach ( $value as $key => $inner ) {
            $value[ $key ] = self::canonicalize( $inner );
        }

        return $value;
    }
}
