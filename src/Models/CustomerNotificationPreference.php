<?php

/**
 * CustomerNotificationPreference model.
 *
 * Whether a customer wants notifications of one `category` on one
 * `channel`. `transactional` rows are kept for auditability only —
 * transactional notifications always send. Engine spec §3.30, parent
 * plan §14.3.
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

use ArtisanPackUI\Ecommerce\Database\Factories\CustomerNotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CustomerNotificationPreference Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int          $id
 * @property int          $customer_id
 * @property string       $channel
 * @property string       $category
 * @property bool         $is_enabled
 * @property Carbon|null  $updated_at
 * @property Customer     $customer
 */
class CustomerNotificationPreference extends Model
{
    use HasFactory;

    /**
     * Always sent; opting out is recorded but ignored.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CATEGORY_TRANSACTIONAL = 'transactional';

    /**
     * The categories the engine ships with (engine spec §3.30).
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const CATEGORIES = [
        self::CATEGORY_TRANSACTIONAL,
        'shipping-updates',
        'review-requests',
        'marketing',
        'abandoned-cart',
        'back-in-stock',
    ];

    /**
     * Rows only track `updated_at`.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public const CREATED_AT = null;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_customer_notification_preferences';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'channel',
        'category',
        'is_enabled',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_enabled' => true,
    ];

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo( Customer::class );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'is_enabled'  => 'boolean',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return CustomerNotificationPreferenceFactory
     */
    protected static function newFactory(): CustomerNotificationPreferenceFactory
    {
        return CustomerNotificationPreferenceFactory::new();
    }
}
