<?php

/**
 * WebhookSubscription model.
 *
 * An outbound webhook endpoint (engine spec §3.29). `events` lists the
 * wire event names the endpoint receives (`order.refunded`, …) — `*`
 * subscribes to every event. `secret` is encrypted at rest and hidden
 * from serialization; it is only ever revealed once, in the response to
 * the request that created the subscription.
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

use ArtisanPackUI\Ecommerce\Database\Factories\WebhookSubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                 $id
 * @property string              $name
 * @property string              $url
 * @property string              $secret
 * @property array<int, string>  $events
 * @property bool                $is_active
 * @property Carbon|null         $last_success_at
 * @property Carbon|null         $last_failure_at
 * @property int                 $consecutive_failures
 * @property Carbon|null         $created_at
 * @property Carbon|null         $updated_at
 */
class WebhookSubscription extends Model
{
    use HasFactory;

    /**
     * Wildcard event name subscribing to every event.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ALL_EVENTS = '*';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_webhook_subscriptions';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'url',
        'secret',
        'events',
        'is_active',
        'last_success_at',
        'last_failure_at',
        'consecutive_failures',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'secret',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active'            => true,
        'consecutive_failures' => 0,
    ];

    /**
     * Delivery ledger rows for this subscription.
     *
     * @since 1.0.0
     *
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany( WebhookDelivery::class, 'subscription_id' );
    }

    /**
     * Whether the subscription receives `$event`.
     *
     * @since 1.0.0
     *
     * @param  string  $event  Wire event name.
     *
     * @return bool
     */
    public function listensTo( string $event ): bool
    {
        $events = (array) $this->events;

        return in_array( self::ALL_EVENTS, $events, true ) || in_array( $event, $events, true );
    }

    /**
     * Active subscriptions.
     *
     * @since 1.0.0
     *
     * @param  Builder<WebhookSubscription>  $query  Query.
     *
     * @return void
     */
    public function scopeActive( Builder $query ): void
    {
        $query->where( 'is_active', true );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret'               => 'encrypted',
            'events'               => 'array',
            'is_active'            => 'boolean',
            'last_success_at'      => 'datetime',
            'last_failure_at'      => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return WebhookSubscriptionFactory
     */
    protected static function newFactory(): WebhookSubscriptionFactory
    {
        return WebhookSubscriptionFactory::new();
    }
}
