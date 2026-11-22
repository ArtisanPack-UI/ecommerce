<?php

/**
 * BackInStockSubscription.
 *
 * A shopper's request to hear when a product, or one of its variants, is
 * available again. Pending until notified or unsubscribed; see
 * {@see \ArtisanPackUI\Ecommerce\Services\BackInStockService}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Models;

use ArtisanPackUI\Ecommerce\Database\Factories\BackInStockSubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.4.0
 *
 * @property int                  $id
 * @property string               $email
 * @property int|null             $customer_id
 * @property int                  $product_id
 * @property int|null             $product_variant_id
 * @property string|null          $locale
 * @property Carbon|null          $notified_at
 * @property Carbon|null          $unsubscribed_at
 * @property Carbon|null          $created_at
 * @property Carbon|null          $updated_at
 * @property Customer|null        $customer
 * @property Product              $product
 * @property ProductVariant|null  $variant
 */
class BackInStockSubscription extends Model
{
    use HasFactory;

    /**
     * @since 1.4.0
     *
     * @var string
     */
    protected $table = 'ecommerce_back_in_stock_subscriptions';

    /**
     * @since 1.4.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'customer_id',
        'product_id',
        'product_variant_id',
        'locale',
        'notified_at',
        'unsubscribed_at',
    ];

    /**
     * @since 1.4.0
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo( Customer::class );
    }

    /**
     * @since 1.4.0
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo( Product::class );
    }

    /**
     * @since 1.4.0
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo( ProductVariant::class, 'product_variant_id' );
    }

    /**
     * Whether the subscription is still waiting for a restock.
     *
     * @since 1.4.0
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return null === $this->notified_at && null === $this->unsubscribed_at;
    }

    /**
     * Subscriptions neither notified nor unsubscribed.
     *
     * @since 1.4.0
     *
     * @param  Builder<self>  $query  Query.
     *
     * @return void
     */
    public function scopePending( Builder $query ): void
    {
        $query->whereNull( 'notified_at' )->whereNull( 'unsubscribed_at' );
    }

    /**
     * @since 1.4.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id'        => 'integer',
            'product_id'         => 'integer',
            'product_variant_id' => 'integer',
            'notified_at'        => 'datetime',
            'unsubscribed_at'    => 'datetime',
        ];
    }

    /**
     * @since 1.4.0
     *
     * @return BackInStockSubscriptionFactory
     */
    protected static function newFactory(): BackInStockSubscriptionFactory
    {
        return BackInStockSubscriptionFactory::new();
    }
}
