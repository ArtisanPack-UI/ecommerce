<?php

/**
 * DigitalDownloadEvent model.
 *
 * One hit on the download or stream endpoint for a {@see DigitalDownload}:
 * `download`, `stream`, or `forbidden` (expired / exhausted / streaming-only
 * refusal). Append-only audit rows. Engine spec §3.27.
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

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DigitalDownloadEvent Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int              $id
 * @property int              $digital_download_id
 * @property string|null      $ip_address
 * @property string|null      $user_agent
 * @property string           $event_type
 * @property Carbon|null      $created_at
 * @property DigitalDownload  $download
 */
class DigitalDownloadEvent extends Model
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const TYPE_DOWNLOAD = 'download';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const TYPE_STREAM = 'stream';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const TYPE_FORBIDDEN = 'forbidden';

    /**
     * Rows are insert-only: no `updated_at`.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_digital_download_events';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'digital_download_id',
        'ip_address',
        'user_agent',
        'event_type',
    ];

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<DigitalDownload, $this>
     */
    public function download(): BelongsTo
    {
        return $this->belongsTo( DigitalDownload::class, 'digital_download_id' );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'digital_download_id' => 'integer',
        ];
    }
}
