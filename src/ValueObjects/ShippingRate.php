<?php

/**
 * ShippingRate value object.
 *
 * One quotable shipping option returned by a
 * {@see \ArtisanPackUI\Ecommerce\Contracts\ShippingRateProvider}. The spec
 * calls this type `Rate` (engine spec §4.3).
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\ValueObjects;

use Money\Money;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class ShippingRate
{
    /**
     * @since 1.0.0
     *
     * @param  string                $methodKey         Method / provider key (e.g. `flat-rate`, `shippo:usps-priority`).
     * @param  string                $label             Customer-facing label.
     * @param  Money                 $amount            Rate in the cart currency.
     * @param  int|null              $shippingMethodId  Originating `shipping_methods` row, when zone-based.
     * @param  string|null           $carrier           Carrier slug (`usps`, `ups`, …).
     * @param  string|null           $service           Carrier service slug.
     * @param  array<string, mixed>  $meta              Provider-specific extras (delivery estimate, pickup location, …).
     */
    public function __construct(
        public readonly string $methodKey,
        public readonly string $label,
        public readonly Money $amount,
        public readonly ?int $shippingMethodId = null,
        public readonly ?string $carrier = null,
        public readonly ?string $service = null,
        public readonly array $meta = [],
    ) {
    }

    /**
     * Stable identifier a client echoes back to select this rate.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function id(): string
    {
        return ( null === $this->shippingMethodId ? '' : $this->shippingMethodId . ':' ) . $this->methodKey
            . ( null === $this->service ? '' : ':' . $this->service );
    }

    /**
     * Returns a copy with a different amount.
     *
     * @since 1.0.0
     *
     * @param  Money  $amount  Replacement amount.
     *
     * @return self
     */
    public function withAmount( Money $amount ): self
    {
        return new self( $this->methodKey, $this->label, $amount, $this->shippingMethodId, $this->carrier, $this->service, $this->meta );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                 => $this->id(),
            'method_key'         => $this->methodKey,
            'label'              => $this->label,
            'amount'             => (int) $this->amount->getAmount(),
            'currency'           => $this->amount->getCurrency()->getCode(),
            'shipping_method_id' => $this->shippingMethodId,
            'carrier'            => $this->carrier,
            'service'            => $this->service,
            'meta'               => $this->meta,
        ];
    }
}
