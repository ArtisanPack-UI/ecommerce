<?php

/**
 * CustomerClaimAttempt model.
 *
 * Rate-limit audit row: each attempt (successful or failed) a customer makes
 * to claim a prior guest order lands here. Engine spec §3.22 caps this at
 * 5 attempts per hour per customer.
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

use ArtisanPackUI\Ecommerce\Database\Factories\CustomerClaimAttemptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CustomerClaimAttempt Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int         $id
 * @property int         $customer_id
 * @property string|null $order_number
 * @property string|null $ip_address
 * @property bool        $was_success
 * @property Carbon|null $created_at
 * @property Customer    $customer
 */
class CustomerClaimAttempt extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'customer_claim_attempts';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'order_number',
        'ip_address',
        'was_success',
        'created_at',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'was_success' => false,
    ];

    /**
     * The owning customer.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo( Customer::class );
    }

    /**
     * Scope: rows made within the last `$minutes` minutes.
     *
     * @since 1.0.0
     *
     * @param  Builder<self>  $query
     * @param  int            $minutes
     *
     * @return Builder<self>
     */
    public function scopeWithinLastMinutes( Builder $query, int $minutes ): Builder
    {
        return $query->where( 'created_at', '>=', Carbon::now()->subMinutes( $minutes ) );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'was_success' => 'boolean',
            'created_at'  => 'datetime',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return CustomerClaimAttemptFactory
     */
    protected static function newFactory(): CustomerClaimAttemptFactory
    {
        return CustomerClaimAttemptFactory::new();
    }
}
