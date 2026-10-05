<?php

/**
 * AbilityCatalog.
 *
 * The closed list of engine abilities (engine spec §6.18) as data, so
 * integrations can enumerate them — the cms-framework bridge registers
 * one RBAC permission per entry (engine issue #151). Satellites add their
 * own resources through `ap.ecommerce.abilities.catalog`:
 *
 * ```php
 * addFilter( 'ap.ecommerce.abilities.catalog', fn ( array $catalog ): array => $catalog + [
 *     'subscription' => [ 'viewAny', 'view', 'update', 'cancel' ],
 * ] );
 * ```
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Auth;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class AbilityCatalog
{
    /**
     * Resource → actions, as listed in engine spec §6.18.
     *
     * @since 1.0.0
     *
     * @var array<string, array<int, string>>
     */
    public const CORE = [
        'product'              => [ 'viewAny', 'view', 'create', 'update', 'delete', 'restore' ],
        'order'                => [ 'viewAny', 'view', 'create', 'update', 'edit-fulfilled', 'cancel', 'refund' ],
        'refund'               => [ 'create', 'view' ],
        'customer'             => [ 'viewAny', 'view', 'update', 'delete' ],
        'promotion'            => [ 'viewAny', 'view', 'create', 'update', 'delete' ],
        'coupon'               => [ 'create', 'update', 'delete' ],
        'taxRate'              => [ 'viewAny', 'create', 'update', 'delete' ],
        'shippingZone'         => [ 'viewAny', 'create', 'update', 'delete' ],
        'kanbanBoard'          => [ 'viewAny', 'view', 'create', 'update', 'delete' ],
        'kanbanCard'           => [ 'move' ],
        'notificationTemplate' => [ 'viewAny', 'view', 'update' ],
        'webhookSubscription'  => [ 'viewAny', 'create', 'update', 'delete' ],
        'digitalFile'          => [ 'viewAny', 'create', 'update', 'delete' ],
        'licenseKey'           => [ 'view', 'revoke' ],
        'review'               => [ 'viewAny', 'view', 'moderate', 'delete' ],
        'orderSubstatus'       => [ 'viewAny', 'view', 'create', 'update', 'delete' ],
        'inventory'            => [ 'viewAny', 'adjust' ],
        'settings'             => [ 'view', 'update' ],
        'report'               => [ 'view' ],
    ];

    /**
     * Resource → actions, after `ap.ecommerce.abilities.catalog`.
     *
     * @since 1.0.0
     *
     * @return array<string, array<int, string>>
     */
    public static function all(): array
    {
        $catalog = [];

        foreach ( (array) applyFilters( 'ap.ecommerce.abilities.catalog', self::CORE ) as $resource => $actions ) {
            if ( is_string( $resource ) && '' !== $resource ) {
                $catalog[ $resource ] = array_values( array_unique( array_filter( (array) $actions, static fn ( mixed $action ): bool => is_string( $action ) && '' !== $action ) ) );
            }
        }

        return $catalog;
    }

    /**
     * Every Gate ability name, `ecommerce.{resource}.{action}`.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public static function abilities(): array
    {
        $abilities = [];

        foreach ( self::all() as $resource => $actions ) {
            foreach ( $actions as $action ) {
                $abilities[] = sprintf( 'ecommerce.%s.%s', $resource, $action );
            }
        }

        return $abilities;
    }
}
