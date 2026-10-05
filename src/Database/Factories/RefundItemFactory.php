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

use ArtisanPackUI\Ecommerce\Models\Order;
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
     * Both parent records must anchor to the same {@see Order}, otherwise
     * downstream services (e.g. {@see \ArtisanPackUI\Ecommerce\Services\RefundService})
     * that verify "order item belongs to this order" would reject the row.
     * Materialise a shared order here so `RefundItem::factory()->create()`
     * always produces a coherent line by default.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $order = Order::factory()->create();

        return [
            'refund_id'     => Refund::factory()->state( [ 'order_id' => $order->id ] ),
            'order_item_id' => OrderItem::factory()->state( [ 'order_id' => $order->id ] ),
            'quantity'      => 1,
            'amount'        => 1_000,
            'currency'      => 'USD',
            'restock'       => false,
        ];
    }
}
