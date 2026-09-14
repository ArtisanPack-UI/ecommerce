<?php

/**
 * Order model (stub).
 *
 * Placeholder model wired up so the {@see \ArtisanPackUI\Ecommerce\Contracts\ProductType}
 * contract can type-hint placed orders. The order-lifecycle work
 * (spec §3.15) will replace this stub with the fully-fillable model and
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
 * Order Eloquent stub.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class Order extends Model
{
    /**
     * @var string
     */
    protected $table = 'orders';

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
