<?php

/**
 * IssueRefundRequest.
 *
 * Payload for `POST orders/{order}/refunds` (engine spec §9.3). Amounts are
 * minor units in the order's currency; see
 * {@see \ArtisanPackUI\Ecommerce\Services\RefundService::issue()}.
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

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class IssueRefundRequest extends ApiFormRequest
{
    /**
     * Rules shared with the matching GraphQL mutation.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, mixed>>
     */
    public static function baseRules(): array
    {
        return [
            'lines'                 => [ 'required', 'array', 'min:1' ],
            'lines.*.order_item_id' => [ 'required', 'integer', 'min:1' ],
            'lines.*.quantity'      => [ 'required', 'integer', 'min:1' ],
            'lines.*.amount'        => [ 'required', 'integer', 'min:1' ],
            'lines.*.restock'       => [ 'boolean' ],
            'reason'                => [ 'nullable', 'string', 'max:500' ],
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::baseRules();
    }
}
