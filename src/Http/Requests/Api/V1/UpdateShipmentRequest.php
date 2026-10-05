<?php

/**
 * UpdateShipmentRequest.
 *
 * Payload for `PATCH orders/{order}/shipments/{shipment}` — a tracking
 * update. A status change runs through `ShipmentService::updateTracking()`
 * so delivery hooks fire.
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

use ArtisanPackUI\Ecommerce\Models\Shipment;
use Illuminate\Validation\Rule;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class UpdateShipmentRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status'          => [ 'sometimes', Rule::in( Shipment::STATUSES ) ],
            'carrier'         => [ 'sometimes', 'nullable', 'string', 'max:120' ],
            'service'         => [ 'sometimes', 'nullable', 'string', 'max:120' ],
            'tracking_number' => [ 'sometimes', 'nullable', 'string', 'max:255' ],
            'tracking_url'    => [ 'sometimes', 'nullable', 'url', 'max:500' ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => __( 'Shipment status must be one of: :values.' ),
        ];
    }
}
