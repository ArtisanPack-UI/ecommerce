<?php

/**
 * KanbanCardWidgetOverrideRequest.
 *
 * Body of `PUT kanban/widgets/{key}`: a widget's display label and/or
 * default config for every board (ArtisanPack-UI/ecommerce#202). The
 * config is checked against the widget's schema by
 * {@see \ArtisanPackUI\Ecommerce\Services\KanbanCardWidgetService::saveOverride()}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Requests\Api\V1;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
class KanbanCardWidgetOverrideRequest extends ApiFormRequest
{
    /**
     * @since 1.2.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'label'          => [ 'sometimes', 'nullable', 'string', 'max:120' ],
            'default_config' => [ 'sometimes', 'array' ],
        ];
    }
}
