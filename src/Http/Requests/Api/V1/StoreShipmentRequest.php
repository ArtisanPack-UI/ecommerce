<?php

/**
 * StoreShipmentRequest.
 *
 * Payload for `POST orders/{order}/shipments`. `items` is optional —
 * omitted means "ship everything still unshipped".
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
class StoreShipmentRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'method_key'              => [ 'required', 'string', 'max:120' ],
            'items'                   => [ 'sometimes', 'array' ],
            'items.*.order_item_id'   => [ 'required', 'integer' ],
            'items.*.quantity'        => [ 'required', 'integer', 'min:1' ],
            'carrier'                 => [ 'sometimes', 'nullable', 'string', 'max:120' ],
            'service'                 => [ 'sometimes', 'nullable', 'string', 'max:120' ],
            'tracking_number'         => [ 'sometimes', 'nullable', 'string', 'max:255' ],
            'tracking_url'            => [ 'sometimes', 'nullable', 'url', 'max:500' ],
            'status'                  => [ 'sometimes', Rule::in( Shipment::STATUSES ) ],
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
            'method_key.required' => __( 'A shipping method key is required.' ),
            'status.in'           => __( 'Shipment status must be one of: :values.' ),
        ];
    }
}
