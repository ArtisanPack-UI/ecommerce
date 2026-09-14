<?php

/**
 * CartItem model (stub).
 *
 * Placeholder model wired up so the {@see \ArtisanPackUI\Ecommerce\Contracts\ProductType}
 * contract can type-hint cart lines without a Phase 2 dependency. The
 * cart-lifecycle work (spec §3.13–3.14) will replace this stub with the
 * fully-fillable model and its migration.
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
 * CartItem Eloquent stub.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CartItem extends Model
{
    /**
     * @var string
     */
    protected $table = 'cart_items';

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
            'options' => 'array',
        ];
    }
}
