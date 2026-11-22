<?php

/**
 * LineOptionDescriber.
 *
 * The one place that turns a cart or order line's options into
 * human-readable `label` / `value` rows (#222):
 *
 * - {@see self::describe()} asks the product type when it implements
 *   {@see DescribesLineOptions}, otherwise builds the default rows: the
 *   variant name (with an empty label), then each scalar option with its
 *   key headlined ("Gift message: Happy birthday"). Internal keys
 *   (`variant_id`, `product_id`, keys starting with `_`), nested arrays
 *   (grouped `items`, bundle members), and empty values are skipped.
 * - {@see self::forCartItem()} describes a cart line.
 * - {@see self::forOrderItem()} reads the rows copied into the order
 *   line's `product_snapshot.option_lines` at placement, so historical
 *   orders never change when a product is edited; older lines without
 *   them fall back to describing the snapshot's options.
 *
 * Every result passes through `ap.ecommerce.lineOptions.described`.
 * Stateless; resolve it from the container.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Support;

use ArtisanPackUI\Ecommerce\Contracts\DescribesLineOptions;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.4.0
 */
class LineOptionDescriber
{
    /**
     * Option keys the default description never shows.
     *
     * @since 1.4.0
     *
     * @var array<int, string>
     */
    public const HIDDEN_KEYS = [ 'variant_id', 'product_variant_id', 'product_id' ];

    /**
     * Snapshot key the rows are copied into at placement.
     *
     * @since 1.4.0
     *
     * @var string
     */
    public const SNAPSHOT_KEY = 'option_lines';

    /**
     * The rows describing a line of `$product` with `$options`.
     *
     * @since 1.4.0
     *
     * @param  Product|null          $product  Product (null when it was deleted).
     * @param  array<string, mixed>  $options  Line options, with `variant_id` when the line has a variant.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function describe( ?Product $product, array $options ): array
    {
        return $this->resolve( $product, $options, null );
    }

    /**
     * The rows describing a cart line.
     *
     * @since 1.4.0
     *
     * @param  CartItem  $item  Cart line.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function forCartItem( CartItem $item ): array
    {
        $options = (array) ( $item->options ?? [] );

        if ( null !== $item->product_variant_id && ! isset( $options['variant_id'] ) ) {
            $options['variant_id'] = (int) $item->product_variant_id;
        }

        $variant     = null === $item->product_variant_id ? null : $this->related( $item, 'variant' );
        $variantName = null === $item->product_variant_id ? null : (string) ( $variant?->name ?? '' );

        return $this->resolve( $this->related( $item, 'product' ), $options, $variantName );
    }

    /**
     * The rows describing an order line: the ones recorded at placement
     * when present, otherwise a description of the snapshot's options.
     *
     * @since 1.4.0
     *
     * @param  OrderItem  $item  Order line.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function forOrderItem( OrderItem $item ): array
    {
        $snapshot = (array) ( $item->product_snapshot ?? [] );

        if ( is_array( $snapshot[ self::SNAPSHOT_KEY ] ?? null ) ) {
            return $this->normalize( $snapshot[ self::SNAPSHOT_KEY ] );
        }

        $options = (array) ( $snapshot['options'] ?? [] );

        if ( null !== $item->product_variant_id && ! isset( $options['variant_id'] ) ) {
            $options['variant_id'] = (int) $item->product_variant_id;
        }

        $variantName = is_string( $snapshot['variant_name'] ?? null ) ? $snapshot['variant_name'] : '';

        return $this->resolve( $this->related( $item, 'product' ), $options, $variantName );
    }

    /**
     * Cleans rows from any source: each becomes `{ label, value }` strings,
     * trimmed; rows that aren't arrays or have no value are dropped.
     *
     * @since 1.4.0
     *
     * @param  mixed  $rows  Candidate rows.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function normalize( mixed $rows ): array
    {
        $clean = [];

        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $label = is_scalar( $row['label'] ?? null ) ? trim( (string) $row['label'] ) : '';
            $value = is_scalar( $row['value'] ?? null ) ? trim( (string) $row['value'] ) : '';

            if ( '' === $value ) {
                continue;
            }

            $clean[] = [ 'label' => $label, 'value' => $value ];
        }

        return $clean;
    }

    /**
     * A line's related model, without attaching it to the line when it
     * wasn't loaded: resources include loaded relations, so loading one
     * here would change what they emit.
     *
     * @since 1.4.0
     *
     * @param  Model   $item      Cart or order line.
     * @param  string  $relation  Relation name.
     *
     * @return Model|null
     */
    protected function related( Model $item, string $relation ): ?Model
    {
        return $item->relationLoaded( $relation ) ? $item->getRelation( $relation ) : $item->{$relation}()->first();
    }

    /**
     * The type's rows when it describes its own options, else the default
     * rows; filtered and normalized.
     *
     * @since 1.4.0
     *
     * @param  Product|null          $product      Product.
     * @param  array<string, mixed>  $options      Line options.
     * @param  string|null           $variantName  Known variant name (`''` for none, null to look it up).
     *
     * @return array<int, array{label: string, value: string}>
     */
    protected function resolve( ?Product $product, array $options, ?string $variantName ): array
    {
        $rows = null;

        if ( null !== $product && ! $product->typeIsMissing() ) {
            $type = $product->productType();

            if ( $type instanceof DescribesLineOptions ) {
                try {
                    $rows = $type->describeLineOptions( $product, $options );
                } catch ( Throwable $exception ) {
                    report( $exception );
                    $rows = null;
                }
            }
        }

        $rows ??= $this->defaultRows( $product, $options, $variantName );

        return $this->normalize( applyFilters( 'ap.ecommerce.lineOptions.described', $this->normalize( $rows ), $product, $options ) );
    }

    /**
     * The default rows: the variant name, then each scalar option.
     *
     * @since 1.4.0
     *
     * @param  Product|null          $product      Product.
     * @param  array<string, mixed>  $options      Line options.
     * @param  string|null           $variantName  Known variant name (`''` for none, null to look it up).
     *
     * @return array<int, array{label: string, value: string}>
     */
    protected function defaultRows( ?Product $product, array $options, ?string $variantName ): array
    {
        $rows = [];
        $name = $variantName ?? $this->variantName( $product, $options['variant_id'] ?? null );

        if ( '' !== trim( (string) $name ) ) {
            $rows[] = [ 'label' => '', 'value' => trim( (string) $name ) ];
        }

        foreach ( $options as $key => $value ) {
            if ( ! is_string( $key ) || in_array( $key, self::HIDDEN_KEYS, true ) || str_starts_with( $key, '_' ) ) {
                continue;
            }

            $text = match ( true ) {
                true === $value                        => __( 'Yes' ),
                is_string( $value )                    => trim( $value ),
                is_int( $value ) || is_float( $value ) => (string) $value,
                default                                => '',
            };

            if ( '' !== $text ) {
                $rows[] = [ 'label' => Str::headline( $key ), 'value' => $text ];
            }
        }

        return $rows;
    }

    /**
     * The name of the variant `$variantId` of `$product`, or `''`.
     *
     * @since 1.4.0
     *
     * @param  Product|null  $product    Product.
     * @param  mixed         $variantId  Variant id.
     *
     * @return string
     */
    protected function variantName( ?Product $product, mixed $variantId ): string
    {
        if ( null === $product || ! is_numeric( $variantId ) ) {
            return '';
        }

        $variant = $product->relationLoaded( 'variants' )
            ? $product->variants->firstWhere( 'id', (int) $variantId )
            : ProductVariant::query()->whereKey( (int) $variantId )->where( 'product_id', $product->getKey() )->first();

        return (string) ( $variant?->name ?? '' );
    }
}
