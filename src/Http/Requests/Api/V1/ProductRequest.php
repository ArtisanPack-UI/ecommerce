<?php

/**
 * ProductRequest.
 *
 * Body of `POST admin/products` and `PATCH admin/products/{product}`
 * (engine spec §9.5). Checks the payload's shape only; catalog rules (taken
 * slug or SKU, registered type, tax class, bundle loops) are enforced by
 * {@see \ArtisanPackUI\Ecommerce\Services\ProductService}.
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
class ProductRequest extends ApiFormRequest
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
        return array_merge(
            [
                'type'                    => [ 'string', 'max:60' ],
                'name'                    => [ 'string', 'max:255' ],
                'slug'                    => [ 'nullable', 'string', 'max:255' ],
                'sku'                     => [ 'nullable', 'string', 'max:100' ],
                'barcode'                 => [ 'nullable', 'string', 'max:100' ],
                'description'             => [ 'nullable', 'string', 'max:200000' ],
                'short_description'       => [ 'nullable', 'string', 'max:5000' ],
                'status'                  => [ 'string', 'in:draft,active,archived' ],
                'featured_image_media_id' => [ 'nullable', 'integer', 'min:1' ],
                'featured_image_url'      => [ 'nullable', 'string', 'max:1000' ],
                'is_taxable'              => [ 'boolean' ],
                'tax_class_key'           => [ 'nullable', 'string', 'max:60' ],
                'weight'                  => [ 'nullable', 'numeric', 'min:0' ],
                'weight_unit'             => [ 'nullable', 'string', 'in:g,kg,oz,lb' ],
                'length'                  => [ 'nullable', 'numeric', 'min:0' ],
                'width'                   => [ 'nullable', 'numeric', 'min:0' ],
                'height'                  => [ 'nullable', 'numeric', 'min:0' ],
                'dim_unit'                => [ 'nullable', 'string', 'in:mm,cm,in' ],
                'meta'                    => [ 'nullable', 'array', 'max:50' ],
                'published_at'            => [ 'nullable', 'date' ],
                'category_ids'            => [ 'array', 'max:200' ],
                'category_ids.*'          => [ 'integer' ],
                'tag_ids'                 => [ 'array', 'max:200' ],
                'tag_ids.*'               => [ 'integer' ],
                'images'                  => [ 'array', 'max:50' ],
                'images.*'                => [ 'array' ],
                'images.*.id'             => [ 'nullable', 'integer' ],
                'images.*.media_id'       => [ 'nullable', 'integer', 'min:1' ],
                'images.*.image_url'      => [ 'nullable', 'string', 'max:1000' ],
                'images.*.alt_text'       => [ 'nullable', 'string', 'max:255' ],
                'attributes'              => [ 'array', 'max:20' ],
                'attributes.*'            => [ 'array' ],
                'children'                => [ 'array', 'max:100' ],
                'children.*'              => [ 'array' ],
                'children.*.product_id'   => [ 'required', 'integer' ],
                'children.*.variant_id'   => [ 'nullable', 'integer' ],
                'children.*.quantity'     => [ 'integer', 'min:1', 'max:1000' ],
            ],
            self::priceRules( 'prices' ),
            self::inventoryRules(),
            ProductAttributeRequest::valueRules( 'attributes.*.' ),
            [
                'attributes.*.id'           => [ 'nullable', 'integer' ],
                'attributes.*.key'          => [ 'nullable', 'string', 'max:60' ],
                'attributes.*.label'        => [ 'required', 'string', 'max:120' ],
                'attributes.*.is_variation' => [ 'boolean' ],
            ],
        );
    }

    /**
     * Rules for a list of price rows under `$key`.
     *
     * @since 1.0.0
     *
     * @param  string  $key  List key.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function priceRules( string $key ): array
    {
        return [
            $key                            => [ 'array', 'max:50' ],
            "{$key}.*"                      => [ 'array' ],
            "{$key}.*.currency"             => [ 'required', 'string', 'size:3' ],
            "{$key}.*.price_amount"         => [ 'required', 'integer', 'min:0' ],
            "{$key}.*.compare_at_amount"    => [ 'nullable', 'integer', 'min:0' ],
            "{$key}.*.cost_amount"          => [ 'nullable', 'integer', 'min:0' ],
            "{$key}.*.starts_at"            => [ 'nullable', 'date' ],
            "{$key}.*.ends_at"              => [ 'nullable', 'date' ],
        ];
    }

    /**
     * Rules for the `inventory` settings block and `stock_adjustment`.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public static function inventoryRules(): array
    {
        return [
            'inventory'                     => [ 'array' ],
            'inventory.track_inventory'     => [ 'boolean' ],
            'inventory.allow_backorder'     => [ 'boolean' ],
            'inventory.low_stock_threshold' => [ 'nullable', 'integer', 'min:0' ],
            'inventory.quantity_on_hand'    => [ 'integer', 'min:-1000000', 'max:1000000' ],
            'stock_adjustment'              => [ 'array' ],
            'stock_adjustment.delta'        => [ 'required_with:stock_adjustment', 'integer', 'min:-1000000', 'max:1000000' ],
            'stock_adjustment.reason'       => [ 'required_with:stock_adjustment', 'string', 'max:255' ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->sometimes( self::baseRules(), [ 'type', 'name' ] );
    }
}
