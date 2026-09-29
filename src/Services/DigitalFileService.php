<?php

/**
 * DigitalFileService.
 *
 * Admin writes to `digital_files` (engine spec §9.9). Changing a file's
 * `version` fires `ap.ecommerce.digital.productUpdated` and
 * {@see DigitalProductUpdated} so buyers can be told a new version is out.
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

use ArtisanPackUI\Ecommerce\Events\DigitalProductUpdated;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use Illuminate\Support\Facades\Event;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DigitalFileService
{
    /**
     * Creates a digital file.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  Validated attributes.
     *
     * @return DigitalFile
     */
    public function create( array $attributes ): DigitalFile
    {
        return DigitalFile::query()->create( $attributes );
    }

    /**
     * Updates a digital file, announcing a new version when `version`
     * changes on a file attached to a product.
     *
     * @since 1.0.0
     *
     * @param  DigitalFile           $file        File.
     * @param  array<string, mixed>  $attributes  Validated attributes.
     *
     * @return DigitalFile
     */
    public function update( DigitalFile $file, array $attributes ): DigitalFile
    {
        $file->fill( $attributes );

        $versionChanged = $file->exists && $file->isDirty( 'version' ) && null !== $file->getOriginal( 'version' );

        $file->save();

        $product = $file->product ?? $file->variant?->product;

        if ( $versionChanged && null !== $product ) {
            doAction( 'ap.ecommerce.digital.productUpdated', $product, $file );
            Event::dispatch( new DigitalProductUpdated( $product, $file ) );
        }

        return $file;
    }
}
