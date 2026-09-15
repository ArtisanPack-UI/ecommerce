<?php

/**
 * RefundItem factory.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Database\Factories;

use ArtisanPackUI\Ecommerce\Models\OrderItem;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Models\RefundItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RefundItem>
 *
 * @since 1.0.0
 */
class RefundItemFactory extends Factory
{
    /**
     * @var class-string<RefundItem>
     */
    protected $model = RefundItem::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'refund_id'     => Refund::factory(),
            'order_item_id' => OrderItem::factory(),
            'quantity'      => 1,
            'amount'        => 1_000,
            'currency'      => 'USD',
            'restock'       => false,
        ];
    }
}
