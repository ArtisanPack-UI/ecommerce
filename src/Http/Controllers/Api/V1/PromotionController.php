<?php

/**
 * PromotionController.
 *
 * CRUD for `admin/promotions` (engine spec §9.7). `conditions` /
 * `actions` in a payload replace the promotion's existing rows inside the
 * same transaction.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\PromotionRequest;
use ArtisanPackUI\Ecommerce\Http\Resources\PromotionResource;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Promotion;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class PromotionController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    private const INCLUDES = [ 'conditions' => 'conditions', 'actions' => 'actions', 'coupons' => 'coupons' ];

    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List promotions', resource: PromotionResource::class, collection: true, filters: [ 'source_type' => 'string', 'is_active' => 'boolean', 'key' => 'string' ], sorts: [ 'priority', 'created_at', 'name' ], includes: self::INCLUDES )]
    public function index( Request $request ): JsonResponse
    {
        return $this->listResponse(
            Promotion::query(),
            $request,
            PromotionResource::class,
            [ 'source_type' => 'source_type', 'is_active' => [ 'is_active', 'bool' ], 'key' => 'key' ],
            [ 'priority' => 'priority', 'created_at' => 'created_at', 'name' => 'name' ],
            self::INCLUDES,
            'priority',
        );
    }

    /**
     * @since 1.0.0
     *
     * @param  PromotionRequest  $request  Validated request.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Create a promotion', resource: PromotionResource::class, status: 201, includes: self::INCLUDES )]
    public function store( PromotionRequest $request ): JsonResponse
    {
        $promotion = DB::transaction( fn (): Promotion => $this->persist( new Promotion(), $request->validated() ) );

        // The rules are part of what was mutated, so they are always returned.
        $promotion->load( [ 'conditions', 'actions' ] );

        return $this->resourceResponse( $promotion, $request, PromotionResource::class, self::INCLUDES, 201 );
    }

    /**
     * @since 1.0.0
     *
     * @param  PromotionRequest  $request    Validated request.
     * @param  Promotion         $promotion  Promotion.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Update a promotion', resource: PromotionResource::class, includes: self::INCLUDES )]
    public function update( PromotionRequest $request, Promotion $promotion ): JsonResponse
    {
        DB::transaction( fn (): Promotion => $this->persist( $promotion, $request->validated() ) );

        $promotion->load( [ 'conditions', 'actions' ] );

        return $this->resourceResponse( $promotion, $request, PromotionResource::class, self::INCLUDES );
    }

    /**
     * Deletes an unused promotion (its conditions, actions, and coupons cascade).
     *
     * @since 1.0.0
     *
     * @param  Request    $request    Request.
     * @param  Promotion  $promotion  Promotion.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Delete a promotion', resource: PromotionResource::class )]
    public function destroy( Request $request, Promotion $promotion ): JsonResponse
    {
        // Usage rows are the discount audit trail for placed orders; deleting
        // the promotion would cascade them away. Deactivate it instead.
        if ( $promotion->usages()->exists() ) {
            return Problem::make(
                409,
                'promotion-in-use',
                __( 'Promotion in use' ),
                __( 'This promotion has been used on orders and cannot be deleted. Set is_active to false instead.' ),
                $request,
            );
        }

        $promotion->delete();

        return $this->resourceResponse( $promotion, $request, PromotionResource::class );
    }

    /**
     * Saves the promotion's own columns and replaces its rule rows.
     *
     * @since 1.0.0
     *
     * @param  Promotion             $promotion  Promotion.
     * @param  array<string, mixed>  $data       Validated payload.
     *
     * @return Promotion
     */
    protected function persist( Promotion $promotion, array $data ): Promotion
    {
        $promotion->fill( $data )->save();

        foreach ( [ 'conditions', 'actions' ] as $relation ) {
            if ( ! array_key_exists( $relation, $data ) ) {
                continue;
            }

            $promotion->{$relation}()->delete();

            foreach ( (array) $data[ $relation ] as $row ) {
                $promotion->{$relation}()->create( [ 'type' => $row['type'], 'config' => (array) ( $row['config'] ?? [] ) ] );
            }

            $promotion->unsetRelation( $relation );
        }

        return $promotion;
    }
}
