<?php

/**
 * DigitalDownload factory.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Database\Factories;

use ArtisanPackUI\Ecommerce\Models\DigitalDownload;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DigitalDownload>
 *
 * @since 1.0.0
 */
class DigitalDownloadFactory extends Factory
{
    /**
     * @var class-string<DigitalDownload>
     */
    protected $model = DigitalDownload::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_item_id'       => OrderItem::factory(),
            'digital_file_id'     => DigitalFile::factory(),
            'token'               => DigitalDownload::hashToken( Str::random( 64 ) ),
            'downloads_remaining' => 5,
            'expires_at'          => now()->addDays( 30 ),
            'first_downloaded_at' => null,
            'last_downloaded_at'  => null,
            'download_count'      => 0,
        ];
    }

    /**
     * Uses `$plainToken` as the download token (stores its hash).
     *
     * @since 1.0.0
     *
     * @param  string  $plainToken  Plain token.
     *
     * @return static
     */
    public function withToken( string $plainToken ): static
    {
        return $this->state( fn (): array => [ 'token' => DigitalDownload::hashToken( $plainToken ) ] );
    }
}
