<?php

/**
 * DigitalFileController.
 *
 * `admin/digital-files` (engine spec §9.9): the files attached to digital
 * products. Updates go through {@see DigitalFileService} so a `version`
 * bump announces the new version to buyers.
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

use ArtisanPackUI\Ecommerce\Exceptions\DigitalFileInUseException;
use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\DigitalFileRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\DigitalFileResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\DigitalFile;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Services\DigitalFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DigitalFileController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  DigitalFileService  $files  File writes.
     */
    public function __construct( private readonly DigitalFileService $files )
    {
    }

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List digital files', resource: DigitalFileResource::class, collection: true )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            DigitalFile::query(),
            $request,
            DigitalFileResource::class,
            [
                'product_id'         => [ 'product_id', 'int' ],
                'product_variant_id' => [ 'product_variant_id', 'int' ],
                'is_streaming_only'  => [ 'is_streaming_only', 'bool' ],
            ],
            [ 'label' => 'label', 'created_at' => 'created_at' ],
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  DigitalFileRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create a digital file', resource: DigitalFileResource::class, status: 201 )]
    public function store( DigitalFileRequest $request ): JsonResponse
    {
        return $this->resourceResponse( $this->files->create( $request->validated() ), $request, DigitalFileResource::class, [], 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  DigitalFileRequest  $request  Validated request.
     * @param  DigitalFile         $file     File.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a digital file', resource: DigitalFileResource::class )]
    public function update( DigitalFileRequest $request, DigitalFile $file ): JsonResponse
    {
        return $this->resourceResponse( $this->files->update( $file, $request->validated() ), $request, DigitalFileResource::class );
    }

    /**
     * Deletes a file no customer holds an entitlement for; a file that has
     * been sold answers 409 `digital-file-in-use` (archive it instead).
     *
     * @since 1.0.0
     *
     * @param  Request      $request  Request.
     * @param  DigitalFile  $file     File.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a digital file', resource: DigitalFileResource::class )]
    public function destroy( Request $request, DigitalFile $file ): JsonResponse
    {
        try {
            $this->files->delete( $file );
        } catch ( DigitalFileInUseException $exception ) {
            return Problem::make( 409, 'digital-file-in-use', __( 'Digital file in use' ), $exception->getMessage(), $request );
        }

        return $this->resourceResponse( $file, $request, DigitalFileResource::class );
    }
}
