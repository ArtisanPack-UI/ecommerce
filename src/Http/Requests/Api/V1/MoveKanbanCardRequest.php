<?php

/**
 * MoveKanbanCardRequest.
 *
 * Payload for `POST kanban/cards/{order}/move`. The target column also
 * identifies the board the card moves on. Since 1.2.0 the card can be
 * placed with `position` (`top` / `bottom`), `before_order_id`, or
 * `after_order_id`; a move into the card's own column with a placement
 * reorders it.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Requests\Api\V1;

use ArtisanPackUI\Ecommerce\Kanban\KanbanCardPlacement;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class MoveKanbanCardRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_column_id'    => [ 'required', 'integer', Rule::exists( KanbanColumn::class, 'id' ) ],
            'reason'          => [ 'nullable', 'string', 'max:255' ],
            'position'        => [ 'nullable', 'string', Rule::in( [ KanbanCardPlacement::TOP, KanbanCardPlacement::BOTTOM ] ), 'prohibits:before_order_id,after_order_id' ],
            'before_order_id' => [ 'nullable', 'integer', 'prohibits:after_order_id' ],
            'after_order_id'  => [ 'nullable', 'integer' ],
        ];
    }

    /**
     * Where the card lands in its column, or null for the default (bottom;
     * a no-op for a move into the card's own column).
     *
     * @since 1.2.0
     *
     * @return KanbanCardPlacement|null
     */
    public function placement(): ?KanbanCardPlacement
    {
        $before = $this->validated( 'before_order_id' );
        $after  = $this->validated( 'after_order_id' );

        return match ( true ) {
            null !== $before                        => KanbanCardPlacement::before( (int) $before ),
            null !== $after                         => KanbanCardPlacement::after( (int) $after ),
            null !== $this->validated( 'position' ) => KanbanCardPlacement::end( $this->validated( 'position' ) ),
            default                                 => null,
        };
    }
}
