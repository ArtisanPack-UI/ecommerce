<?php

/**
 * KanbanBoardRequest.
 *
 * Payload for `POST kanban/boards` / `PATCH kanban/boards/{board}`.
 * `routing_rules` must be a well-formed condition tree whose leaves name
 * registered conditions; `settings.card_widgets` is a widget list
 * ({@see KanbanWidgetList}: registered keys or `{ key, config }` entries).
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

use ArtisanPackUI\Ecommerce\Kanban\KanbanWidgetList;
use ArtisanPackUI\Ecommerce\Kanban\OrderConditionEvaluator;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use Closure;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class KanbanBoardRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     * @since 1.2.0 Widget entries may carry config.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $board = $this->route( 'board' );

        return $this->sometimes( [
            'key'                     => [ 'string', 'max:120', 'regex:/^(?:[a-z0-9]+(?:-[a-z0-9]+)*:)?[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique( KanbanBoard::class, 'key' )->ignore( $board instanceof KanbanBoard ? $board->id : null ) ],
            'name'                    => [ 'string', 'max:255' ],
            'description'             => [ 'nullable', 'string' ],
            'routing_rules'           => [ 'array', self::conditionTree() ],
            'is_default'              => [ 'boolean' ],
            'is_active'               => [ 'boolean' ],
            'position'                => [ 'integer', 'min:0' ],
            'settings'                => [ 'array' ],
        ], [ 'key', 'name' ] ) + KanbanWidgetList::rules( 'settings.card_widgets', $this->input( 'settings.card_widgets' ) );
    }

    /**
     * @since 1.2.0
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return parent::attributes() + KanbanWidgetList::attributes( 'settings.card_widgets', $this->input( 'settings.card_widgets' ) );
    }

    /**
     * Validation rule for a kanban condition tree.
     *
     * @since 1.0.0
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function conditionTree(): Closure
    {
        return static function ( string $attribute, mixed $value, Closure $fail ): void {
            $error = app( OrderConditionEvaluator::class )->validate( $value );

            if ( null !== $error ) {
                $fail( $error );
            }
        };
    }
}
