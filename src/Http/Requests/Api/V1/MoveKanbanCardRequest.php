<?php

/**
 * MoveKanbanCardRequest.
 *
 * Payload for `POST kanban/cards/{order}/move`. The target column also
 * identifies the board the card moves on.
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
            'to_column_id' => [ 'required', 'integer', Rule::exists( 'kanban_columns', 'id' ) ],
            'reason'       => [ 'nullable', 'string', 'max:255' ],
        ];
    }
}
