<?php

/**
 * WebhookDelivery model.
 *
 * One row per (subscription, event occurrence) in the outbound retry
 * ledger (engine spec §3.29 / §8.2). The row is append-mostly: the
 * delivery service updates `attempts`, `response_*`, `delivered_at`, and
 * `next_retry_at` as attempts happen. There is no `updated_at` column.
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

use ArtisanPackUI\Ecommerce\Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                   $id
 * @property int                   $subscription_id
 * @property string                $event
 * @property int|null              $order_id     Order the payload is about (see WebhookDispatcher::subjectIds()).
 * @property int|null              $customer_id  Customer the payload is about.
 * @property string                $payload_hash
 * @property array<string, mixed>  $payload
 * @property int|null              $response_status
 * @property string|null           $response_body
 * @property int                   $attempts
 * @property Carbon|null           $delivered_at
 * @property Carbon|null           $next_retry_at
 * @property Carbon|null           $created_at
 * @property WebhookSubscription   $subscription
 */
class WebhookDelivery extends Model
{
    use HasFactory;

    /**
     * No `updated_at` column (engine spec §3.29).
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
    protected $table = 'webhook_deliveries';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'subscription_id',
        'event',
        'order_id',
        'customer_id',
        'payload_hash',
        'payload',
        'response_status',
        'response_body',
        'attempts',
        'delivered_at',
        'next_retry_at',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'attempts' => 0,
    ];

    /**
     * The subscription this delivery targets.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<WebhookSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo( WebhookSubscription::class, 'subscription_id' );
    }

    /**
     * Undelivered rows whose retry time has come.
     *
     * @since 1.0.0
     *
     * @param  Builder<WebhookDelivery>  $query  Query.
     * @param  Carbon|null               $now    Reference time.
     *
     * @return void
     */
    public function scopeDue( Builder $query, ?Carbon $now = null ): void
    {
        $query->whereNull( 'delivered_at' )
            ->whereNotNull( 'next_retry_at' )
            ->where( 'next_retry_at', '<=', $now ?? Carbon::now() );
    }

    /**
     * Whether the delivery succeeded.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isDelivered(): bool
    {
        return null !== $this->delivered_at;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload'         => 'array',
            'order_id'        => 'integer',
            'customer_id'     => 'integer',
            'response_status' => 'integer',
            'attempts'        => 'integer',
            'delivered_at'    => 'datetime',
            'next_retry_at'   => 'datetime',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return WebhookDeliveryFactory
     */
    protected static function newFactory(): WebhookDeliveryFactory
    {
        return WebhookDeliveryFactory::new();
    }
}
