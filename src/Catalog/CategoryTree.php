<?php

/**
 * CategoryTree.
 *
 * Read side of product categories for storefronts (#171): the whole tree,
 * cached and rebuilt whenever a category changes, a category's descendants
 * (for "this category and everything under it" listings), and lookups by
 * id or slug.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Catalog;

use ArtisanPackUI\Ecommerce\Models\ProductCategory;
use Illuminate\Support\Facades\Cache;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CategoryTree
{
    /**
     * Cache key for the flattened tree.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CACHE_KEY = 'ecommerce.catalog.category_tree';

    /**
     * Every category as nested nodes (`children` lists), roots first, each
     * level ordered by position then name.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(): array
    {
        $nodes    = $this->nodes();
        $children = [];

        foreach ( $nodes as $node ) {
            $children[ $node['parent_id'] ?? 0 ][] = $node['id'];
        }

        $build = static function ( int $parent ) use ( &$build, $nodes, $children ): array {
            return array_map( static fn ( int $id ): array => $nodes[ $id ] + [ 'children' => $build( $id ) ], $children[ $parent ] ?? [] );
        };

        return $build( 0 );
    }

    /**
     * `$id` and the ids of every category under it.
     *
     * @since 1.0.0
     *
     * @param  int  $id  Category id.
     *
     * @return array<int, int>
     */
    public function withDescendants( int $id ): array
    {
        $nodes    = $this->nodes();
        $children = [];

        foreach ( $nodes as $node ) {
            $children[ $node['parent_id'] ?? 0 ][] = $node['id'];
        }

        $ids   = [];
        $queue = isset( $nodes[ $id ] ) ? [ $id ] : [];

        while ( [] !== $queue ) {
            $current = array_shift( $queue );

            // A cycle in bad data can't loop forever.
            if ( in_array( $current, $ids, true ) ) {
                continue;
            }

            $ids[] = $current;
            $queue = [ ...$queue, ...( $children[ $current ] ?? [] ) ];
        }

        return $ids;
    }

    /**
     * A category by id, or by slug when `$key` isn't numeric.
     *
     * @since 1.0.0
     *
     * @param  int|string  $key  Id or slug.
     *
     * @return ProductCategory|null
     */
    public function find( int|string $key ): ?ProductCategory
    {
        return is_int( $key ) || ctype_digit( (string) $key )
            ? ProductCategory::query()->find( (int) $key )
            : $this->bySlug( (string) $key );
    }

    /**
     * A category by slug.
     *
     * @since 1.0.0
     *
     * @param  string  $slug  Slug.
     *
     * @return ProductCategory|null
     */
    public function bySlug( string $slug ): ?ProductCategory
    {
        return ProductCategory::query()->where( 'slug', $slug )->first();
    }

    /**
     * Drops the cached tree (called when a category changes).
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function flush(): void
    {
        Cache::forget( self::CACHE_KEY );
    }

    /**
     * Every category as a flat node, keyed by id, in display order.
     *
     * @since 1.0.0
     *
     * @return array<int, array{id: int, parent_id: int|null, name: string, slug: string, description: string|null, image_media_id: int|null, icon: string|null, position: int}>
     */
    protected function nodes(): array
    {
        return Cache::rememberForever( self::CACHE_KEY, static fn (): array => ProductCategory::query()
            ->orderBy( 'position' )
            ->orderBy( 'name' )
            ->get( [ 'id', 'parent_id', 'name', 'slug', 'description', 'image_media_id', 'icon', 'position' ] )
            ->mapWithKeys( static fn ( ProductCategory $category ): array => [ (int) $category->id => [
                'id'             => (int) $category->id,
                'parent_id'      => null === $category->parent_id ? null : (int) $category->parent_id,
                'name'           => (string) $category->name,
                'slug'           => (string) $category->slug,
                'description'    => $category->description,
                'image_media_id' => null === $category->image_media_id ? null : (int) $category->image_media_id,
                'icon'           => $category->icon,
                'position'       => (int) $category->position,
            ] ] )
            ->all() );
    }
}
