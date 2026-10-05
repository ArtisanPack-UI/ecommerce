<?php

/**
 * Custom Eloquent query builder for {@see \ArtisanPackUI\Ecommerce\Models\OrderItem}.
 *
 * Blocks any bulk update that would modify `product_snapshot`, since mass
 * updates issued via the query builder bypass the model's `updating` event
 * and would otherwise silently corrupt the historical audit trail.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Database\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * @template TModel of \ArtisanPackUI\Ecommerce\Models\OrderItem
 *
 * @extends Builder<TModel>
 *
 * @since 1.0.0
 */
class OrderItemBuilder extends Builder
{
    /**
     * Reject any bulk update that touches the immutable snapshot column.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $values
     *
     * @return int
     */
    public function update( array $values ): int
    {
        if ( array_key_exists( 'product_snapshot', $values ) ) {
            throw new LogicException(
                'order_items.product_snapshot is immutable after placement and cannot be modified.',
            );
        }

        return parent::update( $values );
    }
}
