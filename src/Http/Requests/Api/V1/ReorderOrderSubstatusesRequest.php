<?php

/**
 * ReorderOrderSubstatusesRequest.
 *
 * Body of `POST admin/order-substatuses/reorder`: the system status and its
 * sub-status ids in their new order.
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
class ReorderOrderSubstatusesRequest extends ApiFormRequest
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
            'system_status' => [ 'required', 'string', 'max:60' ],
            'ids'           => [ 'required', 'array', 'min:1', 'max:1000' ],
            'ids.*'         => [ 'integer' ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return self::baseRules();
    }
}
