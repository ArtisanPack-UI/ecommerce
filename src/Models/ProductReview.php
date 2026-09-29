<?php

/**
 * ProductReview model.
 *
 * A customer's 1–5 star review of a product. Reviews move through the
 * moderation statuses `pending` → `approved` | `rejected` | `spam`; only
 * approved reviews are shown on the storefront and counted toward the
 * product's denormalized rating. Engine spec §3.26, parent plan §5.12.
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

use ArtisanPackUI\Ecommerce\Database\Factories\ProductReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * ProductReview Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                                                                   $id
 * @property int                                                                   $product_id
 * @property int|null                                                              $customer_id
 * @property int|null                                                              $order_id
 * @property string                                                                $author_name
 * @property string|null                                                           $author_email
 * @property int                                                                   $rating
 * @property string|null                                                           $title
 * @property string|null                                                           $body
 * @property bool                                                                  $is_verified_purchase
 * @property string                                                                $status
 * @property Carbon|null                                                           $approved_at
 * @property int|null                                                              $reviewed_by_user_id
 * @property Carbon|null                                                           $created_at
 * @property Carbon|null                                                           $updated_at
 * @property Product                                                               $product
 * @property Customer|null                                                         $customer
 * @property Order|null                                                            $order
 * @property \Illuminate\Database\Eloquent\Collection<int, ProductReviewMedia>     $media
 */
class ProductReview extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_PENDING = 'pending';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_APPROVED = 'approved';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_REJECTED = 'rejected';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_SPAM = 'spam';

    /**
     * Every moderation status.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_SPAM,
    ];

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'product_reviews';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'customer_id',
        'order_id',
        'author_name',
        'author_email',
        'rating',
        'title',
        'body',
        'is_verified_purchase',
        'status',
        'approved_at',
        'reviewed_by_user_id',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_verified_purchase' => false,
        'status'               => self::STATUS_PENDING,
    ];

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo( Product::class );
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
     * The order that makes this a verified purchase, if any.
     *
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
     * @return HasMany<ProductReviewMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany( ProductReviewMedia::class, 'review_id' );
    }

    /**
     * Reviews visible on the storefront.
     *
     * @since 1.0.0
     *
     * @param  Builder<ProductReview>  $query  Query.
     *
     * @return void
     */
    public function scopeApproved( Builder $query ): void
    {
        $query->where( $this->qualifyColumn( 'status' ), self::STATUS_APPROVED );
    }

    /**
     * Whether the review counts toward the product's rating.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isApproved(): bool
    {
        return self::STATUS_APPROVED === $this->status;
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id'           => 'integer',
            'customer_id'          => 'integer',
            'order_id'             => 'integer',
            'rating'               => 'integer',
            'is_verified_purchase' => 'boolean',
            'approved_at'          => 'datetime',
            'reviewed_by_user_id'  => 'integer',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return ProductReviewFactory
     */
    protected static function newFactory(): ProductReviewFactory
    {
        return ProductReviewFactory::new();
    }
}
