<?php

/**
 * ProductReviewMedia model.
 *
 * A media-library item (photo, video) attached to a {@see ProductReview}.
 * `media_id` is unconstrained so `artisanpack-ui/media-library` stays a
 * soft dependency. Engine spec §3.26.
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

use ArtisanPackUI\Ecommerce\Database\Factories\ProductReviewMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductReviewMedia Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int            $id
 * @property int            $review_id
 * @property int            $media_id
 * @property ProductReview  $review
 */
class ProductReviewMedia extends Model
{
    use HasFactory;

    /**
     * The table has no timestamps (engine spec §3.26).
     *
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
    protected $table = 'product_review_media';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'review_id',
        'media_id',
    ];

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<ProductReview, $this>
     */
    public function review(): BelongsTo
    {
        return $this->belongsTo( ProductReview::class, 'review_id' );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'review_id' => 'integer',
            'media_id'  => 'integer',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return ProductReviewMediaFactory
     */
    protected static function newFactory(): ProductReviewMediaFactory
    {
        return ProductReviewMediaFactory::new();
    }
}
