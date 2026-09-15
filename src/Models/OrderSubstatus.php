<?php

/**
 * OrderSubstatus model.
 *
 * A finer-grained status attached to a system status. Every system status has
 * at least one default sub-status seeded by the engine migration so a
 * freshly-placed order can land on a non-null `substatus_id` without operator
 * configuration. Engine spec §3.17.
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

use ArtisanPackUI\Ecommerce\Database\Factories\OrderSubstatusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OrderSubstatus Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int         $id
 * @property string      $system_status
 * @property string      $key
 * @property string      $label
 * @property string|null $color
 * @property string|null $icon
 * @property int         $position
 * @property bool        $is_terminal
 */
class OrderSubstatus extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'order_substatuses';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'system_status',
        'key',
        'label',
        'color',
        'icon',
        'position',
        'is_terminal',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'position'    => 0,
        'is_terminal' => false,
    ];

    /**
     * Orders currently on this sub-status.
     *
     * @since 1.0.0
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany( Order::class, 'substatus_id' );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position'    => 'integer',
            'is_terminal' => 'boolean',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return OrderSubstatusFactory
     */
    protected static function newFactory(): OrderSubstatusFactory
    {
        return OrderSubstatusFactory::new();
    }
}
