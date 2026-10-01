<?php

/**
 * ProductCategoryResource.
 *
 * A node in the product category tree (engine spec §3.7).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Resources;

use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProductCategoryResource extends EcommerceResource
{
    /**
     * Resource type name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'productCategory';

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return array<string, mixed>
     */
    protected function fields( Request $request ): array
    {
        return [
            'parent_id'      => $this->resource->parent_id,
            'name'           => $this->resource->name,
            'slug'           => $this->resource->slug,
            'description'    => $this->resource->description,
            'image_media_id' => $this->resource->image_media_id,
            'icon'           => $this->resource->icon,
            'position'       => $this->resource->position,
            'created_at'     => $this->resource->created_at,
            'updated_at'     => $this->resource->updated_at,
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, array{0: string, 1: class-string<EcommerceResource>}>
     */
    protected function relations(): array
    {
        return [
            'parent'   => [ 'parent', ProductCategoryResource::class ],
            'children' => [ 'children', ProductCategoryResource::class ],
        ];
    }
}
