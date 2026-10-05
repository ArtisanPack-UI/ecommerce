<?php

/**
 * InventoryPolicy.
 *
 * Engine-resource `inventory` abilities (engine spec §6.18, engine issue #148):
 * `viewAny` to list stock levels and `adjust` to change them, separate from
 * `product.update` so staff can count stock without editing products.
 * Registered for {@see \ArtisanPackUI\Ecommerce\Models\InventoryItem}. Each method
 * routes through {@see EcommercePolicy::decide()}.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Policies;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class InventoryPolicy extends EcommercePolicy
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const RESOURCE = 'inventory';

    /**
     * `ecommerce.inventory.viewAny`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  mixed            $subject  The model acted on, if any.
     *
     * @return bool
     */
    public function viewAny( Authenticatable $user, mixed $subject = null ): bool
    {
        return $this->decide( $user, 'viewAny', $subject );
    }

    /**
     * `ecommerce.inventory.adjust`.
     *
     * @since 1.0.0
     *
     * @param  Authenticatable  $user     Acting user.
     * @param  mixed            $subject  The model acted on, if any.
     *
     * @return bool
     */
    public function adjust( Authenticatable $user, mixed $subject = null ): bool
    {
        return $this->decide( $user, 'adjust', $subject );
    }
}
