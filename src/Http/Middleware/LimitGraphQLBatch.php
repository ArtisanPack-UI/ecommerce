<?php

/**
 * LimitGraphQLBatch middleware.
 *
 * rebing/graphql-laravel accepts batched requests (a JSON array of
 * operations, as JSON, form fields, or a multipart `operations` field) and
 * its switch is global to the host app. On the ecommerce endpoint this caps
 * the batch size at
 * `artisanpack.ecommerce.graphql.max_batch`, so one HTTP request can't fan
 * out into an unbounded number of operations. Oversized batches get a 400
 * problem+json.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Middleware;

use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class LimitGraphQLBatch
{
    /**
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  Closure  $next     Next middleware.
     *
     * @return Response
     */
    public function handle( Request $request, Closure $next ): Response
    {
        $max = max( 1, (int) config( 'artisanpack.ecommerce.graphql.max_batch', 10 ) );

        if ( $this->operationCount( $request ) > $max ) {
            return Problem::make(
                400,
                'graphql-batch-too-large',
                __( 'Batch too large' ),
                __( 'A batch may contain at most :max operations.', [ 'max' => $max ] ),
                $request,
            );
        }

        return $next( $request );
    }

    /**
     * How many operations the request carries, in every body format rebing
     * accepts: a JSON body, a form-encoded body (`0[query]=…&1[query]=…`),
     * or a multipart upload's JSON `operations` field.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return int
     */
    protected function operationCount( Request $request ): int
    {
        $payload = $request->isJson() ? $request->json()->all() : $request->post();

        if ( isset( $payload['operations'] ) && is_string( $payload['operations'] ) ) {
            $payload = json_decode( $payload['operations'], true );
        }

        return is_array( $payload ) && [] !== $payload && array_is_list( $payload ) ? count( $payload ) : 1;
    }
}
