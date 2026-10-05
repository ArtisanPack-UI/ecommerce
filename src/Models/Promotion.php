<?php

/**
 * Promotion model.
 *
 * A discount rule: a set of {@see PromotionCondition} rows that must all
 * pass plus a set of {@see PromotionAction} rows that apply the discount.
 * `source_type` says how the promotion is triggered — `automatic` (any
 * qualifying cart), `coupon` (a {@see Coupon} code was entered), or a
 * satellite source (`gift-card`, `referral`, …).
 *
 * Engine spec §3.23, parent plan §5.9.
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

use ArtisanPackUI\Ecommerce\Database\Factories\PromotionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Promotion Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                                $id
 * @property string                                                             $key
 * @property string                                                             $name
 * @property string|null                                                        $description
 * @property string                                                             $source_type
 * @property bool                                                               $is_exclusive
 * @property int                                                                $priority
 * @property Carbon|null                                                        $starts_at
 * @property Carbon|null                                                        $ends_at
 * @property int|null                                                           $usage_limit_total
 * @property int|null                                                           $usage_limit_per_customer
 * @property int                                                                $times_used
 * @property bool                                                               $is_active
 * @property Carbon|null                                                        $created_at
 * @property Carbon|null                                                        $updated_at
 * @property \Illuminate\Database\Eloquent\Collection<int, PromotionCondition>  $conditions
 * @property \Illuminate\Database\Eloquent\Collection<int, PromotionAction>     $actions
 * @property \Illuminate\Database\Eloquent\Collection<int, Coupon>              $coupons
 */
class Promotion extends Model
{
    use HasFactory;

    /**
     * Source type for promotions applied to any qualifying cart.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SOURCE_AUTOMATIC = 'automatic';

    /**
     * Source type for promotions unlocked by a coupon code.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SOURCE_COUPON = 'coupon';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_promotions';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'name',
        'description',
        'source_type',
        'is_exclusive',
        'priority',
        'starts_at',
        'ends_at',
        'usage_limit_total',
        'usage_limit_per_customer',
        'is_active',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_exclusive' => false,
        'priority'     => 0,
        'times_used'   => 0,
        'is_active'    => true,
    ];

    /**
     * @since 1.0.0
     *
     * @return HasMany<PromotionCondition, $this>
     */
    public function conditions(): HasMany
    {
        return $this->hasMany( PromotionCondition::class )->orderBy( 'id' );
    }

    /**
     * @since 1.0.0
     *
     * @return HasMany<PromotionAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany( PromotionAction::class )->orderBy( 'id' );
    }

    /**
     * @since 1.0.0
     *
     * @return HasMany<Coupon, $this>
     */
    public function coupons(): HasMany
    {
        return $this->hasMany( Coupon::class );
    }

    /**
     * @since 1.0.0
     *
     * @return HasMany<PromotionUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany( PromotionUsage::class );
    }

    /**
     * Scope: active and inside its date window at `$at`.
     *
     * @since 1.0.0
     *
     * @param  Builder<Promotion>       $query
     * @param  DateTimeInterface|null  $at     Reference time (defaults to now).
     *
     * @return Builder<Promotion>
     */
    public function scopeActiveAt( Builder $query, ?DateTimeInterface $at = null ): Builder
    {
        $at ??= Carbon::now();

        return $query
            ->where( 'is_active', true )
            ->where( fn ( Builder $q ) => $q->whereNull( 'starts_at' )->orWhere( 'starts_at', '<=', $at ) )
            ->where( fn ( Builder $q ) => $q->whereNull( 'ends_at' )->orWhere( 'ends_at', '>', $at ) );
    }

    /**
     * Whether the promotion is active and inside its date window at `$at`.
     *
     * @since 1.0.0
     *
     * @param  DateTimeInterface|null  $at  Reference time (defaults to now).
     *
     * @return bool
     */
    public function isActiveAt( ?DateTimeInterface $at = null ): bool
    {
        $at = Carbon::instance( $at ?? Carbon::now() );

        return $this->is_active
            && ( null === $this->starts_at || $this->starts_at->lessThanOrEqualTo( $at ) )
            && ( null === $this->ends_at || $this->ends_at->greaterThan( $at ) );
    }

    /**
     * Whether the promotion still has usage left, overall and — when a
     * customer is known — for that customer. Guest carts can only be held
     * to the overall limit.
     *
     * @since 1.0.0
     *
     * @param  int|null  $customerId  Customer id, if known.
     *
     * @return bool
     */
    public function hasUsageRemaining( ?int $customerId = null ): bool
    {
        if ( null !== $this->usage_limit_total && $this->times_used >= $this->usage_limit_total ) {
            return false;
        }

        if ( null === $customerId || null === $this->usage_limit_per_customer ) {
            return true;
        }

        return $this->usages()->where( 'customer_id', $customerId )->count() < $this->usage_limit_per_customer;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_exclusive'             => 'boolean',
            'priority'                 => 'integer',
            'starts_at'                => 'datetime',
            'ends_at'                  => 'datetime',
            'usage_limit_total'        => 'integer',
            'usage_limit_per_customer' => 'integer',
            'times_used'               => 'integer',
            'is_active'                => 'boolean',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return PromotionFactory
     */
    protected static function newFactory(): PromotionFactory
    {
        return PromotionFactory::new();
    }
}
