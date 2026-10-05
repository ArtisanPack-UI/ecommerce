<?php

/**
 * DigitalFile model.
 *
 * A deliverable file attached to a product (or one of its variants).
 * Files live on a filesystem disk (`disk` + `path`) or in the media library
 * (`media_id`, resolved when `artisanpack-ui/media-library` is installed).
 * Streaming-only files are never handed out through the download endpoint
 * — only piped through the byte-range stream endpoint. Engine spec §3.27.
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

use ArtisanPackUI\Ecommerce\Database\Factories\DigitalFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DigitalFile Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                                 $id
 * @property int|null                                                            $product_id
 * @property int|null                                                            $product_variant_id
 * @property int|null                                                            $media_id
 * @property string|null                                                         $disk
 * @property string|null                                                         $path
 * @property string                                                              $label
 * @property string|null                                                         $version
 * @property bool                                                                $is_streaming_only
 * @property string|null                                                         $checksum_sha256
 * @property Carbon|null                                                         $created_at
 * @property Carbon|null                                                         $updated_at
 * @property Product|null                                                        $product
 * @property ProductVariant|null                                                 $variant
 * @property \Illuminate\Database\Eloquent\Collection<int, DigitalDownload>      $downloads
 */
class DigitalFile extends Model
{
    use HasFactory;

    /**
     * Media-library model used to resolve `media_id` files.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const MEDIA_MODEL = 'ArtisanPackUI\\MediaLibrary\\Models\\Media';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_digital_files';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'product_variant_id',
        'media_id',
        'disk',
        'path',
        'label',
        'version',
        'is_streaming_only',
        'checksum_sha256',
        'archived_at',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_streaming_only' => false,
    ];

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo( Product::class );
    }

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo( ProductVariant::class, 'product_variant_id' );
    }

    /**
     * Download entitlements issued for this file.
     *
     * @since 1.0.0
     *
     * @return HasMany<DigitalDownload, $this>
     */
    public function downloads(): HasMany
    {
        return $this->hasMany( DigitalDownload::class );
    }

    /**
     * Where the file's bytes live: `[ disk, path ]`, or null when the file
     * points at a media-library item and that package isn't installed.
     *
     * @since 1.0.0
     *
     * @return array{0: string, 1: string}|null
     */
    public function storageLocation(): ?array
    {
        if ( null !== $this->path && '' !== $this->path ) {
            return [ (string) ( $this->disk ?? config( 'artisanpack.ecommerce.digital.disk', 'local' ) ), $this->path ];
        }

        $mediaModel = self::MEDIA_MODEL;

        if ( null === $this->media_id || ! class_exists( $mediaModel ) ) {
            return null;
        }

        $media = $mediaModel::query()->find( $this->media_id );

        if ( null === $media || ! is_string( $media->file_path ) || '' === $media->file_path ) {
            return null;
        }

        return [ (string) ( $media->disk ?? 'public' ), $media->file_path ];
    }

    /**
     * The name the customer's browser saves the file as.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function downloadName(): string
    {
        $location  = $this->storageLocation();
        $extension = null === $location ? '' : pathinfo( $location[1], PATHINFO_EXTENSION );
        $base      = trim( (string) preg_replace( '/[^A-Za-z0-9._ -]+/', '', $this->label ) );
        $base      = '' === $base ? 'download' : $base;

        return '' === $extension || str_ends_with( strtolower( $base ), '.' . strtolower( $extension ) )
            ? $base
            : $base . '.' . $extension;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id'         => 'integer',
            'product_variant_id' => 'integer',
            'media_id'           => 'integer',
            'is_streaming_only'  => 'boolean',
            'archived_at'        => 'datetime',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return DigitalFileFactory
     */
    protected static function newFactory(): DigitalFileFactory
    {
        return DigitalFileFactory::new();
    }
}
