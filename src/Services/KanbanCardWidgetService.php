<?php

/**
 * KanbanCardWidgetService.
 *
 * Card widget settings outside a single column (ArtisanPack-UI/ecommerce#202):
 *
 * - a board's default widget list (`settings.card_widgets`), used by columns
 *   that don't choose their own;
 * - the store-wide `kanban_card_widgets` overrides — a widget's display
 *   label and default config — that {@see \ArtisanPackUI\Ecommerce\Kanban\KanbanCardRenderer}
 *   merges into every instance.
 *
 * Column lists are written through {@see KanbanColumnService::update()}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Contracts\DescribesConfig;
use ArtisanPackUI\Ecommerce\Events\KanbanBoardConfigChanged;
use ArtisanPackUI\Ecommerce\Kanban\KanbanCardRenderer;
use ArtisanPackUI\Ecommerce\Kanban\KanbanWidgetList;
use ArtisanPackUI\Ecommerce\Models\KanbanBoard;
use ArtisanPackUI\Ecommerce\Models\KanbanCardWidget;
use ArtisanPackUI\Ecommerce\Registries\KanbanCardWidgetRegistry;
use ArtisanPackUI\Ecommerce\Support\AfterCommit;
use ArtisanPackUI\Ecommerce\Support\ConfigSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
class KanbanCardWidgetService
{
    /**
     * @since 1.2.0
     *
     * @param  KanbanCardWidgetRegistry  $widgets   Registered widgets.
     * @param  KanbanCardRenderer        $renderer  Card renderer (its override cache is cleared on writes).
     */
    public function __construct(
        private readonly KanbanCardWidgetRegistry $widgets,
        private readonly KanbanCardRenderer $renderer,
    ) {
    }

    /**
     * Sets `$board`'s default widget list. An empty list falls back to
     * `artisanpack.ecommerce.kanban.default_card_widgets`.
     *
     * @since 1.2.0
     *
     * @param  KanbanBoard        $board        Board.
     * @param  array<int, mixed>  $entries      Keys or `{ key, config }` entries.
     * @param  int|null           $actorUserId  Acting user, if any.
     *
     * @throws ValidationException When an entry names an unknown widget or breaks its schema.
     *
     * @return KanbanBoard
     */
    public function setBoardWidgets( KanbanBoard $board, array $entries, ?int $actorUserId = null ): KanbanBoard
    {
        KanbanWidgetList::validate( $entries, 'card_widgets' );

        $settings                 = (array) ( $board->settings ?? [] );
        $settings['card_widgets'] = array_values( $entries );
        $board->settings          = $settings;

        if ( $board->isDirty( 'settings' ) ) {
            $board->save();

            Event::dispatch( new KanbanBoardConfigChanged( $board, KanbanBoardConfigChanged::BOARD_UPDATED, null, $actorUserId ) );
        }

        return $board;
    }

    /**
     * Stores a widget's display label and/or default config for every
     * board, and tells active boards to refresh. Keys left out of `$data`
     * keep their stored value; a null or empty `label` removes the label
     * override, so the widget's own label shows.
     *
     * @since 1.2.0
     *
     * @param  string                                              $key   Registered widget key.
     * @param  array{label?: string|null, default_config?: array<string, mixed>}  $data  Override.
     *
     * @throws ValidationException When the key isn't registered, the label is too long, or the config breaks the schema.
     *
     * @return KanbanCardWidget
     */
    public function saveOverride( string $key, array $data ): KanbanCardWidget
    {
        $rules = [
            'key'            => [ 'required', 'string', Rule::in( $this->widgets->keys() ) ],
            'label'          => [ 'sometimes', 'nullable', 'string', 'max:120' ],
            'default_config' => [ 'sometimes', 'array' ],
        ];

        $widget     = $this->widgets->has( $key ) ? $this->widgets->get( $key ) : null;
        $attributes = [];

        if ( $widget instanceof DescribesConfig ) {
            $schema                    = $widget->configSchema();
            $rules['default_config'][] = KanbanWidgetList::onlyDeclared( $schema );
            $rules += ConfigSchema::rules( $schema, 'default_config.' );
            $attributes                = ConfigSchema::attributes( $schema, 'default_config.' );
        }

        $input = [ 'key' => $key ] + array_intersect_key( $data, array_flip( [ 'label', 'default_config' ] ) );

        Validator::make( $input, $rules, [], $attributes )->validate();

        $row = DB::transaction( function () use ( $key, $input, $widget ): KanbanCardWidget {
            // No label unless one is set, so the widget's own (translated)
            // label keeps showing; an empty label clears the override.
            $row = KanbanCardWidget::query()->createOrFirst( [ 'key' => $key ], [
                'label'       => null,
                'provided_by' => (string) ( $this->widgets->meta( $key )['provided_by'] ?? 'ecommerce' ),
            ] );

            if ( array_key_exists( 'label', $input ) ) {
                $label      = trim( (string) ( $input['label'] ?? '' ) );
                $row->label = '' === $label ? null : $label;
            }

            if ( array_key_exists( 'default_config', $input ) ) {
                $row->default_config = (array) $input['default_config'];
            }

            $row->save();

            return $row;
        } );

        $this->renderer->forgetOverrides();
        $this->announce();

        AfterCommit::action( 'ap.ecommerce.kanban.widgetOverrideSaved', $row );

        return $row;
    }

    /**
     * Removes a widget's stored override, restoring its own label and
     * schema defaults.
     *
     * @since 1.2.0
     *
     * @param  string  $key  Widget key.
     *
     * @return bool Whether an override was removed.
     */
    public function resetOverride( string $key ): bool
    {
        $deleted = KanbanCardWidget::query()->where( 'key', $key )->delete() > 0;

        if ( $deleted ) {
            $this->renderer->forgetOverrides();
            $this->announce();

            AfterCommit::action( 'ap.ecommerce.kanban.widgetOverrideReset', $key );
        }

        return $deleted;
    }

    /**
     * Tells every active board its cards' widgets changed: an override
     * changes labels and config wherever the widget shows.
     *
     * @since 1.2.0
     *
     * @return void
     */
    protected function announce(): void
    {
        foreach ( KanbanBoard::query()->where( 'is_active', true )->get() as $board ) {
            Event::dispatch( new KanbanBoardConfigChanged( $board, KanbanBoardConfigChanged::BOARD_UPDATED ) );
        }
    }
}
