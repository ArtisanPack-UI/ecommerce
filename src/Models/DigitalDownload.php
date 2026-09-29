<?php

/**
 * DigitalDownload model.
 *
 * A customer's entitlement to download one {@see DigitalFile} bought on
 * one {@see OrderItem}. Access is by an opaque, server-issued token: the
 * `token` column stores only its sha256, so a leaked database never yields
 * working links. The plain token exists exactly once — on the instance
 * returned by `DigitalDownloadService::issue()` ({@see self::$plainToken})
 * — and goes straight into the customer's download link. Engine spec §3.27.
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

use ArtisanPackUI\Ecommerce\Database\Factories\DigitalDownloadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DigitalDownload Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                                     $id
 * @property int                                                                     $order_item_id
 * @property int                                                                     $digital_file_id
 * @property string                                                                  $token
 * @property int|null                                                                $downloads_remaining
 * @property Carbon|null                                                             $expires_at
 * @property Carbon|null                                                             $first_downloaded_at
 * @property Carbon|null                                                             $last_downloaded_at
 * @property int                                                                     $download_count
 * @property Carbon|null                                                             $created_at
 * @property Carbon|null                                                             $updated_at
 * @property OrderItem                                                               $orderItem
 * @property DigitalFile                                                             $file
 * @property \Illuminate\Database\Eloquent\Collection<int, DigitalDownloadEvent>     $events
 */
class DigitalDownload extends Model
{
    use HasFactory;

    /**
     * The plain token, set only on the instance that issued it.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $plainToken = null;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'digital_downloads';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_item_id',
        'digital_file_id',
        'token',
        'downloads_remaining',
        'expires_at',
        'first_downloaded_at',
        'last_downloaded_at',
        'download_count',
    ];

    /**
     * The token hash never leaves the server.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'download_count' => 0,
    ];

    /**
     * Hashes a plain token for storage / lookup.
     *
     * @since 1.0.0
     *
     * @param  string  $plainToken  Plain token.
     *
     * @return string
     */
    public static function hashToken( string $plainToken ): string
    {
        return hash( 'sha256', $plainToken );
    }

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo( OrderItem::class );
    }

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<DigitalFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo( DigitalFile::class, 'digital_file_id' );
    }

    /**
     * @since 1.0.0
     *
     * @return HasMany<DigitalDownloadEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany( DigitalDownloadEvent::class );
    }

    /**
     * Whether the entitlement has passed its expiry.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return null !== $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Whether a download remains (null quota = unlimited).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function hasDownloadsRemaining(): bool
    {
        return null === $this->downloads_remaining || $this->downloads_remaining > 0;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_item_id'       => 'integer',
            'digital_file_id'     => 'integer',
            'downloads_remaining' => 'integer',
            'expires_at'          => 'datetime',
            'first_downloaded_at' => 'datetime',
            'last_downloaded_at'  => 'datetime',
            'download_count'      => 'integer',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return DigitalDownloadFactory
     */
    protected static function newFactory(): DigitalDownloadFactory
    {
        return DigitalDownloadFactory::new();
    }
}
