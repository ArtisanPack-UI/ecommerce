<?php

/**
 * KanbanAutomation model.
 *
 * A data-driven rule on a {@see KanbanBoard}: when a card moves from
 * `from_column_id` (or from any column, when null) into `to_column_id` and
 * the optional `conditions` tree passes, the trigger registered under
 * `trigger_key` fires with `trigger_config`. Engine spec §3.28, parent
 * plan §9.4.
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

use ArtisanPackUI\Ecommerce\Database\Factories\KanbanAutomationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KanbanAutomation Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                   $id
 * @property int                   $board_id
 * @property int|null              $from_column_id
 * @property int                   $to_column_id
 * @property string                $trigger_key
 * @property array<string, mixed>  $trigger_config
 * @property array<string, mixed>  $conditions
 * @property bool                  $is_active
 * @property KanbanBoard           $board
 * @property KanbanColumn|null     $fromColumn
 * @property KanbanColumn          $toColumn
 */
class KanbanAutomation extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'kanban_automations';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'board_id',
        'from_column_id',
        'to_column_id',
        'trigger_key',
        'trigger_config',
        'conditions',
        'is_active',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'trigger_config' => '{}',
        'conditions'     => '{}',
        'is_active'      => true,
    ];

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<KanbanBoard, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo( KanbanBoard::class, 'board_id' );
    }

    /**
     * The column the card must leave; `null` matches any column.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<KanbanColumn, $this>
     */
    public function fromColumn(): BelongsTo
    {
        return $this->belongsTo( KanbanColumn::class, 'from_column_id' );
    }

    /**
     * The column the card must enter.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<KanbanColumn, $this>
     */
    public function toColumn(): BelongsTo
    {
        return $this->belongsTo( KanbanColumn::class, 'to_column_id' );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'board_id'       => 'integer',
            'from_column_id' => 'integer',
            'to_column_id'   => 'integer',
            'trigger_config' => 'array',
            'conditions'     => 'array',
            'is_active'      => 'boolean',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return KanbanAutomationFactory
     */
    protected static function newFactory(): KanbanAutomationFactory
    {
        return KanbanAutomationFactory::new();
    }
}
