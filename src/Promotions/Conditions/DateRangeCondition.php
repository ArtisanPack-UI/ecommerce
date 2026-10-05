<?php

/**
 * DateRangeCondition.
 *
 * `date-range` (audit I3): now — or, for a placed order, when it was
 * placed — falls between `starts_on` and `ends_on` (inclusive, whole days
 * in the store's time zone). Either end may be left open, not both.
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

use ArtisanPackUI\Ecommerce\Contracts\DescribesConfig;
use ArtisanPackUI\Ecommerce\Contracts\OrderAwarePromotionCondition;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Support\ConfigField;
use ArtisanPackUI\Ecommerce\Support\StoreTimezone;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class DateRangeCondition implements OrderAwarePromotionCondition, DescribesConfig
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'date-range';

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
        return __( 'Date range' );
    }

    /**
     * Fields this condition's `config` takes.
     *
     * @since 1.0.0
     *
     * @return array<int, array<string, mixed>>
     */
    public function configSchema(): array
    {
        return [
            ConfigField::make( 'starts_on', 'date', __( 'From' ), [ 'help' => __( 'First day, in the store time zone. Leave empty for no start.' ) ] ),
            ConfigField::make( 'ends_on', 'date', __( 'Until' ), [ 'help' => __( 'Last day, in the store time zone. Leave empty for no end.' ) ] ),
        ];
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart.
     * @param  array<string, mixed>  $config  `{ starts_on?: Y-m-d, ends_on?: Y-m-d }`.
     *
     * @return bool
     */
    public function evaluate( Cart $cart, array $config ): bool
    {
        return $this->covers( CarbonImmutable::now(), $config );
    }

    /**
     * @since 1.0.0
     *
     * @param  Order                 $order   Placed order.
     * @param  array<string, mixed>  $config  See {@see self::evaluate()}.
     *
     * @return bool
     */
    public function evaluateOrder( Order $order, array $config ): bool
    {
        return $this->covers( CarbonImmutable::instance( $order->placed_at ?? $order->created_at ?? now() ), $config );
    }

    /**
     * @since 1.0.0
     *
     * @param  DateTimeInterface     $moment  Moment.
     * @param  array<string, mixed>  $config  Config.
     *
     * @return bool
     */
    protected function covers( DateTimeInterface $moment, array $config ): bool
    {
        $start = self::day( $config['starts_on'] ?? null );
        $end   = self::day( $config['ends_on'] ?? null );

        // Nothing (or nothing readable) configured: fail closed.
        if ( ( null === $start && null === $end ) || false === $start || false === $end ) {
            return false;
        }

        $at = CarbonImmutable::instance( $moment )->setTimezone( StoreTimezone::name() );

        return ( null === $start || $at->greaterThanOrEqualTo( $start->startOfDay() ) )
            && ( null === $end || $at->lessThanOrEqualTo( $end->endOfDay() ) );
    }

    /**
     * A configured day in the store time zone; null when not set, false
     * when unreadable.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Configured value.
     *
     * @return CarbonImmutable|false|null
     */
    protected static function day( mixed $value ): CarbonImmutable|false|null
    {
        if ( null === $value || '' === $value ) {
            return null;
        }

        if ( ! is_string( $value ) ) {
            return false;
        }

        try {
            return CarbonImmutable::parse( $value, StoreTimezone::name() );
        } catch ( Throwable ) {
            return false;
        }
    }
}
