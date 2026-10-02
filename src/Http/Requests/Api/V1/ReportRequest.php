<?php

/**
 * ReportRequest.
 *
 * Validates `GET admin/reports/{report}` (engine issue #146): the date
 * range (`from`, `to` as `Y-m-d` in the store time zone), `interval`,
 * `compare`, and the report's own options from
 * {@see \ArtisanPackUI\Ecommerce\Reports\Report::optionRules()}.
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

use ArtisanPackUI\Ecommerce\Registries\ReportRegistry;
use ArtisanPackUI\Ecommerce\Reports\ReportRange;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ReportRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $registry = app( ReportRegistry::class );
        $key      = (string) $this->route( 'report' );

        return [
            'from'     => [ 'nullable', 'date_format:Y-m-d' ],
            'to'       => [ 'nullable', 'date_format:Y-m-d', 'after_or_equal:from' ],
            'interval' => [ 'nullable', Rule::in( ReportRange::INTERVALS ) ],
            'compare'  => [ 'nullable', 'boolean' ],
            ...( $registry->has( $key ) ? $registry->get( $key )->optionRules() : [] ),
        ];
    }
}
