<?php

/**
 * WeightBasedMethod.
 *
 * `weight-based` driver. Config:
 *
 * - `unit`  — weight unit the tiers are expressed in (`g`, `kg`, `oz`,
 *             `lb`; default `kg`).
 * - `tiers` — list of `{ max_weight: number|null, amount: int|map }`. The
 *             first tier (in ascending `max_weight` order) whose
 *             `max_weight` is ≥ the cart weight wins; a tier with a null
 *             `max_weight` is a catch-all. Heavier than every tier and no
 *             catch-all → method unavailable.
 *
 * Parent plan §5.10.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Shipping\Methods;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class WeightBasedMethod extends AbstractShippingMethod
{
    /**
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'weight-based';

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
        return __( 'Weight-based' );
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart                  $cart         Cart.
     * @param  Address               $destination  Destination.
     * @param  array<string, mixed>  $config       Method config.
     *
     * @return Money|null
     */
    public function calculate( Cart $cart, Address $destination, array $config ): ?Money
    {
        $tiers = array_values( array_filter(
            (array) ( $config['tiers'] ?? [] ),
            static fn ( mixed $tier ): bool => is_array( $tier ) && array_key_exists( 'amount', $tier ),
        ) );

        if ( [] === $tiers || $this->items( $cart )->isEmpty() ) {
            return null;
        }

        $unit   = (string) ( $config['unit'] ?? 'kg' );
        $weight = $this->weightInGrams( $cart ) / self::gramsPer( $unit );

        usort( $tiers, static function ( array $a, array $b ): int {
            $aMax = $a['max_weight'] ?? null;
            $bMax = $b['max_weight'] ?? null;

            if ( null === $aMax || null === $bMax ) {
                return ( null === $aMax ) <=> ( null === $bMax );
            }

            return (float) $aMax <=> (float) $bMax;
        } );

        foreach ( $tiers as $tier ) {
            $max = $tier['max_weight'] ?? null;

            // Compare at gram precision so 1.0 kg of 0.5 kg items isn't
            // pushed into the next tier by float noise.
            if ( null === $max || round( $weight, 3 ) <= round( (float) $max, 3 ) ) {
                $amount = $this->configuredAmount( $tier['amount'], $cart );

                return ( null === $amount || $amount->isNegative() ) ? null : $amount;
            }
        }

        return null;
    }
}
