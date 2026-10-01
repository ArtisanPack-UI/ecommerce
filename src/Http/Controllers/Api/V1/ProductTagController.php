<?php

/**
 * ProductTagController.
 *
 * `admin/product-tags` (engine spec §9.5): list, create, rename, delete,
 * and merge, through {@see ProductTagService}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Controllers\Api\V1;

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\MergeProductTagRequest;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ProductTagRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\ProductTagResource;
use ArtisanPackUI\Ecommerce\Models\ProductTag;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\ProductTagService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ProductTagController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  ProductTagService  $tags  Tag writes.
     */
    public function __construct( private readonly ProductTagService $tags )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List product tags', resource: ProductTagResource::class, collection: true )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            ProductTag::query(),
            $request,
            ProductTagResource::class,
            [
                'slug'   => 'slug',
                'search' => static fn ( Builder $query, string $term ) => $query->whereRaw(
                    "name LIKE ? ESCAPE '!'",
                    [ '%' . str_replace( [ '!', '%', '_' ], [ '!!', '!%', '!_' ], $term ) . '%' ],
                ),
            ],
            [ 'name' => 'name', 'created_at' => 'created_at' ],
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductTagRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create a product tag', resource: ProductTagResource::class, status: 201 )]
    public function store( ProductTagRequest $request ): JsonResponse
    {
        return $this->resourceResponse( $this->tags->create( $request->validated() ), $request, ProductTagResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  ProductTagRequest  $request  Validated request.
     * @param  ProductTag         $tag      Tag.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Rename a product tag', resource: ProductTagResource::class )]
    public function update( ProductTagRequest $request, ProductTag $tag ): JsonResponse
    {
        return $this->resourceResponse( $this->tags->update( $tag, $request->validated() ), $request, ProductTagResource::class );
    }

    /**
     * @since 1.0.0
     *
     * @param  Request     $request  Request.
     * @param  ProductTag  $tag      Tag.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a product tag', resource: ProductTagResource::class )]
    public function destroy( Request $request, ProductTag $tag ): JsonResponse
    {
        $this->tags->delete( $tag );

        return $this->resourceResponse( $tag, $request, ProductTagResource::class );
    }

    /**
     * Moves the tag's products to `target_id` and deletes the tag.
     *
     * @since 1.0.0
     *
     * @param  MergeProductTagRequest  $request  Validated request.
     * @param  ProductTag              $tag      Tag to merge away.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Merge a tag into another', resource: ProductTagResource::class )]
    public function merge( MergeProductTagRequest $request, ProductTag $tag ): JsonResponse
    {
        $target = ProductTag::query()->findOrFail( (int) $request->validated( 'target_id' ) );

        return $this->resourceResponse( $this->tags->merge( $tag, $target ), $request, ProductTagResource::class );
    }
}
