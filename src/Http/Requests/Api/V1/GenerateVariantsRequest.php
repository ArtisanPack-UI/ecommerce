<?php

/**
 * GenerateVariantsRequest.
 *
 * Body of `POST admin/products/{product}/variants/generate`: defaults
 * (prices, stock settings) applied to every variant created.
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
class GenerateVariantsRequest extends ApiFormRequest
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
        return array_merge( ProductRequest::priceRules( 'prices' ), [
            'inventory'                     => [ 'array' ],
            'inventory.track_inventory'     => [ 'boolean' ],
            'inventory.allow_backorder'     => [ 'boolean' ],
            'inventory.low_stock_threshold' => [ 'nullable', 'integer', 'min:0' ],
            'inventory.quantity_on_hand'    => [ 'integer' ],
        ] );
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
