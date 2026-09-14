<?php

/**
 * OrderItem model (stub).
 *
 * Placeholder wired up so the {@see \ArtisanPackUI\Ecommerce\Contracts\ProductType}
 * contract can type-hint order lines. The order-lifecycle work
 * (spec §3.16) will replace this stub with the fully-fillable model and
 * its migration.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * OrderItem Eloquent stub.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderItem extends Model
{
    /**
     * @var string
     */
    protected $table = 'order_items';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options'          => 'array',
            'product_snapshot' => 'array',
        ];
    }
}
