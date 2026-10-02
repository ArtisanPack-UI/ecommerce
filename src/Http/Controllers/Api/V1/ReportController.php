<?php

/**
 * ReportController.
 *
 * `admin/reports` (engine issue #146): list the registered reports and run
 * one through {@see ReportRunner}. Amounts are integer minor units of the
 * store's current base currency; see `docs/reports.md`.
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

use ArtisanPackUI\Ecommerce\Http\Requests\Api\V1\ReportRequest;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\OpenApi\Attributes\ApiOperation;
use ArtisanPackUI\Ecommerce\Registries\ReportRegistry;
use ArtisanPackUI\Ecommerce\Reports\ReportRange;
use ArtisanPackUI\Ecommerce\Reports\ReportRunner;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ReportController extends ApiController
{
    /**
     * @since 1.0.0
     *
     * @param  ReportRunner    $runner   Runs reports.
     * @param  ReportRegistry  $reports  Registered reports.
     */
    public function __construct(
        private readonly ReportRunner $runner,
        private readonly ReportRegistry $reports,
    ) {
    }

    /**
     * The registered reports.
     *
     * @since 1.0.0
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'List the available reports' )]
    public function index(): JsonResponse
    {
        return new JsonResponse( [ 'data' => $this->runner->available() ] );
    }

    /**
     * Runs one report.
     *
     * @since 1.0.0
     *
     * @param  ReportRequest  $request  Validated request.
     * @param  string         $report   Report key.
     *
     * @return JsonResponse
     */
    #[ApiOperation( summary: 'Run a report' )]
    public function show( ReportRequest $request, string $report ): JsonResponse
    {
        abort_unless( $this->reports->has( $report ), 404 );

        try {
            $range = ReportRange::make(
                $request->validated( 'from' ),
                $request->validated( 'to' ),
                (string) ( $request->validated( 'interval' ) ?? 'day' ),
                $request->boolean( 'compare' ),
            );
        } catch ( InvalidArgumentException $exception ) {
            return Problem::make( 422, 'validation-failed', __( 'Validation failed' ), $exception->getMessage(), $request, [
                [ 'field' => 'from', 'code' => 'invalid', 'message' => $exception->getMessage() ],
            ] );
        }

        $options = array_diff_key( $request->validated(), array_flip( [ 'from', 'to', 'interval', 'compare' ] ) );

        return new JsonResponse( [ 'data' => $this->runner->run( $report, $range, $options ) ] );
    }
}
