<?php

/**
 * ProductService.
 *
 * The one write path for catalog products (engine spec §3.1–§3.10a): the
 * product row, its variants, per-currency prices, gallery, attributes,
 * category and tag links, grouped/bundled children, and stock settings.
 * Admin UIs, REST, GraphQL, and imports all call it, so a product is
 * validated the same way however it was made.
 *
 * Multi-table writes run in one transaction. Catalog rules that a request
 * validator can't see — a taken slug or SKU (across products and variants),
 * an unregistered type, an unknown tax class, a bundle that contains itself
 * — throw {@see ProductWriteException} with field-level errors. A product
 * whose type is missing (its satellite was uninstalled) is read-only.
 *
 * The lifecycle hooks (`ap.ecommerce.product.saving` / `.saved` /
 * `.published` / `.unpublished` / `.deleted`, `ap.ecommerce.variant.saved`)
 * fire from the models, and the activity log records the writes through its
 * observer.
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

use ArtisanPackUI\Ecommerce\Exceptions\ProductWriteException;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductAttribute;
use ArtisanPackUI\Ecommerce\Models\ProductAttributeValue;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use ArtisanPackUI\Ecommerce\Models\ProductChild;
use ArtisanPackUI\Ecommerce\Models\ProductImage;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ProductTag;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Models\ProductVariantOptionValue;
use ArtisanPackUI\Ecommerce\Models\TaxClass;
use ArtisanPackUI\Ecommerce\ProductTypes\BundledProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\GroupedProductType;
use ArtisanPackUI\Ecommerce\ProductTypes\VariableProductType;
use ArtisanPackUI\Ecommerce\Registries\ProductTypeRegistry;
use ArtisanPackUI\Ecommerce\ValueObjects\Currency as CurrencyVO;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProductService
{
    /**
     * Product columns a write may set.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const PRODUCT_COLUMNS = [
        'type',
        'name',
        'slug',
        'sku',
        'barcode',
        'description',
        'short_description',
        'status',
        'featured_image_media_id',
        'is_taxable',
        'tax_class_key',
        'weight',
        'weight_unit',
        'length',
        'width',
        'height',
        'dim_unit',
        'meta',
        'published_at',
    ];

    /**
     * Variant columns a write may set.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const VARIANT_COLUMNS = [
        'sku',
        'barcode',
        'name',
        'image_media_id',
        'weight',
        'weight_unit',
        'length',
        'width',
        'height',
        'dim_unit',
        'position',
        'meta',
    ];

    /**
     * Inventory settings a write may set (quantity changes go through
     * {@see self::adjustStock()} so they are audited).
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const INVENTORY_SETTINGS = [
        'track_inventory',
        'allow_backorder',
        'low_stock_threshold',
    ];

    /**
     * Product statuses.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const STATUSES = [ 'draft', 'active', 'archived' ];

    /**
     * Weight units for products and variants.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const WEIGHT_UNITS = [ 'g', 'kg', 'oz', 'lb' ];

    /**
     * Dimension units for products and variants.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const DIMENSION_UNITS = [ 'mm', 'cm', 'in' ];

    /**
     * htmLawed config for product rich text: safe mode drops `<script>`,
     * embeds, event-handler attributes, and `javascript:` URLs, which
     * `kses()`'s default config keeps.
     *
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    public const KSES_CONFIG = [
        'safe'           => 1,
        'elements'       => '* -style -link -meta -base -form -input -button -select -textarea -option -iframe -object -embed -applet -frame -frameset',
        'deny_attribute' => 'on*, style',
        'schemes'        => 'href: http, https, mailto, tel; src: http, https; *: http, https',
    ];

    /**
     * Most variants one "generate" call may create.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_GENERATED_VARIANTS = 500;

    /**
     * @since 1.0.0
     *
     * @param  ProductTypeRegistry  $types      Registered product types.
     * @param  InventoryService     $inventory  Audited stock adjustments.
     */
    public function __construct(
        protected ProductTypeRegistry $types,
        protected InventoryService $inventory,
    ) {
    }

    /**
     * Creates a product and, optionally, its related rows.
     *
     * Besides the product columns, `$data` may carry `prices` (rows of
     * `currency`, `price_amount`, `compare_at_amount`, `cost_amount`,
     * `starts_at`, `ends_at`), `category_ids`, `tag_ids`, `images` (rows of
     * `media_id` or `image_url`, plus `alt_text`), `featured_image_url`,
     * `attributes` (see {@see self::syncAttributes()}), `children` (rows of
     * `product_id`, `variant_id`, `quantity`), `inventory` (the
     * {@see self::INVENTORY_SETTINGS} plus an opening `quantity_on_hand`).
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $data  Product data.
     *
     * @throws ProductWriteException When a catalog rule is broken.
     *
     * @return Product
     */
    public function create( array $data ): Product
    {
        $type = (string) ( $data['type'] ?? '' );

        if ( '' === $type || ! $this->types->has( $type ) ) {
            throw ProductWriteException::field( 'type', 'unknown-type', __( 'Choose a registered product type.' ) );
        }

        if ( '' === trim( (string) ( $data['name'] ?? '' ) ) ) {
            throw ProductWriteException::field( 'name', 'required', __( 'A product needs a name.' ) );
        }

        return DB::transaction( function () use ( $data ): Product {
            $product = new Product();
            $this->fillProduct( $product, $data );
            $product->save();

            $this->syncRelations( $product, $data );

            if ( isset( $data['inventory'] ) && is_array( $data['inventory'] ) ) {
                $this->writeInventory( $product, $data['inventory'], true );
            }

            return $product->refresh();
        } );
    }

    /**
     * Updates a product and any related rows present in `$data`.
     *
     * Related keys are the same as {@see self::create()}. A present related
     * key replaces that set (absent keys are left alone). Stock changes on an
     * existing product go through `stock_adjustment` (`delta`, `reason`),
     * never through `inventory.quantity_on_hand`.
     *
     * @since 1.0.0
     *
     * @param  Product               $product  Product.
     * @param  array<string, mixed>  $data     Changed data.
     *
     * @throws ProductWriteException When a catalog rule is broken or the type is missing.
     *
     * @return Product
     */
    public function update( Product $product, array $data ): Product
    {
        $this->assertEditable( $product );

        if ( array_key_exists( 'type', $data ) && ! $this->types->has( (string) $data['type'] ) ) {
            throw ProductWriteException::field( 'type', 'unknown-type', __( 'Choose a registered product type.' ) );
        }

        if ( array_key_exists( 'name', $data ) && '' === trim( (string) $data['name'] ) ) {
            throw ProductWriteException::field( 'name', 'required', __( 'A product needs a name.' ) );
        }

        if ( array_key_exists( 'type', $data ) && (string) $data['type'] !== $product->type ) {
            $this->assertTypeChangeable( $product, (string) $data['type'] );
        }

        return DB::transaction( function () use ( $product, $data ): Product {
            $this->fillProduct( $product, $data );
            $product->save();

            $this->syncRelations( $product, $data );

            if ( isset( $data['inventory'] ) && is_array( $data['inventory'] ) ) {
                $this->writeInventory( $product, $data['inventory'], false );
            }

            if ( isset( $data['stock_adjustment'] ) && is_array( $data['stock_adjustment'] ) ) {
                $this->adjustStock(
                    $product,
                    (int) ( $data['stock_adjustment']['delta'] ?? 0 ),
                    (string) ( $data['stock_adjustment']['reason'] ?? '' ),
                    'stock_adjustment',
                );
            }

            return $product->refresh();
        } );
    }

    /**
     * Deletes a product with its variants, prices, stock rows, gallery,
     * attributes, links, and children.
     *
     * Order lines keep their snapshot; digital files are detached by their
     * foreign key.
     *
     * @since 1.0.0
     *
     * @param  Product  $product  Product.
     *
     * @return void
     */
    public function delete( Product $product ): void
    {
        if ( CartItem::query()->where( 'product_id', $product->id )->exists() ) {
            throw ProductWriteException::field( 'id', 'in-carts', __( 'This product is in shoppers\' carts. Archive it instead, or wait for those carts to expire.' ) );
        }

        DB::transaction( function () use ( $product ): void {
            $variantIds = $product->variants()->pluck( 'id' )->all();

            $this->deletePolymorphicRows( ProductVariant::class, $variantIds );
            $this->deletePolymorphicRows( Product::class, [ $product->id ] );

            $product->delete();
        } );
    }

    /**
     * Creates a variant of `$product`.
     *
     * `$data` may carry the {@see self::VARIANT_COLUMNS}, `option_values`
     * (`attribute_id => value_id`), `prices`, and `inventory` (settings plus
     * an opening `quantity_on_hand`).
     *
     * @since 1.0.0
     *
     * @param  Product               $product  Parent product.
     * @param  array<string, mixed>  $data     Variant data.
     *
     * @throws ProductWriteException When a catalog rule is broken.
     *
     * @return ProductVariant
     */
    public function createVariant( Product $product, array $data ): ProductVariant
    {
        $this->assertEditable( $product );

        return DB::transaction( function () use ( $product, $data ): ProductVariant {
            $variant             = new ProductVariant();
            $variant->product_id = $product->id;

            if ( ! array_key_exists( 'position', $data ) ) {
                $data['position'] = (int) $product->variants()->max( 'position' ) + 1;
            }

            $this->fillVariant( $variant, $data );
            $variant->save();

            $this->writeVariantRelations( $product, $variant, $data, true );

            return $variant->refresh();
        } );
    }

    /**
     * Updates a variant. Present related keys replace their set.
     *
     * @since 1.0.0
     *
     * @param  ProductVariant        $variant  Variant.
     * @param  array<string, mixed>  $data     Changed data (may include `stock_adjustment`).
     *
     * @throws ProductWriteException When a catalog rule is broken.
     *
     * @return ProductVariant
     */
    public function updateVariant( ProductVariant $variant, array $data ): ProductVariant
    {
        $product = $variant->product;
        $this->assertEditable( $product );

        return DB::transaction( function () use ( $product, $variant, $data ): ProductVariant {
            $this->fillVariant( $variant, $data );
            $variant->save();

            $this->writeVariantRelations( $product, $variant, $data, false );

            if ( isset( $data['stock_adjustment'] ) && is_array( $data['stock_adjustment'] ) ) {
                $this->adjustStock(
                    $variant,
                    (int) ( $data['stock_adjustment']['delta'] ?? 0 ),
                    (string) ( $data['stock_adjustment']['reason'] ?? '' ),
                    'stock_adjustment',
                );
            }

            return $variant->refresh();
        } );
    }

    /**
     * Deletes a variant with its prices and stock rows.
     *
     * @since 1.0.0
     *
     * @param  ProductVariant  $variant  Variant.
     *
     * @throws ProductWriteException When the product's type is missing.
     *
     * @return void
     */
    public function deleteVariant( ProductVariant $variant ): void
    {
        $this->assertEditable( $variant->product );

        if ( CartItem::query()->where( 'product_variant_id', $variant->id )->exists() ) {
            throw ProductWriteException::field( 'id', 'in-carts', __( 'This variant is in shoppers\' carts, so it can\'t be deleted yet.' ) );
        }

        DB::transaction( function () use ( $variant ): void {
            $this->deletePolymorphicRows( ProductVariant::class, [ $variant->id ] );
            $variant->delete();
        } );
    }

    /**
     * Sets variant positions from an ordered id list (ids not listed keep
     * their order after the listed ones).
     *
     * @since 1.0.0
     *
     * @param  Product          $product  Product.
     * @param  array<int, int>  $ids      Variant ids, first to last.
     *
     * @throws ProductWriteException When an id is not a variant of `$product`.
     *
     * @return Collection<int, ProductVariant>
     */
    public function reorderVariants( Product $product, array $ids ): Collection
    {
        $this->assertEditable( $product );
        $this->reorder( $product->variants()->getQuery(), $ids, 'ids' );

        return $product->variants()->orderBy( 'position' )->orderBy( 'id' )->get();
    }

    /**
     * How many variants the product's variation attributes describe (the
     * size of the attribute matrix), whether or not they exist yet.
     *
     * @since 1.0.0
     *
     * @param  Product  $product  Product.
     *
     * @return int
     */
    public function variantMatrixSize( Product $product ): int
    {
        $attributes = $this->variationAttributes( $product );

        if ( $attributes->isEmpty() ) {
            return 0;
        }

        $size = 1;

        foreach ( $attributes as $attribute ) {
            $size *= $attribute->values->count();

            // Past the generation cap the exact size doesn't matter; stopping
            // here also keeps a huge matrix from overflowing an int.
            if ( $size > self::MAX_GENERATED_VARIANTS ) {
                return self::MAX_GENERATED_VARIANTS + 1;
            }
        }

        return $size;
    }

    /**
     * Creates a variant for every combination of the product's variation
     * attribute values that doesn't have one yet.
     *
     * New variants are named from their option labels ("M / Red"), placed
     * after existing ones, and get `$defaults` (variant columns, `prices`,
     * `inventory`).
     *
     * @since 1.0.0
     *
     * @param  Product               $product   Variable product.
     * @param  array<string, mixed>  $defaults  Values for every new variant.
     *
     * @throws ProductWriteException When there are no variation attributes or the matrix is too large.
     *
     * @return Collection<int, ProductVariant> The variants created.
     */
    public function generateVariants( Product $product, array $defaults = [] ): Collection
    {
        $this->assertEditable( $product );

        $attributes = $this->variationAttributes( $product );

        if ( $attributes->isEmpty() || $attributes->contains( static fn ( ProductAttribute $attribute ): bool => $attribute->values->isEmpty() ) ) {
            throw ProductWriteException::field( 'attributes', 'no-variation-attributes', __( 'Add at least one value to each attribute used for variations first.' ) );
        }

        if ( $this->variantMatrixSize( $product ) > self::MAX_GENERATED_VARIANTS ) {
            throw ProductWriteException::field( 'attributes', 'too-many-variants', __( 'That would create more than :max variants.', [ 'max' => self::MAX_GENERATED_VARIANTS ] ) );
        }

        $existing = $this->existingCombinations( $product );
        $combos   = [ [] ];

        foreach ( $attributes as $attribute ) {
            $next = [];

            foreach ( $combos as $combo ) {
                foreach ( $attribute->values as $value ) {
                    $next[] = $combo + [ $attribute->id => $value ];
                }
            }

            $combos = $next;
        }

        return DB::transaction( function () use ( $product, $combos, $existing, $defaults ): Collection {
            $created  = new Collection();
            $position = (int) $product->variants()->max( 'position' );

            foreach ( $combos as $combo ) {
                $map = array_map( static fn ( ProductAttributeValue $value ): int => $value->id, $combo );

                if ( in_array( $this->combinationKey( $map ), $existing, true ) ) {
                    continue;
                }

                $data = array_merge( $defaults, [
                    'name'          => implode( ' / ', array_map( static fn ( ProductAttributeValue $value ): string => $value->label, $combo ) ),
                    'position'      => ++$position,
                    'option_values' => $map,
                    'sku'           => null,
                ] );

                $variant             = new ProductVariant();
                $variant->product_id = $product->id;
                $variant->setRelation( 'product', $product );
                $this->fillVariant( $variant, $data );
                $variant->save();

                // The combinations come from the product's own values and were
                // checked against the existing variants above.
                $this->writeVariantRelations( $product, $variant, $data, true, true );

                $created->push( $variant );
            }

            return $created;
        } );
    }

    /**
     * Replaces the price rows of a product or variant.
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant            $priceable  Owner.
     * @param  array<int, array<string, mixed>>  $rows       Price rows.
     * @param  string                            $field      Error field prefix.
     *
     * @throws ProductWriteException When a row is invalid or two rows share a currency and window.
     *
     * @return Collection<int, ProductPrice>
     */
    public function syncPrices( Product|ProductVariant $priceable, array $rows, string $field = 'prices' ): Collection
    {
        $this->assertOwnerEditable( $priceable );

        $normalized = [];
        $seen       = [];

        foreach ( array_values( $rows ) as $index => $row ) {
            $clean = $this->normalizePrice( (array) $row, "{$field}.{$index}" );
            $key   = $this->windowKey( $clean['currency'], $clean['starts_at'], $clean['ends_at'] );

            if ( isset( $seen[ $key ] ) ) {
                throw ProductWriteException::field( "{$field}.{$index}.currency", 'duplicate-price', __( 'Two prices share this currency and schedule.' ) );
            }

            $seen[ $key ]  = true;
            $normalized[]  = $clean;
        }

        return DB::transaction( function () use ( $priceable, $normalized ): Collection {
            $priceable->prices()->delete();

            foreach ( $normalized as $row ) {
                $priceable->prices()->create( $row );
            }

            return $priceable->prices()->orderBy( 'currency' )->orderBy( 'starts_at' )->get();
        } );
    }

    /**
     * Creates or updates one price row, matched on currency and schedule.
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant  $priceable  Owner.
     * @param  array<string, mixed>    $row        Price row.
     *
     * @throws ProductWriteException When the row is invalid.
     *
     * @return ProductPrice
     */
    public function upsertPrice( Product|ProductVariant $priceable, array $row ): ProductPrice
    {
        $this->assertOwnerEditable( $priceable );

        $clean = $this->normalizePrice( $row, null );

        $query = $priceable->prices()->where( 'currency', $clean['currency'] );

        foreach ( [ 'starts_at', 'ends_at' ] as $column ) {
            null === $clean[ $column ]
                ? $query->whereNull( $column )
                : $query->where( $column, $clean[ $column ] );
        }

        $price = $query->first() ?? $priceable->prices()->make();
        $price->fill( $clean )->save();

        return $price;
    }

    /**
     * Updates an existing price row.
     *
     * @since 1.0.0
     *
     * @param  ProductPrice          $price  Price row.
     * @param  array<string, mixed>  $row    Changed values.
     *
     * @throws ProductWriteException When the result is invalid or collides with another row.
     *
     * @return ProductPrice
     */
    public function updatePrice( ProductPrice $price, array $row ): ProductPrice
    {
        $owner = $price->priceable;

        if ( $owner instanceof Product || $owner instanceof ProductVariant ) {
            $this->assertOwnerEditable( $owner );
        }

        $clean = $this->normalizePrice( array_merge( [
            'currency'          => $price->currency,
            'price_amount'      => $price->price_amount,
            'compare_at_amount' => $price->compare_at_amount,
            'cost_amount'       => $price->cost_amount,
            'starts_at'         => $price->starts_at,
            'ends_at'           => $price->ends_at,
        ], $row ), null );

        $key   = $this->windowKey( $clean['currency'], $clean['starts_at'], $clean['ends_at'] );
        $clash = ProductPrice::query()
            ->where( 'priceable_type', $price->priceable_type )
            ->where( 'priceable_id', $price->priceable_id )
            ->whereKeyNot( $price->id )
            ->get()
            ->contains( fn ( ProductPrice $other ): bool => $this->windowKey( $other->currency, $other->starts_at, $other->ends_at ) === $key );

        if ( $clash ) {
            throw ProductWriteException::field( 'currency', 'duplicate-price', __( 'Two prices share this currency and schedule.' ) );
        }

        $price->fill( $clean )->save();

        return $price;
    }

    /**
     * Adds a gallery image.
     *
     * @since 1.0.0
     *
     * @param  Product               $product  Product.
     * @param  array<string, mixed>  $data     `media_id` or `image_url`, `alt_text`, `position`.
     *
     * @throws ProductWriteException When neither source is given or the URL is not http(s).
     *
     * @return ProductImage
     */
    public function addImage( Product $product, array $data ): ProductImage
    {
        $this->assertEditable( $product );

        $clean = $this->normalizeImage( $data, null );

        if ( ! array_key_exists( 'position', $data ) ) {
            $clean['position'] = (int) $product->images()->max( 'position' ) + 1;
        }

        return $product->images()->create( $clean );
    }

    /**
     * Updates a gallery image's source, alt text, or position.
     *
     * @since 1.0.0
     *
     * @param  ProductImage          $image  Image.
     * @param  array<string, mixed>  $data   Changed values.
     *
     * @throws ProductWriteException When the result has no source.
     *
     * @return ProductImage
     */
    public function updateImage( ProductImage $image, array $data ): ProductImage
    {
        $this->assertEditable( $image->product );

        $image->fill( $this->normalizeImage( array_merge( $image->only( [ 'media_id', 'image_url', 'alt_text', 'position' ] ), $data ), null ) )->save();

        return $image;
    }

    /**
     * Removes a gallery image.
     *
     * @since 1.0.0
     *
     * @param  ProductImage  $image  Image.
     *
     * @return void
     */
    public function removeImage( ProductImage $image ): void
    {
        $this->assertEditable( $image->product );

        $image->delete();
    }

    /**
     * Sets gallery positions from an ordered id list.
     *
     * @since 1.0.0
     *
     * @param  Product          $product  Product.
     * @param  array<int, int>  $ids      Image ids, first to last.
     *
     * @throws ProductWriteException When an id is not one of the product's images.
     *
     * @return Collection<int, ProductImage>
     */
    public function reorderImages( Product $product, array $ids ): Collection
    {
        $this->assertEditable( $product );
        $this->reorder( ProductImage::query()->where( 'product_id', $product->id ), $ids, 'ids' );

        return $product->images()->get();
    }

    /**
     * Replaces the gallery. Rows with an `id` of an existing image update it;
     * other rows are added; images not listed are removed. Order follows the
     * list.
     *
     * @since 1.0.0
     *
     * @param  Product                           $product  Product.
     * @param  array<int, array<string, mixed>>  $rows     Image rows.
     *
     * @throws ProductWriteException When a row is invalid.
     *
     * @return Collection<int, ProductImage>
     */
    public function syncImages( Product $product, array $rows ): Collection
    {
        $existing = $product->images()->get()->keyBy( 'id' );
        $keep     = [];

        foreach ( array_values( $rows ) as $index => $row ) {
            $row               = (array) $row;
            $clean             = [ 'position' => $index ] + $this->normalizeImage( $row, "images.{$index}" );
            $id                = isset( $row['id'] ) ? (int) $row['id'] : null;

            if ( null !== $id && $existing->has( $id ) ) {
                $existing->get( $id )->fill( $clean )->save();
                $keep[] = $id;
                continue;
            }

            $keep[] = $product->images()->create( $clean )->id;
        }

        ProductImage::query()->where( 'product_id', $product->id )->whereNotIn( 'id', $keep )->delete();

        return $product->images()->get();
    }

    /**
     * Creates an attribute (with values) on a product.
     *
     * @since 1.0.0
     *
     * @param  Product               $product  Product.
     * @param  array<string, mixed>  $data     `key`, `label`, `position`, `is_variation`, `values`.
     *
     * @throws ProductWriteException When the key is taken or invalid.
     *
     * @return ProductAttribute
     */
    public function createAttribute( Product $product, array $data ): ProductAttribute
    {
        $this->assertEditable( $product );

        return DB::transaction( function () use ( $product, $data ): ProductAttribute {
            $attribute             = new ProductAttribute();
            $attribute->product_id = $product->id;

            if ( ! array_key_exists( 'position', $data ) ) {
                $data['position'] = (int) $product->productAttributes()->max( 'position' ) + 1;
            }

            $this->fillAttribute( $attribute, $data, null );
            $attribute->save();

            if ( array_key_exists( 'values', $data ) ) {
                $this->syncAttributeValues( $attribute, (array) $data['values'], 'values' );
            }

            return $attribute->load( 'values' );
        } );
    }

    /**
     * Updates an attribute. A present `values` list replaces the values
     * (matched on `id`, then on `value`); removing a value removes it from
     * the variants that used it.
     *
     * @since 1.0.0
     *
     * @param  ProductAttribute      $attribute  Attribute.
     * @param  array<string, mixed>  $data       Changed data.
     *
     * @throws ProductWriteException When the key is taken or invalid.
     *
     * @return ProductAttribute
     */
    public function updateAttribute( ProductAttribute $attribute, array $data ): ProductAttribute
    {
        $this->assertEditable( $attribute->product );

        return DB::transaction( function () use ( $attribute, $data ): ProductAttribute {
            $this->fillAttribute( $attribute, $data, null );
            $attribute->save();

            if ( array_key_exists( 'values', $data ) ) {
                $this->syncAttributeValues( $attribute, (array) $data['values'], 'values' );
            }

            return $attribute->load( 'values' );
        } );
    }

    /**
     * Deletes an attribute and its values.
     *
     * @since 1.0.0
     *
     * @param  ProductAttribute  $attribute  Attribute.
     *
     * @return void
     */
    public function deleteAttribute( ProductAttribute $attribute ): void
    {
        $this->assertEditable( $attribute->product );

        $attribute->delete();
    }

    /**
     * Replaces a product's attributes. Rows with an existing `id` (or a
     * matching `key`) update it; other rows are added; attributes not listed
     * are removed. Order follows the list.
     *
     * @since 1.0.0
     *
     * @param  Product                           $product  Product.
     * @param  array<int, array<string, mixed>>  $rows     Attribute rows (each with `values`).
     *
     * @throws ProductWriteException When a row is invalid or two rows share a key.
     *
     * @return Collection<int, ProductAttribute>
     */
    public function syncAttributes( Product $product, array $rows ): Collection
    {
        $existing = $product->productAttributes()->get();
        $keep     = [];
        $keys     = [];

        foreach ( array_values( $rows ) as $index => $row ) {
            $row = (array) $row;
            $key = Str::slug( (string) ( $row['key'] ?? $row['label'] ?? '' ), '_' );

            if ( isset( $keys[ $key ] ) ) {
                throw ProductWriteException::field( "attributes.{$index}.key", 'attribute-key-taken', __( 'Each attribute needs its own key.' ) );
            }

            $keys[ $key ] = true;

            $attribute = ( isset( $row['id'] ) ? $existing->firstWhere( 'id', (int) $row['id'] ) : null )
                ?? $existing->firstWhere( 'key', $key )
                ?? new ProductAttribute( [ 'product_id' => $product->id ] );

            $attribute->product_id = $product->id;
            $this->fillAttribute( $attribute, array_merge( $row, [ 'position' => $index ] ), "attributes.{$index}" );
            $attribute->save();

            $this->syncAttributeValues( $attribute, (array) ( $row['values'] ?? [] ), "attributes.{$index}.values" );

            $keep[] = $attribute->id;
        }

        ProductAttribute::query()->where( 'product_id', $product->id )->whereNotIn( 'id', $keep )->delete();

        return $product->productAttributes()->with( 'values' )->orderBy( 'position' )->get();
    }

    /**
     * Sets a product's categories.
     *
     * @since 1.0.0
     *
     * @param  Product          $product  Product.
     * @param  array<int, int>  $ids      Category ids.
     * @param  string           $mode     `sync` (replace), `attach`, or `detach`.
     *
     * @throws ProductWriteException When an id doesn't exist.
     *
     * @return Collection<int, ProductCategory>
     */
    public function setCategories( Product $product, array $ids, string $mode = 'sync' ): Collection
    {
        $this->assertEditable( $product );

        $ids = $this->existingIds( ProductCategory::class, $ids, 'category_ids' );

        $this->applyPivot( $product->categories(), $ids, $mode );

        return $product->categories()->get();
    }

    /**
     * Sets a product's tags.
     *
     * @since 1.0.0
     *
     * @param  Product          $product  Product.
     * @param  array<int, int>  $ids      Tag ids.
     * @param  string           $mode     `sync` (replace), `attach`, or `detach`.
     *
     * @throws ProductWriteException When an id doesn't exist.
     *
     * @return Collection<int, ProductTag>
     */
    public function setTags( Product $product, array $ids, string $mode = 'sync' ): Collection
    {
        $this->assertEditable( $product );

        $ids = $this->existingIds( ProductTag::class, $ids, 'tag_ids' );

        $this->applyPivot( $product->tags(), $ids, $mode );

        return $product->tags()->get();
    }

    /**
     * Replaces the members of a grouped or bundled product.
     *
     * Each row is `product_id`, optional `variant_id` (a variant of that
     * product), and `quantity` (≥ 1). A product can't contain itself, the
     * same product and variant twice, or a product that already contains it
     * (directly or further down), so the structure never loops.
     *
     * @since 1.0.0
     *
     * @param  Product                           $parent  Grouped or bundled product.
     * @param  array<int, array<string, mixed>>  $rows    Member rows, in order.
     *
     * @throws ProductWriteException When the product can't have members or a row is invalid.
     *
     * @return Collection<int, ProductChild>
     */
    public function syncChildren( Product $parent, array $rows ): Collection
    {
        $this->assertEditable( $parent );

        if ( ! $this->acceptsChildren( $parent->type ) ) {
            throw ProductWriteException::field( 'children', 'not-a-parent', __( 'Only grouped and bundled products contain other products.' ) );
        }

        $clean = [];
        $seen  = [];

        foreach ( array_values( $rows ) as $index => $row ) {
            $row       = (array) $row;
            $childId   = (int) ( $row['product_id'] ?? 0 );
            $variantId = isset( $row['variant_id'] ) && '' !== $row['variant_id'] ? (int) $row['variant_id'] : null;
            $quantity  = (int) ( $row['quantity'] ?? 1 );
            $field     = "children.{$index}";

            if ( $childId === (int) $parent->id ) {
                throw ProductWriteException::field( "{$field}.product_id", 'child-self', __( 'A product can\'t contain itself.' ) );
            }

            if ( ! Product::query()->whereKey( $childId )->exists() ) {
                throw ProductWriteException::field( "{$field}.product_id", 'child-missing', __( 'That product no longer exists.' ) );
            }

            if ( null !== $variantId && ! ProductVariant::query()->whereKey( $variantId )->where( 'product_id', $childId )->exists() ) {
                throw ProductWriteException::field( "{$field}.variant_id", 'child-variant', __( 'That variant doesn\'t belong to the chosen product.' ) );
            }

            if ( $quantity < 1 ) {
                throw ProductWriteException::field( "{$field}.quantity", 'invalid-quantity', __( 'The quantity must be at least 1.' ) );
            }

            $key = $childId . ':' . ( $variantId ?? '' );

            if ( isset( $seen[ $key ] ) ) {
                throw ProductWriteException::field( "{$field}.product_id", 'duplicate-child', __( 'That product is already in the list.' ) );
            }

            if ( $this->contains( $childId, (int) $parent->id ) ) {
                throw ProductWriteException::field( "{$field}.product_id", 'child-cycle', __( 'That product already contains this one, which would create a loop.' ) );
            }

            $seen[ $key ] = true;
            $clean[]      = [
                'child_product_id' => $childId,
                'child_variant_id' => $variantId,
                'quantity'         => $quantity,
                'position'         => $index,
            ];
        }

        return DB::transaction( function () use ( $parent, $clean ): Collection {
            ProductChild::query()->where( 'parent_product_id', $parent->id )->delete();

            foreach ( $clean as $row ) {
                $parent->children()->create( $row );
            }

            return $parent->children()->with( [ 'product', 'variant' ] )->get();
        } );
    }

    /**
     * Whether a product of `$type` may have members.
     *
     * Core `grouped` and `bundled` types do; a satellite type opts in with
     * `has_children => true` in its registry meta.
     *
     * @since 1.0.0
     *
     * @param  string  $type  Product type key.
     *
     * @return bool
     */
    public function acceptsChildren( string $type ): bool
    {
        return in_array( $type, [ GroupedProductType::KEY, BundledProductType::KEY ], true )
            || true === ( $this->types->meta( $type )['has_children'] ?? false );
    }

    /**
     * Changes the stock of a product or variant through
     * {@see InventoryService::adjust()}, so the change is audited, and
     * creates the stock row when there is none.
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant  $stockable  Product or variant.
     * @param  int                     $delta      Units to add (negative removes).
     * @param  string                  $reason     Why (required).
     * @param  string                  $field      Error field prefix.
     *
     * @throws ProductWriteException When no reason is given.
     *
     * @return InventoryItem
     */
    public function adjustStock( Product|ProductVariant $stockable, int $delta, string $reason, string $field = 'stock_adjustment' ): InventoryItem
    {
        $this->assertOwnerEditable( $stockable );

        $item = $this->inventoryItemFor( $stockable );

        if ( 0 === $delta ) {
            return $item;
        }

        if ( '' === trim( $reason ) ) {
            throw ProductWriteException::field( "{$field}.reason", 'reason-required', __( 'Give a reason for the stock change.' ) );
        }

        return $this->inventory->adjust( $item, $delta, trim( $reason ) );
    }

    /**
     * The stock row (no warehouse) for a product or variant, created with
     * default settings when missing.
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant  $stockable  Product or variant.
     *
     * @return InventoryItem
     */
    public function inventoryItemFor( Product|ProductVariant $stockable ): InventoryItem
    {
        return InventoryItem::query()->firstOrCreate( [
            'stockable_type' => $stockable->getMorphClass(),
            'stockable_id'   => $stockable->getKey(),
            'warehouse_id'   => null,
        ] );
    }

    /**
     * A slug for `$name` that no product (other than `$ignoreId`) uses.
     *
     * @since 1.0.0
     *
     * @param  string    $name      Source text.
     * @param  int|null  $ignoreId  Product to ignore.
     *
     * @return string
     */
    public function uniqueSlug( string $name, ?int $ignoreId = null ): string
    {
        $base = Str::slug( $name );
        $base = '' === $base ? 'product' : Str::limit( $base, 240, '' );
        $slug = $base;
        $n    = 2;

        while ( $this->slugTaken( $slug, $ignoreId ) ) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    /**
     * Throws when the product's type is missing (read-only, parent plan §16.6).
     *
     * @since 1.0.0
     *
     * @param  Product  $product  Product.
     *
     * @throws ProductWriteException When the type is missing.
     *
     * @return void
     */
    public function assertEditable( Product $product ): void
    {
        if ( $product->typeIsMissing() ) {
            throw ProductWriteException::field( 'type', 'type-missing', (string) $product->typeWarning() );
        }
    }

    /**
     * Like {@see self::assertEditable()} for a product or one of its variants.
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant  $owner  Product or variant.
     *
     * @throws ProductWriteException When the product's type is missing.
     *
     * @return void
     */
    protected function assertOwnerEditable( Product|ProductVariant $owner ): void
    {
        $product = $owner instanceof Product ? $owner : $owner->product;

        if ( null !== $product ) {
            $this->assertEditable( $product );
        }
    }

    /**
     * Refuses a type change that would strand the product's variants or
     * grouped/bundled members.
     *
     * @since 1.0.0
     *
     * @param  Product  $product  Product.
     * @param  string   $type     New type key.
     *
     * @throws ProductWriteException When variants or members would be left behind.
     *
     * @return void
     */
    protected function assertTypeChangeable( Product $product, string $type ): void
    {
        if ( ! $this->acceptsChildren( $type ) && ProductChild::query()->where( 'parent_product_id', $product->id )->exists() ) {
            throw ProductWriteException::field( 'type', 'type-has-children', __( 'Remove the products it contains before changing the type.' ) );
        }

        if ( VariableProductType::KEY === $product->type && VariableProductType::KEY !== $type && $product->variants()->exists() ) {
            throw ProductWriteException::field( 'type', 'type-has-variants', __( 'Delete its variants before changing the type.' ) );
        }
    }

    /**
     * Refuses a non-http(s) image URL stored in meta.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $meta  Meta.
     * @param  string                $key   Meta key holding a URL.
     *
     * @throws ProductWriteException When the URL isn't http(s).
     *
     * @return void
     */
    protected function assertMetaUrl( array $meta, string $key ): void
    {
        $url = $meta[ $key ] ?? null;

        if ( null !== $url && '' !== $url && ( ! is_string( $url ) || ! $this->isHttpUrl( $url ) ) ) {
            throw ProductWriteException::field( "meta.{$key}", 'invalid-url', __( 'Use an http or https image URL.' ) );
        }
    }

    /**
     * Fills and validates the product columns present in `$data`.
     *
     * @since 1.0.0
     *
     * @param  Product               $product  Product.
     * @param  array<string, mixed>  $data     Data.
     *
     * @throws ProductWriteException When a column breaks a rule.
     *
     * @return void
     */
    protected function fillProduct( Product $product, array $data ): void
    {
        $values   = array_intersect_key( $data, array_flip( self::PRODUCT_COLUMNS ) );
        $ignoreId = $product->exists ? (int) $product->id : null;

        if ( ! $product->exists && empty( $values['slug'] ) ) {
            $values['slug'] = $this->uniqueSlug( (string) $data['name'] );
        } elseif ( array_key_exists( 'slug', $values ) ) {
            $values['slug'] = Str::slug( (string) $values['slug'] );

            if ( '' === $values['slug'] ) {
                throw ProductWriteException::field( 'slug', 'required', __( 'The slug can\'t be empty.' ) );
            }

            if ( $this->slugTaken( $values['slug'], $ignoreId ) ) {
                throw ProductWriteException::field( 'slug', 'slug-taken', __( 'Another product already uses this slug.' ) );
            }
        }

        if ( array_key_exists( 'sku', $values ) ) {
            $values['sku'] = $this->blankToNull( $values['sku'] );

            if ( null !== $values['sku'] && $this->skuTaken( $values['sku'], $ignoreId, null ) ) {
                throw ProductWriteException::field( 'sku', 'sku-taken', __( 'Another product or variant already uses this SKU.' ) );
            }
        }

        if ( array_key_exists( 'status', $values ) && ! in_array( $values['status'], self::STATUSES, true ) ) {
            throw ProductWriteException::field( 'status', 'invalid-status', __( 'Choose draft, active, or archived.' ) );
        }

        if ( array_key_exists( 'tax_class_key', $values ) ) {
            $values['tax_class_key'] = $this->blankToNull( $values['tax_class_key'] );

            if ( null !== $values['tax_class_key'] && ! TaxClass::query()->where( 'key', $values['tax_class_key'] )->exists() ) {
                throw ProductWriteException::field( 'tax_class_key', 'unknown-tax-class', __( 'Choose an existing tax class.' ) );
            }
        }

        foreach ( [ 'description', 'short_description' ] as $column ) {
            if ( array_key_exists( $column, $values ) ) {
                $values[ $column ] = $this->sanitizeHtml( $values[ $column ] );
            }
        }

        foreach ( [ 'barcode', 'weight_unit', 'dim_unit' ] as $column ) {
            if ( array_key_exists( $column, $values ) ) {
                $values[ $column ] = $this->blankToNull( $values[ $column ] );
            }
        }

        $this->assertUnits( $values );

        if ( array_key_exists( 'meta', $values ) ) {
            // Each top-level meta key is replaced as a whole (not merged deeply).
            $values['meta'] = array_replace( (array) ( $product->meta ?? [] ), (array) $values['meta'] );
            $this->assertMetaUrl( $values['meta'], 'featured_image_url' );
        }

        if ( array_key_exists( 'featured_image_url', $data ) ) {
            $meta = (array) ( $values['meta'] ?? $product->meta ?? [] );
            $url  = $this->blankToNull( $data['featured_image_url'] );

            if ( null !== $url && ! $this->isHttpUrl( $url ) ) {
                throw ProductWriteException::field( 'featured_image_url', 'invalid-url', __( 'Use an http or https image URL.' ) );
            }

            if ( null === $url ) {
                unset( $meta['featured_image_url'] );
            } else {
                $meta['featured_image_url'] = $url;
            }

            $values['meta'] = $meta;
        }

        if ( ! $product->exists && ! array_key_exists( 'meta', $values ) ) {
            $values['meta'] = [];
        }

        $product->fill( $values );
    }

    /**
     * Writes the related sets present in `$data`.
     *
     * @since 1.0.0
     *
     * @param  Product               $product  Saved product.
     * @param  array<string, mixed>  $data     Data.
     *
     * @throws ProductWriteException When a related row is invalid.
     *
     * @return void
     */
    protected function syncRelations( Product $product, array $data ): void
    {
        if ( array_key_exists( 'prices', $data ) ) {
            $this->syncPrices( $product, (array) $data['prices'] );
        }

        if ( array_key_exists( 'category_ids', $data ) ) {
            $this->setCategories( $product, (array) $data['category_ids'] );
        }

        if ( array_key_exists( 'tag_ids', $data ) ) {
            $this->setTags( $product, (array) $data['tag_ids'] );
        }

        if ( array_key_exists( 'images', $data ) ) {
            $this->syncImages( $product, (array) $data['images'] );
        }

        if ( array_key_exists( 'attributes', $data ) ) {
            $this->syncAttributes( $product, (array) $data['attributes'] );
        }

        if ( array_key_exists( 'children', $data ) ) {
            $this->syncChildren( $product, (array) $data['children'] );
        }
    }

    /**
     * Writes stock settings and, on create, the opening quantity.
     *
     * @since 1.0.0
     *
     * @param  Product|ProductVariant  $stockable  Product or variant.
     * @param  array<string, mixed>    $settings   Settings.
     * @param  bool                    $opening    Whether `quantity_on_hand` is an opening balance.
     * @param  string                  $field      Error field prefix.
     *
     * @throws ProductWriteException When a value is out of range.
     *
     * @return InventoryItem
     */
    protected function writeInventory( Product|ProductVariant $stockable, array $settings, bool $opening, string $field = 'inventory' ): InventoryItem
    {
        $item   = $this->inventoryItemFor( $stockable );
        $values = array_intersect_key( $settings, array_flip( self::INVENTORY_SETTINGS ) );

        if ( array_key_exists( 'low_stock_threshold', $values ) ) {
            $threshold = $this->blankToNull( $values['low_stock_threshold'] );

            if ( null !== $threshold && ( ! is_numeric( $threshold ) || (int) $threshold < 0 ) ) {
                throw ProductWriteException::field( "{$field}.low_stock_threshold", 'invalid-threshold', __( 'The low-stock threshold can\'t be negative.' ) );
            }

            $values['low_stock_threshold'] = null === $threshold ? null : (int) $threshold;
        }

        foreach ( [ 'track_inventory', 'allow_backorder' ] as $flag ) {
            if ( array_key_exists( $flag, $values ) ) {
                $values[ $flag ] = (bool) $values[ $flag ];
            }
        }

        $item->fill( $values )->save();

        if ( $opening && isset( $settings['quantity_on_hand'] ) && 0 !== (int) $settings['quantity_on_hand'] ) {
            $item = $this->inventory->adjust( $item, (int) $settings['quantity_on_hand'], __( 'Opening stock' ) );
        }

        return $item;
    }

    /**
     * Fills and validates the variant columns present in `$data`.
     *
     * @since 1.0.0
     *
     * @param  ProductVariant        $variant  Variant.
     * @param  array<string, mixed>  $data     Data.
     *
     * @throws ProductWriteException When the SKU is taken.
     *
     * @return void
     */
    protected function fillVariant( ProductVariant $variant, array $data ): void
    {
        $values = array_intersect_key( $data, array_flip( self::VARIANT_COLUMNS ) );

        if ( array_key_exists( 'sku', $values ) ) {
            $values['sku'] = $this->blankToNull( $values['sku'] );

            if ( null !== $values['sku'] && $this->skuTaken( $values['sku'], null, $variant->exists ? (int) $variant->id : null ) ) {
                throw ProductWriteException::field( 'sku', 'sku-taken', __( 'Another product or variant already uses this SKU.' ) );
            }
        }

        foreach ( [ 'barcode', 'name', 'weight_unit', 'dim_unit' ] as $column ) {
            if ( array_key_exists( $column, $values ) ) {
                $values[ $column ] = $this->blankToNull( $values[ $column ] );
            }
        }

        $this->assertUnits( $values );

        if ( array_key_exists( 'meta', $values ) ) {
            $values['meta'] = array_replace( (array) ( $variant->meta ?? [] ), (array) $values['meta'] );
            $this->assertMetaUrl( $values['meta'], 'image_url' );
        } elseif ( ! $variant->exists ) {
            $values['meta'] = [];
        }

        $variant->fill( $values );
    }

    /**
     * Writes a variant's option values, prices, and stock.
     *
     * @since 1.0.0
     *
     * @param  Product               $product  Parent product.
     * @param  ProductVariant        $variant  Saved variant.
     * @param  array<string, mixed>  $data     Data.
     * @param  bool                  $opening  Whether this is a new variant.
     * @param  bool                  $trusted  Option values are known-valid and unique (skip the checks).
     *
     * @throws ProductWriteException When an option value is invalid or duplicates another variant.
     *
     * @return void
     */
    protected function writeVariantRelations( Product $product, ProductVariant $variant, array $data, bool $opening, bool $trusted = false ): void
    {
        if ( $trusted && array_key_exists( 'option_values', $data ) ) {
            foreach ( (array) $data['option_values'] as $attributeId => $valueId ) {
                ProductVariantOptionValue::query()->create( [
                    'product_variant_id'         => $variant->id,
                    'product_attribute_id'       => (int) $attributeId,
                    'product_attribute_value_id' => (int) $valueId,
                ] );
            }

            unset( $data['option_values'] );
        }

        if ( array_key_exists( 'option_values', $data ) ) {
            $map = [];

            foreach ( (array) $data['option_values'] as $attributeId => $valueId ) {
                $value = ProductAttributeValue::query()
                    ->whereKey( (int) $valueId )
                    ->where( 'product_attribute_id', (int) $attributeId )
                    ->whereHas( 'attribute', static fn ( $query ) => $query->where( 'product_id', $product->id ) )
                    ->first();

                if ( null === $value ) {
                    throw ProductWriteException::field( "option_values.{$attributeId}", 'option-value', __( 'Choose a value of one of this product\'s attributes.' ) );
                }

                $map[ (int) $attributeId ] = (int) $value->id;
            }

            if ( [] !== $map && in_array( $this->combinationKey( $map ), $this->existingCombinations( $product, $variant->id ), true ) ) {
                throw ProductWriteException::field( 'option_values', 'duplicate-combination', __( 'Another variant already has these options.' ) );
            }

            ProductVariantOptionValue::query()->where( 'product_variant_id', $variant->id )->delete();

            foreach ( $map as $attributeId => $valueId ) {
                ProductVariantOptionValue::query()->create( [
                    'product_variant_id'         => $variant->id,
                    'product_attribute_id'       => $attributeId,
                    'product_attribute_value_id' => $valueId,
                ] );
            }
        }

        if ( array_key_exists( 'prices', $data ) ) {
            $this->syncPrices( $variant, (array) $data['prices'] );
        }

        if ( isset( $data['inventory'] ) && is_array( $data['inventory'] ) ) {
            $this->writeInventory( $variant, $data['inventory'], $opening );
        }
    }

    /**
     * Fills and validates an attribute.
     *
     * @since 1.0.0
     *
     * @param  ProductAttribute      $attribute  Attribute.
     * @param  array<string, mixed>  $data       Data.
     * @param  string|null           $field      Error field prefix.
     *
     * @throws ProductWriteException When the key or label is missing or the key is taken.
     *
     * @return void
     */
    protected function fillAttribute( ProductAttribute $attribute, array $data, ?string $field ): void
    {
        $prefix = null === $field ? '' : "{$field}.";
        $values = array_intersect_key( $data, array_flip( [ 'key', 'label', 'position', 'is_variation' ] ) );

        if ( array_key_exists( 'key', $values ) || ! $attribute->exists ) {
            $values['key'] = Str::slug( (string) ( $values['key'] ?? $data['label'] ?? '' ), '_' );

            if ( '' === $values['key'] ) {
                throw ProductWriteException::field( "{$prefix}key", 'required', __( 'An attribute needs a key.' ) );
            }

            $taken = ProductAttribute::query()
                ->where( 'product_id', $attribute->product_id )
                ->where( 'key', $values['key'] )
                ->when( $attribute->exists, static fn ( $query ) => $query->whereKeyNot( $attribute->id ) )
                ->exists();

            if ( $taken ) {
                throw ProductWriteException::field( "{$prefix}key", 'attribute-key-taken', __( 'This product already has an attribute with that key.' ) );
            }
        }

        if ( array_key_exists( 'label', $values ) || ! $attribute->exists ) {
            $values['label'] = trim( (string) ( $values['label'] ?? '' ) );

            if ( '' === $values['label'] ) {
                throw ProductWriteException::field( "{$prefix}label", 'required', __( 'An attribute needs a label.' ) );
            }
        }

        if ( array_key_exists( 'is_variation', $values ) ) {
            $values['is_variation'] = (bool) $values['is_variation'];
        }

        $attribute->fill( $values );
    }

    /**
     * Replaces an attribute's values. Order follows the list.
     *
     * @since 1.0.0
     *
     * @param  ProductAttribute                  $attribute  Saved attribute.
     * @param  array<int, array<string, mixed>>  $rows       `value`, `label`, `swatch`, optional `id`.
     * @param  string                            $field      Error field prefix.
     *
     * @throws ProductWriteException When a value is blank or repeated.
     *
     * @return void
     */
    protected function syncAttributeValues( ProductAttribute $attribute, array $rows, string $field ): void
    {
        $existing = $attribute->values()->get();
        $keep     = [];
        $seen     = [];

        foreach ( array_values( $rows ) as $index => $row ) {
            $row   = is_array( $row ) ? $row : [ 'label' => (string) $row ];
            $label = trim( (string) ( $row['label'] ?? $row['value'] ?? '' ) );
            $value = Str::slug( (string) ( $row['value'] ?? $label ), '_' );

            if ( '' === $value || '' === $label ) {
                throw ProductWriteException::field( "{$field}.{$index}.label", 'required', __( 'Each value needs a label.' ) );
            }

            if ( isset( $seen[ $value ] ) ) {
                throw ProductWriteException::field( "{$field}.{$index}.label", 'duplicate-value', __( 'This attribute already has that value.' ) );
            }

            $seen[ $value ] = true;

            $model = ( isset( $row['id'] ) ? $existing->firstWhere( 'id', (int) $row['id'] ) : null )
                ?? $existing->firstWhere( 'value', $value )
                ?? new ProductAttributeValue( [ 'product_attribute_id' => $attribute->id ] );

            $model->fill( [
                'product_attribute_id' => $attribute->id,
                'value'                => $value,
                'label'                => $label,
                'swatch'               => $this->blankToNull( $row['swatch'] ?? null ),
                'position'             => $index,
            ] )->save();

            $keep[] = $model->id;
        }

        $removed = $existing->pluck( 'id' )->diff( $keep )->all();

        if ( [] !== $removed ) {
            // Deleting the value cascades its option-value rows.
            ProductAttributeValue::query()->whereIn( 'id', $removed )->delete();
        }
    }

    /**
     * The product's variation attributes (with values), in order.
     *
     * @since 1.0.0
     *
     * @param  Product  $product  Product.
     *
     * @return Collection<int, ProductAttribute>
     */
    protected function variationAttributes( Product $product ): Collection
    {
        return $product->productAttributes()
            ->where( 'is_variation', true )
            ->with( [ 'values' => static fn ( $query ) => $query->orderBy( 'position' )->orderBy( 'id' ) ] )
            ->orderBy( 'position' )
            ->orderBy( 'id' )
            ->get();
    }

    /**
     * Combination keys of the product's existing variants.
     *
     * @since 1.0.0
     *
     * @param  Product   $product    Product.
     * @param  int|null  $ignoreId   Variant to leave out.
     *
     * @return array<int, string>
     */
    protected function existingCombinations( Product $product, ?int $ignoreId = null ): array
    {
        return ProductVariantOptionValue::query()
            ->whereIn( 'product_variant_id', $product->variants()->when( null !== $ignoreId, static fn ( $query ) => $query->whereKeyNot( $ignoreId ) )->select( 'id' ) )
            ->get()
            ->groupBy( 'product_variant_id' )
            ->map( fn ( $rows ): string => $this->combinationKey( $rows->pluck( 'product_attribute_value_id', 'product_attribute_id' )->all() ) )
            ->values()
            ->all();
    }

    /**
     * Stable key for an `attribute_id => value_id` map.
     *
     * @since 1.0.0
     *
     * @param  array<int, int>  $map  Option map.
     *
     * @return string
     */
    protected function combinationKey( array $map ): string
    {
        ksort( $map );

        return implode( ',', array_map( static fn ( $attribute, $value ): string => $attribute . ':' . $value, array_keys( $map ), $map ) );
    }

    /**
     * Normalizes and validates a price row.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $row    Raw row.
     * @param  string|null           $field  Error field prefix.
     *
     * @throws ProductWriteException When the row is invalid.
     *
     * @return array{currency: string, price_amount: int, compare_at_amount: int|null, cost_amount: int|null, starts_at: Carbon|null, ends_at: Carbon|null}
     */
    protected function normalizePrice( array $row, ?string $field ): array
    {
        $prefix = null === $field ? '' : "{$field}.";

        try {
            $currency = CurrencyVO::of( (string) ( $row['currency'] ?? '' ) )->code();
        } catch ( Throwable ) {
            throw ProductWriteException::field( "{$prefix}currency", 'invalid-currency', __( 'Use a three-letter currency code.' ) );
        }

        $amounts = [];

        foreach ( [ 'price_amount', 'compare_at_amount', 'cost_amount' ] as $column ) {
            $raw = $row[ $column ] ?? null;

            if ( null === $raw || '' === $raw ) {
                if ( 'price_amount' === $column ) {
                    throw ProductWriteException::field( "{$prefix}price_amount", 'required', __( 'Enter a price.' ) );
                }

                $amounts[ $column ] = null;
                continue;
            }

            if ( ! is_int( $raw ) && ! ( is_string( $raw ) && 1 === preg_match( '/^\d+$/', $raw ) ) ) {
                throw ProductWriteException::field( "{$prefix}{$column}", 'invalid-amount', __( 'Amounts are whole numbers of the currency\'s smallest unit and can\'t be negative.' ) );
            }

            if ( (int) $raw < 0 ) {
                throw ProductWriteException::field( "{$prefix}{$column}", 'invalid-amount', __( 'Amounts are whole numbers of the currency\'s smallest unit and can\'t be negative.' ) );
            }

            $amounts[ $column ] = (int) $raw;
        }

        $starts = $this->parseDate( $row['starts_at'] ?? null, "{$prefix}starts_at" );
        $ends   = $this->parseDate( $row['ends_at'] ?? null, "{$prefix}ends_at" );

        if ( null !== $starts && null !== $ends && $ends->lessThanOrEqualTo( $starts ) ) {
            throw ProductWriteException::field( "{$prefix}ends_at", 'invalid-window', __( 'The end must be after the start.' ) );
        }

        return [
            'currency'          => $currency,
            'price_amount'      => $amounts['price_amount'],
            'compare_at_amount' => $amounts['compare_at_amount'],
            'cost_amount'       => $amounts['cost_amount'],
            'starts_at'         => $starts,
            'ends_at'           => $ends,
        ];
    }

    /**
     * Identity of a price row: currency plus schedule window.
     *
     * @since 1.0.0
     *
     * @param  string                   $currency  Currency code.
     * @param  DateTimeInterface|null  $starts    Window start.
     * @param  DateTimeInterface|null  $ends      Window end.
     *
     * @return string
     */
    protected function windowKey( string $currency, ?DateTimeInterface $starts, ?DateTimeInterface $ends ): string
    {
        return $currency . '|' . ( $starts?->getTimestamp() ?? '' ) . '|' . ( $ends?->getTimestamp() ?? '' );
    }

    /**
     * Normalizes and validates a gallery image row.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $row    Raw row.
     * @param  string|null           $field  Error field prefix.
     *
     * @throws ProductWriteException When the row has no source or a bad URL.
     *
     * @return array<string, mixed>
     */
    protected function normalizeImage( array $row, ?string $field ): array
    {
        $prefix  = null === $field ? '' : "{$field}.";
        $mediaId = isset( $row['media_id'] ) && '' !== $row['media_id'] ? (int) $row['media_id'] : null;
        $url     = $this->blankToNull( $row['image_url'] ?? null );

        if ( null === $mediaId && null === $url ) {
            throw ProductWriteException::field( "{$prefix}image_url", 'image-source', __( 'Choose an image from the media library or enter its URL.' ) );
        }

        if ( null !== $url && ! $this->isHttpUrl( $url ) ) {
            throw ProductWriteException::field( "{$prefix}image_url", 'invalid-url', __( 'Use an http or https image URL.' ) );
        }

        $clean = [
            'media_id'  => $mediaId,
            'image_url' => null === $mediaId ? $url : null,
            'alt_text'  => $this->blankToNull( isset( $row['alt_text'] ) ? Str::limit( strip_tags( (string) $row['alt_text'] ), 255, '' ) : null ),
        ];

        if ( array_key_exists( 'position', $row ) ) {
            $clean['position'] = max( 0, (int) $row['position'] );
        }

        return $clean;
    }

    /**
     * Writes `position` from an ordered id list.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query  Rows that may be ordered.
     * @param  array<int, int>                                                              $ids    Ids, first to last.
     * @param  string                                                                       $field  Error field.
     *
     * @throws ProductWriteException When an id is not in `$query`.
     *
     * @return void
     */
    protected function reorder( $query, array $ids, string $field ): void
    {
        $ids   = array_values( array_unique( array_map( 'intval', $ids ) ) );
        $known = ( clone $query )->pluck( 'id' )->map( static fn ( $id ): int => (int) $id )->all();

        if ( [] !== array_diff( $ids, $known ) ) {
            throw ProductWriteException::field( $field, 'unknown-id', __( 'The list includes an item that doesn\'t belong here.' ) );
        }

        DB::transaction( function () use ( $query, $ids, $known ): void {
            $position = 0;

            foreach ( array_merge( $ids, array_values( array_diff( $known, $ids ) ) ) as $id ) {
                ( clone $query )->whereKey( $id )->update( [ 'position' => $position++ ] );
            }
        } );
    }

    /**
     * Applies a pivot change.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Illuminate\Database\Eloquent\Model, Product>  $relation  Pivot relation.
     * @param  array<int, int>                                                                                       $ids       Ids.
     * @param  string                                                                                                $mode      `sync`, `attach`, or `detach`.
     *
     * @return void
     */
    protected function applyPivot( $relation, array $ids, string $mode ): void
    {
        match ( $mode ) {
            'attach' => $relation->syncWithoutDetaching( $ids ),
            'detach' => $relation->detach( $ids ),
            default  => $relation->sync( $ids ),
        };
    }

    /**
     * Validates that every id exists.
     *
     * @since 1.0.0
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model  Model.
     * @param  array<int, mixed>                                  $ids    Ids.
     * @param  string                                             $field  Error field.
     *
     * @throws ProductWriteException When one doesn't.
     *
     * @return array<int, int>
     */
    protected function existingIds( string $model, array $ids, string $field ): array
    {
        $ids = array_values( array_unique( array_map( 'intval', array_filter( $ids, static fn ( $id ): bool => is_numeric( $id ) ) ) ) );

        if ( count( $ids ) !== $model::query()->whereKey( $ids )->count() ) {
            throw ProductWriteException::field( $field, 'unknown-id', __( 'One of the chosen items no longer exists.' ) );
        }

        return $ids;
    }

    /**
     * Whether `$target` is `$productId` or one of its members, at any depth.
     *
     * @since 1.0.0
     *
     * @param  int  $productId  Where to start.
     * @param  int  $target     Product to look for.
     *
     * @return bool
     */
    protected function contains( int $productId, int $target ): bool
    {
        $visited  = [];
        $frontier = [ $productId ];

        while ( [] !== $frontier ) {
            if ( in_array( $target, $frontier, true ) ) {
                return true;
            }

            $visited  = array_merge( $visited, $frontier );
            $frontier = ProductChild::query()
                ->whereIn( 'parent_product_id', $frontier )
                ->pluck( 'child_product_id' )
                ->map( static fn ( $id ): int => (int) $id )
                ->reject( static fn ( int $id ): bool => in_array( $id, $visited, true ) )
                ->unique()
                ->values()
                ->all();
        }

        return false;
    }

    /**
     * Deletes the price and stock rows of the given owners.
     *
     * @since 1.0.0
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $class  Owner class.
     * @param  array<int, int>                                    $ids    Owner ids.
     *
     * @return void
     */
    protected function deletePolymorphicRows( string $class, array $ids ): void
    {
        if ( [] === $ids ) {
            return;
        }

        $morph = ( new $class() )->getMorphClass();

        ProductPrice::query()->where( 'priceable_type', $morph )->whereIn( 'priceable_id', $ids )->delete();
        InventoryItem::query()->where( 'stockable_type', $morph )->whereIn( 'stockable_id', $ids )->delete();
    }

    /**
     * Whether a product other than `$ignoreId` uses `$slug`.
     *
     * @since 1.0.0
     *
     * @param  string    $slug      Slug.
     * @param  int|null  $ignoreId  Product to ignore.
     *
     * @return bool
     */
    protected function slugTaken( string $slug, ?int $ignoreId ): bool
    {
        return Product::query()
            ->where( 'slug', $slug )
            ->when( null !== $ignoreId, static fn ( $query ) => $query->whereKeyNot( $ignoreId ) )
            ->exists();
    }

    /**
     * Whether any product or variant other than the ignored ones uses `$sku`.
     *
     * @since 1.0.0
     *
     * @param  string    $sku               SKU.
     * @param  int|null  $ignoreProductId   Product to ignore.
     * @param  int|null  $ignoreVariantId   Variant to ignore.
     *
     * @return bool
     */
    protected function skuTaken( string $sku, ?int $ignoreProductId, ?int $ignoreVariantId ): bool
    {
        $product = Product::query()
            ->where( 'sku', $sku )
            ->when( null !== $ignoreProductId, static fn ( $query ) => $query->whereKeyNot( $ignoreProductId ) )
            ->exists();

        return $product || ProductVariant::query()
            ->where( 'sku', $sku )
            ->when( null !== $ignoreVariantId, static fn ( $query ) => $query->whereKeyNot( $ignoreVariantId ) )
            ->exists();
    }

    /**
     * Refuses a weight or dimension unit outside {@see self::WEIGHT_UNITS}
     * and {@see self::DIMENSION_UNITS}. The columns are plain strings, so
     * this is the only check in-process callers get.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $values  Normalized product or variant values.
     *
     * @throws ProductWriteException When a unit isn't allowed.
     *
     * @return void
     */
    protected function assertUnits( array $values ): void
    {
        if ( null !== ( $values['weight_unit'] ?? null ) && ! in_array( $values['weight_unit'], self::WEIGHT_UNITS, true ) ) {
            throw ProductWriteException::field( 'weight_unit', 'invalid-weight-unit', __( 'Choose a weight unit: :units.', [ 'units' => implode( ', ', self::WEIGHT_UNITS ) ] ) );
        }

        if ( null !== ( $values['dim_unit'] ?? null ) && ! in_array( $values['dim_unit'], self::DIMENSION_UNITS, true ) ) {
            throw ProductWriteException::field( 'dim_unit', 'invalid-dimension-unit', __( 'Choose a dimension unit: :units.', [ 'units' => implode( ', ', self::DIMENSION_UNITS ) ] ) );
        }
    }

    /**
     * Cleans rich text with the security package's `kses()` in htmLawed's
     * safe mode ({@see self::KSES_CONFIG}), or strips tags without it.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Raw value.
     *
     * @return string|null
     */
    protected function sanitizeHtml( mixed $value ): ?string
    {
        $value = $this->blankToNull( $value );

        if ( null === $value ) {
            return null;
        }

        return app()->bound( 'security' ) ? trim( app( 'security' )->kses( $value, self::KSES_CONFIG ) ) : strip_tags( $value );
    }

    /**
     * Parses an optional date.
     *
     * @since 1.0.0
     *
     * @param  mixed   $value  Raw value.
     * @param  string  $field  Error field.
     *
     * @throws ProductWriteException When it isn't a date.
     *
     * @return Carbon|null
     */
    protected function parseDate( mixed $value, string $field ): ?Carbon
    {
        if ( null === $value || '' === $value ) {
            return null;
        }

        try {
            return Carbon::parse( $value )->setTimezone( (string) config( 'app.timezone', 'UTC' ) );
        } catch ( Throwable ) {
            throw ProductWriteException::field( $field, 'invalid-date', __( 'Enter a valid date and time.' ) );
        }
    }

    /**
     * Whether `$url` is an absolute http(s) URL.
     *
     * @since 1.0.0
     *
     * @param  string  $url  URL.
     *
     * @return bool
     */
    protected function isHttpUrl( string $url ): bool
    {
        return false !== filter_var( $url, FILTER_VALIDATE_URL ) && in_array( strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ), [ 'http', 'https' ], true );
    }

    /**
     * Trims strings and turns blanks into null.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Raw value.
     *
     * @return mixed
     */
    protected function blankToNull( mixed $value): mixed
    {
        if ( is_string( $value ) ) {
            $value = trim( $value );

            return '' === $value ? null : $value;
        }

        return $value;
    }
}
