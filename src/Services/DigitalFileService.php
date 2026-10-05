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
use ArtisanPackUI\Ecommerce\Exceptions\DigitalFileInUseException;
use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
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
        return DigitalFile::query()->create( $this->withArchiveFlag( $attributes ) );
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
        $file->fill( $this->withArchiveFlag( $attributes, $file ) );

        $versionChanged = $file->exists && $file->isDirty( 'version' ) && null !== $file->getOriginal( 'version' );

        $file->save();

        $product = $file->product ?? $file->variant?->product;

        if ( $versionChanged && null !== $product ) {
            doAction( 'ap.ecommerce.digital.productUpdated', $product, $file );
            Event::dispatch( new DigitalProductUpdated( $product, $file ) );
        }

        return $file;
    }

    /**
     * Deletes a file nobody holds an entitlement for. A file customers
     * already bought can't be deleted (their downloads would vanish);
     * archive it instead with `is_archived`.
     *
     * @since 1.0.0
     *
     * @param  DigitalFile  $file  File.
     *
     * @throws DigitalFileInUseException When download entitlements reference it.
     *
     * @return void
     */
    public function delete( DigitalFile $file ): void
    {
        if ( DigitalDownload::query()->where( 'digital_file_id', $file->id )->exists() ) {
            throw new DigitalFileInUseException(
                __( 'Customers have downloads for this file, so it cannot be deleted. Archive it instead.' ),
                [ 'digital_file_id' => $file->id ],
            );
        }

        $file->delete();
    }

    /**
     * Turns the `is_archived` input into `archived_at`, keeping the
     * original archive time when an archived file is saved again.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  Validated attributes.
     * @param  DigitalFile|null      $file        The file being updated.
     *
     * @return array<string, mixed>
     */
    protected function withArchiveFlag( array $attributes, ?DigitalFile $file = null ): array
    {
        if ( ! array_key_exists( 'is_archived', $attributes ) ) {
            return $attributes;
        }

        $archive = (bool) $attributes['is_archived'];
        unset( $attributes['is_archived'] );

        $attributes['archived_at'] = $archive ? ( $file?->archived_at ?? now() ) : null;

        return $attributes;
    }
}
