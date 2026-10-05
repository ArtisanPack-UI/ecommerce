<?php

/**
 * PromotionCondition model.
 *
 * One eligibility rule attached to a {@see Promotion}. `type` is a
 * {@see \ArtisanPackUI\Ecommerce\Registries\PromotionConditionRegistry} key and `config`
 * is that implementation's JSON configuration.
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

use ArtisanPackUI\Ecommerce\Database\Factories\PromotionConditionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PromotionCondition Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                  $id
 * @property int                  $promotion_id
 * @property string               $type
 * @property array<string, mixed> $config
 * @property Promotion            $promotion
 */
class PromotionCondition extends Model
{
    use HasFactory;

    /**
     * The table has no timestamps (engine spec §3.23).
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
    protected $table = 'ecommerce_promotion_conditions';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'promotion_id',
        'type',
        'config',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'config' => '{}',
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'promotion_id' => 'integer',
            'config'       => 'array',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return PromotionConditionFactory
     */
    protected static function newFactory(): PromotionConditionFactory
    {
        return PromotionConditionFactory::new();
    }
}
