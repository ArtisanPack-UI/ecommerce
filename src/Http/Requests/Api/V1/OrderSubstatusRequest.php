<?php

/**
 * OrderSubstatusRequest.
 *
 * Body of `POST admin/order-substatuses` and
 * `PATCH admin/order-substatuses/{substatus}` (engine issue #143). Shape
 * only: uniqueness, the colour format, and the fixed system status are
 * enforced by {@see \ArtisanPackUI\Ecommerce\Services\OrderSubstatusService}.
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

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderSubstatusRequest extends ApiFormRequest
{
    /**
     * Shape rules shared by REST and GraphQL.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public static function baseRules(): array
    {
        return [
            'system_status' => [ 'string', 'max:60' ],
            'key'           => [ 'nullable', 'string', 'max:80' ],
            'label'         => [ 'string', 'max:120' ],
            'color'         => [ 'nullable', 'string', 'max:7' ],
            'icon'          => [ 'nullable', 'string', 'max:80' ],
            'is_terminal'   => [ 'boolean' ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->sometimes( self::baseRules(), [ 'system_status', 'label' ] );
    }
}
