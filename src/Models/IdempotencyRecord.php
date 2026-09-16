<?php

/**
 * IdempotencyRecord model.
 *
 * Backs {@see \ArtisanPackUI\Ecommerce\Http\Middleware\IdempotencyMiddleware}:
 * one row per (actor_scope, endpoint_key, idempotency_key) tuple, storing the
 * request-body hash and the terminal response so replays return byte-for-byte.
 *
 * Engine spec §3.31 / §11.2.
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
 * IdempotencyRecord Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                 $id
 * @property string              $actor_scope
 * @property string              $endpoint_key
 * @property string              $idempotency_key
 * @property string              $request_hash
 * @property int|null            $response_status
 * @property array<string,mixed>|null $response_headers
 * @property string|null         $response_body
 * @property Carbon|null         $locked_at
 * @property Carbon              $expires_at
 * @property Carbon|null         $created_at
 * @property Carbon|null         $updated_at
 */
class IdempotencyRecord extends Model
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'idempotency_records';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'actor_scope',
        'endpoint_key',
        'idempotency_key',
        'request_hash',
        'response_status',
        'response_headers',
        'response_body',
        'locked_at',
        'expires_at',
    ];

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_status'  => 'integer',
            'response_headers' => 'array',
            'locked_at'        => 'datetime',
            'expires_at'       => 'datetime',
        ];
    }
}
