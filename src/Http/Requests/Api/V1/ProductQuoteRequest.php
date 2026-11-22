<?php

/**
 * ProductQuoteRequest.
 *
 * `POST products/{product}/quote` (#224): the add-to-cart options to
 * price, the quantity (default 1), and the currency (default the store's
 * base currency).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.4.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Http\Requests\Api\V1;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.4.0
 */
class ProductQuoteRequest extends ApiFormRequest
{
    /**
     * Largest quantity a quote accepts.
     *
     * @since 1.4.0
     *
     * @var int
     */
    public const MAX_QUANTITY = 100_000;

    /**
     * @since 1.4.0
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            ...AddCartItemRequest::optionRules(),
            'quantity' => [ 'nullable', 'integer', 'min:1', 'max:' . self::MAX_QUANTITY ],
            'currency' => [ 'nullable', 'string', 'size:3', 'alpha' ],
        ];
    }
}
