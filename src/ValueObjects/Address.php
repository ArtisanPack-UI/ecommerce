<?php

/**
 * Address value object.
 *
 * Immutable structured address matching the JSON shape stored on
 * `orders.shipping_address` / `orders.billing_address` (engine spec §3.15).
 * Passed into `ShippingRateProvider`, `TaxProvider`, and `FraudProvider`
 * calls — the destination the calculation runs against.
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

use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
final class Address
{
    /**
     * @since 1.0.0
     *
     * @param  string       $address1     Street address line one.
     * @param  string       $city         City / locality.
     * @param  string       $countryCode  Two-letter ISO 3166-1 alpha-2 country code.
     * @param  string|null  $firstName    Recipient first name.
     * @param  string|null  $lastName     Recipient last name.
     * @param  string|null  $company      Company or organisation.
     * @param  string|null  $phone        Contact phone number.
     * @param  string|null  $address2     Street address line two.
     * @param  string|null  $region       Region / state / province name.
     * @param  string|null  $regionCode   Region ISO subdivision code (e.g. `CA`).
     * @param  string|null  $postalCode   Postal / ZIP code.
     */
    public function __construct(
        public readonly string $address1,
        public readonly string $city,
        public readonly string $countryCode,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $company = null,
        public readonly ?string $phone = null,
        public readonly ?string $address2 = null,
        public readonly ?string $region = null,
        public readonly ?string $regionCode = null,
        public readonly ?string $postalCode = null,
    ) {
        if ( 2 !== strlen( $this->countryCode ) ) {
            throw new InvalidArgumentException( sprintf(
                'Address countryCode must be a two-letter ISO 3166-1 alpha-2 code; "%s" given.',
                $this->countryCode,
            ) );
        }
    }

    /**
     * Hydrates an {@see Address} from an array using the JSON shape stored
     * on `orders.shipping_address` / `orders.billing_address`.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $data
     *
     * @return self
     */
    public static function fromArray( array $data ): self
    {
        return new self(
            address1: (string) ( $data['address1'] ?? '' ),
            city: (string) ( $data['city'] ?? '' ),
            countryCode: strtoupper( (string) ( $data['country_code'] ?? '' ) ),
            firstName: self::optString( $data, 'first_name' ),
            lastName: self::optString( $data, 'last_name' ),
            company: self::optString( $data, 'company' ),
            phone: self::optString( $data, 'phone' ),
            address2: self::optString( $data, 'address2' ),
            region: self::optString( $data, 'region' ),
            regionCode: self::optString( $data, 'region_code' ),
            postalCode: self::optString( $data, 'postal_code' ),
        );
    }

    /**
     * Serialises to the JSON shape stored on `orders.shipping_address` /
     * `orders.billing_address`.
     *
     * @since 1.0.0
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'first_name'   => $this->firstName,
            'last_name'    => $this->lastName,
            'company'      => $this->company,
            'phone'        => $this->phone,
            'address1'     => $this->address1,
            'address2'     => $this->address2,
            'city'         => $this->city,
            'region'       => $this->region,
            'region_code'  => $this->regionCode,
            'postal_code'  => $this->postalCode,
            'country_code' => $this->countryCode,
        ];
    }

    /**
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $data
     * @param  string                $key
     *
     * @return string|null
     */
    private static function optString( array $data, string $key ): ?string
    {
        if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
            return null;
        }

        return (string) $data[ $key ];
    }
}
