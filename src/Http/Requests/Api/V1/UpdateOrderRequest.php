<?php

/**
 * UpdateOrderRequest.
 *
 * Payload for `PATCH orders/{order}`: the customer-facing fields an admin
 * may correct without an order edit (line / total changes go through
 * `OrderEditService`). `meta` is merged into the existing value, and
 * engine-owned keys (`fraud_decision`) can't be written.
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
use Closure;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class UpdateOrderRequest extends ApiFormRequest
{
    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_note' => [ 'sometimes', 'nullable', 'string', 'max:5000' ],
            'phone'         => [ 'sometimes', 'nullable', 'string', 'max:50' ],
            'meta'          => [ 'sometimes', 'array', function ( string $attribute, mixed $value, Closure $fail ): void {
                // Written by PaymentOrchestrator; clients must never forge or
                // wipe it. Checked here rather than with a `meta.*` rule so
                // validated() keeps the rest of the meta payload.
                if ( is_array( $value ) && array_key_exists( 'fraud_decision', $value ) ) {
                    $fail( __( 'meta.fraud_decision is managed by the engine and cannot be changed.' ) );
                }

                // Recorded when the order is placed; the tax report reads it.
                if ( is_array( $value ) && array_key_exists( 'tax_breakdown', $value ) ) {
                    $fail( __( 'meta.tax_breakdown is recorded when the order is placed and cannot be changed.' ) );
                }
            } ],
        ];
    }
}
