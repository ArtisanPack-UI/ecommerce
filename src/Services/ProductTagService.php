<?php

/**
 * ProductTagService.
 *
 * Writes for product tags (engine spec §3.9): create, rename, delete, and
 * merge one tag into another (its products move to the target, then it is
 * deleted). Slugs are unique and filled from the name when blank.
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
use ArtisanPackUI\Ecommerce\Models\ProductTag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProductTagService
{
    /**
     * Creates a tag.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $data  `name`, optional `slug`.
     *
     * @throws ProductWriteException When the name is blank or the slug is taken.
     *
     * @return ProductTag
     */
    public function create( array $data ): ProductTag
    {
        $tag = new ProductTag();
        $this->fill( $tag, $data );
        $tag->save();

        return $tag;
    }

    /**
     * Finds a tag by name (case-insensitive slug match) or creates it.
     *
     * @since 1.0.0
     *
     * @param  string  $name  Tag name.
     *
     * @throws ProductWriteException When the name is blank.
     *
     * @return ProductTag
     */
    public function findOrCreate( string $name ): ProductTag
    {
        $slug = Str::slug( $name );

        if ( '' !== $slug ) {
            $existing = ProductTag::query()->where( 'slug', $slug )->first();

            if ( null !== $existing ) {
                return $existing;
            }
        }

        return $this->create( [ 'name' => $name ] );
    }

    /**
     * Renames a tag or changes its slug.
     *
     * @since 1.0.0
     *
     * @param  ProductTag            $tag   Tag.
     * @param  array<string, mixed>  $data  Changed data.
     *
     * @throws ProductWriteException When the name is blank or the slug is taken.
     *
     * @return ProductTag
     */
    public function update( ProductTag $tag, array $data ): ProductTag
    {
        $this->fill( $tag, $data );
        $tag->save();

        return $tag;
    }

    /**
     * Deletes a tag (its product links go with it).
     *
     * @since 1.0.0
     *
     * @param  ProductTag  $tag  Tag.
     *
     * @return void
     */
    public function delete( ProductTag $tag ): void
    {
        DB::transaction( static function () use ( $tag ): void {
            $tag->products()->detach();
            $tag->delete();
        } );
    }

    /**
     * Moves every product from `$source` to `$target`, then deletes `$source`.
     *
     * @since 1.0.0
     *
     * @param  ProductTag  $source  Tag to merge away.
     * @param  ProductTag  $target  Tag to keep.
     *
     * @throws ProductWriteException When both are the same tag.
     *
     * @return ProductTag The target.
     */
    public function merge( ProductTag $source, ProductTag $target ): ProductTag
    {
        if ( $source->is( $target ) ) {
            throw ProductWriteException::field( 'target_id', 'merge-self', __( 'Choose a different tag to merge into.' ) );
        }

        return DB::transaction( function () use ( $source, $target ): ProductTag {
            $target->products()->syncWithoutDetaching( $source->products()->pluck( 'products.id' )->all() );
            $this->delete( $source );

            return $target;
        } );
    }

    /**
     * Fills and validates a tag.
     *
     * @since 1.0.0
     *
     * @param  ProductTag            $tag   Tag.
     * @param  array<string, mixed>  $data  Data.
     *
     * @throws ProductWriteException When the name is blank or the slug is taken.
     *
     * @return void
     */
    protected function fill( ProductTag $tag, array $data ): void
    {
        $values = array_intersect_key( $data, array_flip( [ 'name', 'slug' ] ) );

        if ( array_key_exists( 'name', $values ) || ! $tag->exists ) {
            $values['name'] = Str::limit( trim( strip_tags( (string) ( $values['name'] ?? '' ) ) ), 120, '' );

            if ( '' === $values['name'] ) {
                throw ProductWriteException::field( 'name', 'required', __( 'A tag needs a name.' ) );
            }
        }

        if ( ! $tag->exists && empty( $values['slug'] ) ) {
            $values['slug'] = $this->uniqueSlug( (string) $values['name'] );
        } elseif ( array_key_exists( 'slug', $values ) ) {
            $values['slug'] = Str::limit( Str::slug( (string) $values['slug'] ), 120, '' );

            if ( '' === $values['slug'] ) {
                throw ProductWriteException::field( 'slug', 'required', __( 'The slug can\'t be empty.' ) );
            }

            $taken = ProductTag::query()
                ->where( 'slug', $values['slug'] )
                ->when( $tag->exists, static fn ( $query ) => $query->whereKeyNot( $tag->id ) )
                ->exists();

            if ( $taken ) {
                throw ProductWriteException::field( 'slug', 'slug-taken', __( 'Another tag already uses this slug.' ) );
            }
        }

        $tag->fill( $values );
    }

    /**
     * A slug for `$name` no tag uses.
     *
     * @since 1.0.0
     *
     * @param  string  $name  Tag name.
     *
     * @return string
     */
    protected function uniqueSlug( string $name ): string
    {
        $base = Str::limit( Str::slug( $name ), 110, '' );
        $base = '' === $base ? 'tag' : $base;
        $slug = $base;
        $n    = 2;

        while ( ProductTag::query()->where( 'slug', $slug )->exists() ) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }
}
