<?php

/**
 * ProductCategoryService.
 *
 * Writes for the category tree (engine spec §3.7): create, update, delete,
 * and reorder within a parent. Slugs are unique and filled from the name
 * when blank, and a category can't be moved under itself or one of its own
 * descendants.
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

use ArtisanPackUI\Ecommerce\Catalog\CategoryTree;
use ArtisanPackUI\Ecommerce\Exceptions\ProductWriteException;
use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProductCategoryService
{
    /**
     * Columns a write may set.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const COLUMNS = [ 'parent_id', 'name', 'slug', 'description', 'image_media_id', 'icon', 'position' ];

    /**
     * Creates a category.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $data  Category data.
     *
     * @throws ProductWriteException When a rule is broken.
     *
     * @return ProductCategory
     */
    public function create( array $data ): ProductCategory
    {
        $category = new ProductCategory();

        if ( ! array_key_exists( 'position', $data ) ) {
            $data['position'] = (int) ProductCategory::query()->where( 'parent_id', $data['parent_id'] ?? null )->max( 'position' ) + 1;
        }

        $this->fill( $category, $data );
        $category->save();

        return $category;
    }

    /**
     * Updates a category.
     *
     * @since 1.0.0
     *
     * @param  ProductCategory       $category  Category.
     * @param  array<string, mixed>  $data      Changed data.
     *
     * @throws ProductWriteException When a rule is broken.
     *
     * @return ProductCategory
     */
    public function update( ProductCategory $category, array $data ): ProductCategory
    {
        $this->fill( $category, $data );
        $category->save();

        return $category;
    }

    /**
     * Deletes a category. Its children move up to its parent and its
     * products are unlinked.
     *
     * @since 1.0.0
     *
     * @param  ProductCategory  $category  Category.
     *
     * @return void
     */
    public function delete( ProductCategory $category ): void
    {
        DB::transaction( static function () use ( $category ): void {
            ProductCategory::query()->where( 'parent_id', $category->id )->update( [ 'parent_id' => $category->parent_id ] );
            $category->products()->detach();
            $category->delete();
        } );
    }

    /**
     * Sets positions of the categories under one parent from an ordered id
     * list.
     *
     * @since 1.0.0
     *
     * @param  int|null         $parentId  Parent (null for top level).
     * @param  array<int, int>  $ids       Category ids, first to last.
     *
     * @throws ProductWriteException When an id isn't under that parent.
     *
     * @return Collection<int, ProductCategory>
     */
    public function reorder( ?int $parentId, array $ids ): Collection
    {
        $siblings = ProductCategory::query()->where( 'parent_id', $parentId )->orderBy( 'position' )->orderBy( 'id' )->pluck( 'id' )->map( static fn ( $id ): int => (int) $id )->all();
        $ids      = array_values( array_unique( array_map( 'intval', $ids ) ) );

        if ( [] !== array_diff( $ids, $siblings ) ) {
            throw ProductWriteException::field( 'ids', 'unknown-id', __( 'The list includes an item that doesn\'t belong here.' ) );
        }

        DB::transaction( static function () use ( $ids, $siblings ): void {
            foreach ( array_merge( $ids, array_values( array_diff( $siblings, $ids ) ) ) as $position => $id ) {
                ProductCategory::query()->whereKey( $id )->update( [ 'position' => $position ] );
            }
        } );

        // Bulk updates skip model events, so drop the cached tree here.
        CategoryTree::flush();

        return ProductCategory::query()->where( 'parent_id', $parentId )->orderBy( 'position' )->get();
    }

    /**
     * Fills and validates a category.
     *
     * @since 1.0.0
     *
     * @param  ProductCategory       $category  Category.
     * @param  array<string, mixed>  $data      Data.
     *
     * @throws ProductWriteException When a rule is broken.
     *
     * @return void
     */
    protected function fill( ProductCategory $category, array $data ): void
    {
        $values = array_intersect_key( $data, array_flip( self::COLUMNS ) );

        if ( array_key_exists( 'name', $values ) || ! $category->exists ) {
            $values['name'] = trim( strip_tags( (string) ( $values['name'] ?? '' ) ) );

            if ( '' === $values['name'] ) {
                throw ProductWriteException::field( 'name', 'required', __( 'A category needs a name.' ) );
            }
        }

        if ( ! $category->exists && empty( $values['slug'] ) ) {
            $values['slug'] = $this->uniqueSlug( (string) $values['name'], null );
        } elseif ( array_key_exists( 'slug', $values ) ) {
            $values['slug'] = Str::slug( (string) $values['slug'] );

            if ( '' === $values['slug'] ) {
                throw ProductWriteException::field( 'slug', 'required', __( 'The slug can\'t be empty.' ) );
            }

            if ( $this->slugTaken( $values['slug'], $category->exists ? (int) $category->id : null ) ) {
                throw ProductWriteException::field( 'slug', 'slug-taken', __( 'Another category already uses this slug.' ) );
            }
        }

        if ( array_key_exists( 'parent_id', $values ) ) {
            $parentId = null === $values['parent_id'] || '' === $values['parent_id'] ? null : (int) $values['parent_id'];

            if ( null !== $parentId && ! ProductCategory::query()->whereKey( $parentId )->exists() ) {
                throw ProductWriteException::field( 'parent_id', 'unknown-id', __( 'Choose an existing parent category.' ) );
            }

            if ( null !== $parentId && $category->exists && $this->isSelfOrDescendant( (int) $category->id, $parentId ) ) {
                throw ProductWriteException::field( 'parent_id', 'category-cycle', __( 'A category can\'t sit under itself or one of its subcategories.' ) );
            }

            $values['parent_id'] = $parentId;
        }

        if ( array_key_exists( 'description', $values ) ) {
            $description           = trim( (string) $values['description'] );
            $values['description'] = '' === $description ? null : ( app()->bound( 'security' ) ? trim( app( 'security' )->kses( $description, ProductService::KSES_CONFIG ) ) : strip_tags( $description ) );
        }

        if ( array_key_exists( 'icon', $values ) ) {
            $icon           = trim( (string) $values['icon'] );
            $values['icon'] = '' === $icon ? null : Str::limit( $icon, 80, '' );
        }

        if ( array_key_exists( 'position', $values ) ) {
            $values['position'] = max( 0, (int) $values['position'] );
        }

        $category->fill( $values );
    }

    /**
     * Whether `$candidate` is `$categoryId` or below it.
     *
     * @since 1.0.0
     *
     * @param  int  $categoryId  Category being moved.
     * @param  int  $candidate   Proposed parent.
     *
     * @return bool
     */
    protected function isSelfOrDescendant( int $categoryId, int $candidate ): bool
    {
        $seen = [];

        for ( $current = $candidate; null !== $current; ) {
            if ( $current === $categoryId ) {
                return true;
            }

            if ( isset( $seen[ $current ] ) ) {
                return false;
            }

            $seen[ $current ] = true;
            $parent           = ProductCategory::query()->whereKey( $current )->value( 'parent_id' );
            $current          = null === $parent ? null : (int) $parent;
        }

        return false;
    }

    /**
     * A slug no category (other than `$ignoreId`) uses.
     *
     * @since 1.0.0
     *
     * @param  string    $name      Source text.
     * @param  int|null  $ignoreId  Category to ignore.
     *
     * @return string
     */
    protected function uniqueSlug( string $name, ?int $ignoreId ): string
    {
        $base = Str::slug( $name );
        $base = '' === $base ? 'category' : Str::limit( $base, 240, '' );
        $slug = $base;
        $n    = 2;

        while ( $this->slugTaken( $slug, $ignoreId ) ) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }

    /**
     * @since 1.0.0
     *
     * @param  string    $slug      Slug.
     * @param  int|null  $ignoreId  Category to ignore.
     *
     * @return bool
     */
    protected function slugTaken( string $slug, ?int $ignoreId ): bool
    {
        return ProductCategory::query()
            ->where( 'slug', $slug )
            ->when( null !== $ignoreId, static fn ( $query ) => $query->whereKeyNot( $ignoreId ) )
            ->exists();
    }
}
