<?php

/**
 * InboundWebhookDelivery model.
 *
 * One row per inbound provider webhook the engine receives, verified or
 * not. Powers the audit / replay path off {@see \ArtisanPackUI\Ecommerce\Http\Controllers\WebhookController}.
 *
 * Idempotent dispatch is still gated by {@see IdempotencyRecord} — this
 * table's uniqueness is intentionally soft (index, not constraint) so a
 * rejected/duplicate delivery is still ledgered rather than silently
 * dropped by a unique-violation.
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
use Illuminate\Support\Carbon;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                       $id
 * @property string                    $provider
 * @property string|null               $event_id
 * @property string|null               $event_type
 * @property bool                      $verified
 * @property bool                      $duplicate
 * @property string|null               $error_code
 * @property string                    $payload_hash
 * @property string                    $payload
 * @property array<string,mixed>|null  $parsed
 * @property int                       $response_status
 * @property string|null               $correlation_id
 * @property Carbon                    $received_at
 * @property Carbon|null               $created_at
 * @property Carbon|null               $updated_at
 */
class InboundWebhookDelivery extends Model
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'inbound_webhook_deliveries';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'verified',
        'duplicate',
        'error_code',
        'payload_hash',
        'payload',
        'parsed',
        'response_status',
        'correlation_id',
        'received_at',
    ];

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified'        => 'boolean',
            'duplicate'       => 'boolean',
            'parsed'          => 'array',
            'response_status' => 'integer',
            'received_at'     => 'datetime',
        ];
    }
}
