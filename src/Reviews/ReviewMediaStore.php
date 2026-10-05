<?php

/**
 * ReviewMediaStore.
 *
 * Stores a photo attached to a review (#181) through
 * artisanpack-ui/media-library and returns its media id. Without that
 * package, uploads are refused. Bind a subclass to store them elsewhere.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Reviews;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ReviewMediaStore
{
    /**
     * Whether uploads can be stored.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function available(): bool
    {
        return function_exists( 'apUploadMedia' );
    }

    /**
     * Stores `$file` and returns its media id.
     *
     * @since 1.0.0
     *
     * @param  UploadedFile  $file  Photo.
     *
     * @throws RuntimeException When media-library isn't installed.
     *
     * @return int
     */
    public function store( UploadedFile $file ): int
    {
        if ( ! $this->available() ) {
            throw new RuntimeException( 'Review photos need artisanpack-ui/media-library.' );
        }

        return (int) apUploadMedia( $file, [ 'title' => $file->getClientOriginalName() ] )->getKey();
    }
}
