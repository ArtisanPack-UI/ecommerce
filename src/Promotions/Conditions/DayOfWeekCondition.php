<?php

/**
 * DayOfWeekCondition.
 *
 * `day-of-week`: passes on the listed ISO-8601 weekdays (1 = Monday …
 * 7 = Sunday). `timezone` defaults to the application timezone so "Friday
 * sale" means the store's Friday, not UTC's.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Promotions\Conditions;

use ArtisanPackUI\Ecommerce\Contracts\PromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Cart;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DayOfWeekCondition implements PromotionCondition
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'day-of-week';

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return __( 'Day of week' );
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart.
     * @param  array<string, mixed>  $config  `{ days: int[], timezone?: string }`.
     *
     * @return bool
     */
    public function evaluate( Cart $cart, array $config ): bool
    {
        $days = array_map( 'intval', array_filter( (array) ( $config['days'] ?? [] ), 'is_numeric' ) );

        if ( [] === $days ) {
            return false;
        }

        try {
            $now = Carbon::now( (string) ( $config['timezone'] ?? config( 'app.timezone', 'UTC' ) ) );
        } catch ( Throwable ) {
            return false;
        }

        return in_array( $now->dayOfWeekIso, $days, true );
    }
}
