<?php

/**
 * PromotionUsage model.
 *
 * One row per promotion applied to a placed order (parent plan §5.9 step
 * 7). Backs the per-customer usage limit and reporting.
 *
 * Engine spec §3.23.
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

use ArtisanPackUI\Ecommerce\Casts\MoneyCast;
use ArtisanPackUI\Ecommerce\Database\Factories\PromotionUsageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Money\Money;

/**
 * PromotionUsage Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int           $id
 * @property int           $promotion_id
 * @property int           $order_id
 * @property int|null      $customer_id
 * @property int           $amount_discounted
 * @property string        $currency
 * @property Money         $money
 * @property Carbon|null   $created_at
 * @property Promotion     $promotion
 * @property Order         $order
 * @property Customer|null $customer
 */
class PromotionUsage extends Model
{
    use HasFactory;

    /**
     * Only `created_at` exists on this table.
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
    protected $table = 'promotion_usages';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'promotion_id',
        'order_id',
        'customer_id',
        'amount_discounted',
        'currency',
    ];

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo( Promotion::class );
    }

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo( Order::class );
    }

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
            'promotion_id'      => 'integer',
            'order_id'          => 'integer',
            'customer_id'       => 'integer',
            'amount_discounted' => 'integer',
            'money'             => MoneyCast::class . ':amount_discounted,currency',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return PromotionUsageFactory
     */
    protected static function newFactory(): PromotionUsageFactory
    {
        return PromotionUsageFactory::new();
    }
}
