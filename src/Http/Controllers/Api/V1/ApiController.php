<?php

/**
 * ApiController.
 *
 * Base for the `/api/ecommerce/v1` controllers: shared listing and
 * single-resource response builders that apply {@see ListQuery} and run
 * the `ap.ecommerce.api.list.{name}` filter (engine spec §6.17).
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

use ArtisanPackUI\Ecommerce\Http\Resources\EcommerceResource;
use ArtisanPackUI\Ecommerce\Http\Support\ListQuery;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
abstract class ApiController extends Controller
{
    /**
     * Builds a cursor-paginated listing response.
     *
     * @since 1.0.0
     *
     * @param  Builder<Model>                         $query          Base query.
     * @param  Request                                $request        Request.
     * @param  class-string<EcommerceResource>        $resourceClass  Resource class.
     * @param  array<string, Closure|string>         $filters        Allowed filters.
     * @param  array<string, string>                  $sorts          Allowed sorts.
     * @param  array<string, string>                  $includes       Allowed includes (public → relation).
     * @param  string                                 $defaultSort    Default sort.
     *
     * @return JsonResponse
     */
    protected function listResponse(
        Builder $query,
        Request $request,
        string $resourceClass,
        array $filters = [],
        array $sorts = [],
        array $includes = [],
        string $defaultSort = '-id',
    ): JsonResponse {
        $query->with( ListQuery::includes( $request, $includes ) );
        ListQuery::apply( $query, $request, $filters, $sorts, $defaultSort );

        $paginator = ListQuery::paginate( $query, $request );
        $payload   = $resourceClass::collection( $paginator )->response( $request )->getData( true );

        $payload['data'] = (array) applyFilters( 'ap.ecommerce.api.list.' . $resourceClass::NAME, $payload['data'], $query, $request );

        return new JsonResponse( $payload );
    }

    /**
     * Builds a single-resource response, eager-loading requested includes.
     *
     * @since 1.0.0
     *
     * @param  Model                            $model          Model.
     * @param  Request                          $request        Request.
     * @param  class-string<EcommerceResource>  $resourceClass  Resource class.
     * @param  array<string, array{0: string, 1: Closure}|string>  $includes  Allowed includes.
     * @param  int                              $status         HTTP status.
     *
     * @return JsonResponse
     */
    protected function resourceResponse( Model $model, Request $request, string $resourceClass, array $includes = [], int $status = 200 ): JsonResponse
    {
        $model->loadMissing( ListQuery::includes( $request, $includes ) );

        return ( new $resourceClass( $model ) )->response( $request )->setStatusCode( $status );
    }
}
