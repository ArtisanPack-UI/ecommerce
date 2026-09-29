<?php

/**
 * KanbanCardWidget model.
 *
 * Persisted catalog row for a card widget type. Widgets themselves are code
 * registered in {@see \ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry};
 * a row here lets a store override a widget's display label and default
 * config. Engine spec §3.28, parent plan §9.3.
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

use ArtisanPackUI\Ecommerce\Database\Factories\KanbanCardWidgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * KanbanCardWidget Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                   $id
 * @property string                $key
 * @property string                $label
 * @property array<string, mixed>  $default_config
 * @property string                $provided_by
 */
class KanbanCardWidget extends Model
{
    use HasFactory;

    /**
     * The catalog has no timestamps (engine spec §3.28).
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
    protected $table = 'kanban_card_widgets';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'label',
        'default_config',
        'provided_by',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'default_config' => '{}',
        'provided_by'    => 'ecommerce',
    ];

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_config' => 'array',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return KanbanCardWidgetFactory
     */
    protected static function newFactory(): KanbanCardWidgetFactory
    {
        return KanbanCardWidgetFactory::new();
    }
}
