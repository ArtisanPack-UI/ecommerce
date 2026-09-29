<?php

/**
 * ZoneShippingRateProvider.
 *
 * Core {@see ShippingRateProvider}: quotes a cart from the configured
 * `shipping_zones` / `shipping_methods` tables.
 *
 * 1. The first active zone (priority ascending, then id) matching the
 *    destination is used; no match → no rates.
 * 2. Each active method in that zone is quoted in `position` order:
 *    - `provider:{key}` methods delegate to the registered
 *      {@see ShippingRateProvider} and contribute all of its rates;
 *    - any other key is priced by the matching
 *      {@see \ArtisanPackUI\Ecommerce\Contracts\ShippingMethodType} driver
 *      with the row's `config`; a `null` quote hides the method.
 * 3. Each zone-method quote runs through `ap.ecommerce.shipping.rateCalculated`;
 *    the final list runs through `ap.ecommerce.shipping.availableMethods`
 *    (engine spec §6.6).
 *
 * Unknown method keys and misbehaving satellite providers are logged and
 * skipped so one broken method can't take down checkout.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Shipping;

use ArtisanPackUI\Ecommerce\Contracts\ShippingRateProvider;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\Registries\ShippingMethodTypeRegistry;
use ArtisanPackUI\Ecommerce\Registries\ShippingRateProviderRegistry;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\ShippingRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Money\Money;
use Throwable;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ZoneShippingRateProvider implements ShippingRateProvider
{
    /**
     * Provider key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'zones';

    /**
     * @since 1.0.0
     *
     * @param  ShippingMethodTypeRegistry    $methodTypes    Configurable method drivers.
     * @param  ShippingRateProviderRegistry  $rateProviders  Real-time rate providers.
     */
    public function __construct(
        private readonly ShippingMethodTypeRegistry $methodTypes,
        private readonly ShippingRateProviderRegistry $rateProviders,
    ) {
    }

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
        return __( 'Shipping zones' );
    }

    /**
     * Zone serving `$destination`, or null.
     *
     * @since 1.0.0
     *
     * @param  Address  $destination  Destination address.
     *
     * @return ShippingZone|null
     */
    public function zoneFor( Address $destination ): ?ShippingZone
    {
        return ShippingZone::query()
            ->activeInMatchOrder()
            ->get()
            ->first( static fn ( ShippingZone $zone ): bool => $zone->matches( $destination ) );
    }

    /**
     * @since 1.0.0
     *
     * @param  Cart     $cart         Cart being quoted.
     * @param  Address  $destination  Destination address.
     *
     * @return Collection<int, ShippingRate>
     */
    public function getRatesForCart( Cart $cart, Address $destination ): Collection
    {
        // Load the lines once (with what the drivers need) so each method
        // driver reuses them instead of re-querying.
        if ( ! $cart->relationLoaded( 'items' ) ) {
            $cart->setRelation( 'items', $cart->items()->get() );
        }

        $items = $cart->items;
        $items->loadMissing( [ 'product', 'variant' ] );

        if ( $items->isEmpty() ) {
            return new Collection();
        }

        $zone = $this->zoneFor( $destination );

        if ( null === $zone ) {
            return new Collection();
        }

        $currency = strtoupper( (string) $cart->currency );
        $rates    = [];

        foreach ( $zone->methods()->where( 'is_active', true )->get() as $method ) {
            foreach ( $this->quote( $method, $cart, $destination ) as $rate ) {
                if ( $rate->amount->getCurrency()->getCode() !== $currency || $rate->amount->isNegative() ) {
                    Log::channel( 'ecommerce' )->warning( 'Discarding invalid shipping rate.', [
                        'method_key' => $rate->methodKey,
                        'currency'   => $rate->amount->getCurrency()->getCode(),
                        'amount'     => $rate->amount->getAmount(),
                    ] );

                    continue;
                }

                $rates[] = $rate;
            }
        }

        $rates = (array) applyFilters( 'ap.ecommerce.shipping.availableMethods', $rates, $cart, $destination );

        return new Collection( array_values( array_filter(
            $rates,
            static fn ( mixed $rate ): bool => $rate instanceof ShippingRate,
        ) ) );
    }

    /**
     * Quotes one zone method.
     *
     * @since 1.0.0
     *
     * @param  ShippingMethod  $method       Method row.
     * @param  Cart            $cart         Cart.
     * @param  Address         $destination  Destination.
     *
     * @return array<int, ShippingRate>
     */
    protected function quote( ShippingMethod $method, Cart $cart, Address $destination ): array
    {
        $providerKey = $method->providerKey();

        try {
            if ( null !== $providerKey ) {
                return $this->quoteProvider( $providerKey, $method, $cart, $destination );
            }

            if ( ! $this->methodTypes->has( $method->key ) ) {
                Log::channel( 'ecommerce' )->warning( 'Shipping method key is not registered; skipping.', [
                    'shipping_method_id' => $method->id,
                    'key'                => $method->key,
                ] );

                return [];
            }

            $amount = $this->methodTypes->get( $method->key )->calculate( $cart, $destination, (array) $method->config );
        } catch ( Throwable $e ) {
            Log::channel( 'ecommerce' )->error( 'Shipping method failed to quote; skipping.', [
                'shipping_method_id' => $method->id,
                'key'                => $method->key,
                'exception'          => $e->getMessage(),
            ] );

            return [];
        }

        if ( null === $amount ) {
            return [];
        }

        $amount = applyFilters( 'ap.ecommerce.shipping.rateCalculated', $amount, $method, $cart );

        if ( ! $amount instanceof Money ) {
            return [];
        }

        return [
            new ShippingRate(
                methodKey: $method->key,
                label: $method->label,
                amount: $amount,
                shippingMethodId: $method->id,
                meta: $this->publicMeta( $method ),
            ),
        ];
    }

    /**
     * Delegates to a registered real-time provider.
     *
     * @since 1.0.0
     *
     * @param  string          $providerKey  Provider registry key.
     * @param  ShippingMethod  $method       Method row.
     * @param  Cart            $cart         Cart.
     * @param  Address         $destination  Destination.
     *
     * @return array<int, ShippingRate>
     */
    protected function quoteProvider( string $providerKey, ShippingMethod $method, Cart $cart, Address $destination ): array
    {
        if ( self::KEY === $providerKey || ! $this->rateProviders->has( $providerKey ) ) {
            Log::channel( 'ecommerce' )->warning( 'Shipping rate provider is not registered; skipping.', [
                'shipping_method_id' => $method->id,
                'provider'           => $providerKey,
            ] );

            return [];
        }

        $rates = [];

        foreach ( $this->rateProviders->get( $providerKey )->getRatesForCart( $cart, $destination ) as $rate ) {
            $amount = applyFilters( 'ap.ecommerce.shipping.rateCalculated', $rate->amount, $method, $cart );

            if ( ! $amount instanceof Money ) {
                continue;
            }

            $rates[] = new ShippingRate(
                methodKey: $providerKey . ':' . $rate->methodKey,
                label: $rate->label,
                amount: $amount,
                shippingMethodId: $method->id,
                carrier: $rate->carrier,
                service: $rate->service,
                meta: $rate->meta,
            );
        }

        return $rates;
    }

    /**
     * Customer-safe config fields surfaced on a rate (pickup location etc.).
     *
     * @since 1.0.0
     *
     * @param  ShippingMethod  $method  Method row.
     *
     * @return array<string, mixed>
     */
    protected function publicMeta( ShippingMethod $method ): array
    {
        return array_intersect_key( (array) $method->config, array_flip( [ 'location', 'instructions', 'delivery_estimate' ] ) );
    }
}
