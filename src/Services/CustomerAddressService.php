<?php

/**
 * CustomerAddressService.
 *
 * Admin writes for a customer's saved addresses (engine issue #142):
 * create, update, and delete, with default shipping / billing handling.
 * Each write records an `address.added`, `address.updated`, or
 * `address.deleted` activity entry on the customer and fires the matching
 * `ap.ecommerce.customer.address*` action once the outermost transaction
 * commits.
 *
 * Default rules:
 *
 * - The customer's first address becomes default shipping and billing.
 * - Setting `is_default_shipping` or `is_default_billing` clears that flag
 *   on the customer's other addresses.
 * - Deleting a default address does not promote another one; the customer
 *   simply has no default until one is set.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Services;

use ArtisanPackUI\Ecommerce\Exceptions\CustomerWriteException;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerAddressService
{
    /**
     * Writable text columns and their maximum lengths.
     *
     * @since 1.0.0
     *
     * @var array<string, int>
     */
    public const TEXT_FIELDS = [
        'label'        => 120,
        'first_name'   => 120,
        'last_name'    => 120,
        'company'      => 120,
        'phone'        => 50,
        'address1'     => 255,
        'address2'     => 255,
        'city'         => 120,
        'region'       => 120,
        'region_code'  => 10,
        'postal_code'  => 20,
        'country_code' => 2,
    ];

    /**
     * Writable boolean columns.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const FLAG_FIELDS = [
        'is_default_shipping',
        'is_default_billing',
    ];

    /**
     * Columns every saved address must have.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const REQUIRED_FIELDS = [
        'address1',
        'city',
        'country_code',
    ];

    /**
     * @since 1.0.0
     *
     * @param  ActivityLogService  $activity  Activity log.
     */
    public function __construct( protected ActivityLogService $activity )
    {
    }

    /**
     * Adds an address to `$customer`.
     *
     * @since 1.0.0
     *
     * @param  Customer              $customer     Owning customer.
     * @param  array<string, mixed>  $attributes   Address columns; `customer_id` and unknown keys are ignored.
     * @param  int|null              $actorUserId  Acting user id.
     *
     * @throws CustomerWriteException When a required field is missing or a value is invalid.
     *
     * @return CustomerAddress
     */
    public function create( Customer $customer, array $attributes, ?int $actorUserId = null ): CustomerAddress
    {
        $data = $this->normalize( $attributes );
        $this->validate( $data );

        $address = DB::transaction( function () use ( $customer, $data, $actorUserId ): CustomerAddress {
            Customer::query()->lockForUpdate()->findOrFail( $customer->id );

            if ( ! CustomerAddress::query()->where( 'customer_id', $customer->id )->exists() ) {
                $data['is_default_shipping'] = true;
                $data['is_default_billing']  = true;
            }

            $address              = new CustomerAddress( $data );
            $address->customer_id = $customer->id;
            $address->save();

            $this->clearOtherDefaults( $address );

            $this->activity->record( $customer, 'address.added', [
                'address_id'          => (int) $address->id,
                'is_default_shipping' => (bool) $address->is_default_shipping,
                'is_default_billing'  => (bool) $address->is_default_billing,
            ], $actorUserId );

            DB::afterCommit( static fn () => doAction( 'ap.ecommerce.customer.addressAdded', $customer, $address ) );

            return $address;
        } );

        return $address;
    }

    /**
     * Updates `$address`. Only the keys present in `$attributes` change.
     *
     * @since 1.0.0
     *
     * @param  CustomerAddress       $address      Address.
     * @param  array<string, mixed>  $attributes   Address columns; `customer_id` and unknown keys are ignored.
     * @param  int|null              $actorUserId  Acting user id.
     *
     * @throws CustomerWriteException When the result would miss a required field or a value is invalid.
     *
     * @return CustomerAddress
     */
    public function update( CustomerAddress $address, array $attributes, ?int $actorUserId = null ): CustomerAddress
    {
        $data = $this->normalize( $attributes );

        $merged = array_merge(
            array_intersect_key( $address->getAttributes(), array_flip( self::REQUIRED_FIELDS ) ),
            $data,
        );
        $this->validate( $merged, array_keys( $data ) );

        return DB::transaction( function () use ( $address, $data, $actorUserId ): CustomerAddress {
            $customer = Customer::query()->lockForUpdate()->findOrFail( $address->customer_id );
            $locked   = CustomerAddress::query()->lockForUpdate()->findOrFail( $address->id );

            $locked->fill( $data );
            $changed = array_keys( $locked->getDirty() );
            $locked->save();

            $this->clearOtherDefaults( $locked );

            if ( [] !== $changed ) {
                $this->activity->record( $customer, 'address.updated', [
                    'address_id' => (int) $locked->id,
                    'fields'     => $changed,
                ], $actorUserId );
            }

            DB::afterCommit( static fn () => doAction( 'ap.ecommerce.customer.addressUpdated', $customer, $locked, $changed ) );

            return $locked;
        } );
    }

    /**
     * Deletes `$address`. A deleted default is not replaced. Does nothing
     * (and fires nothing) when the address is already gone.
     *
     * @since 1.0.0
     *
     * @param  CustomerAddress  $address      Address.
     * @param  int|null         $actorUserId  Acting user id.
     *
     * @return void
     */
    public function delete( CustomerAddress $address, ?int $actorUserId = null ): void
    {
        DB::transaction( function () use ( $address, $actorUserId ): void {
            $customer = Customer::query()->lockForUpdate()->find( $address->customer_id );
            $locked   = CustomerAddress::query()->lockForUpdate()->find( $address->id );

            // Already gone (a concurrent delete won): nothing to record.
            if ( null === $locked || null === $customer || ! $locked->delete() ) {
                return;
            }

            $this->activity->record( $customer, 'address.deleted', [
                'address_id'          => (int) $locked->id,
                'is_default_shipping' => (bool) $locked->is_default_shipping,
                'is_default_billing'  => (bool) $locked->is_default_billing,
            ], $actorUserId );

            DB::afterCommit( static fn () => doAction( 'ap.ecommerce.customer.addressDeleted', $customer, $locked ) );
        } );
    }

    /**
     * Keeps only the writable columns, trimming text, turning blanks into
     * null, and upper-casing the country and region codes.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  Raw input.
     *
     * @return array<string, mixed>
     */
    protected function normalize( array $attributes ): array
    {
        $data = [];

        foreach ( self::TEXT_FIELDS as $field => $max ) {
            if ( ! array_key_exists( $field, $attributes ) ) {
                continue;
            }

            $value = $attributes[ $field ];

            if ( null !== $value && ! is_scalar( $value ) ) {
                throw CustomerWriteException::field( $field, 'invalid', __( 'This value must be text.' ) );
            }

            $value = null === $value ? '' : trim( (string) $value );

            if ( in_array( $field, [ 'country_code', 'region_code' ], true ) ) {
                $value = strtoupper( $value );
            }

            $data[ $field ] = '' === $value ? null : $value;
        }

        foreach ( self::FLAG_FIELDS as $field ) {
            if ( array_key_exists( $field, $attributes ) ) {
                $data[ $field ] = filter_var( $attributes[ $field ], FILTER_VALIDATE_BOOLEAN );
            }
        }

        return $data;
    }

    /**
     * Checks required fields, lengths, and the country code.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>     $data     Normalized values.
     * @param  array<int, string>|null  $touched  Fields to length-check; all when null.
     *
     * @throws CustomerWriteException When a rule is broken.
     *
     * @return void
     */
    protected function validate( array $data, ?array $touched = null ): void
    {
        $errors = [];

        foreach ( self::REQUIRED_FIELDS as $field ) {
            if ( null === ( $data[ $field ] ?? null ) || '' === $data[ $field ] ) {
                $errors[] = [ 'field' => $field, 'code' => 'required', 'message' => match ( $field ) {
                    'address1' => __( 'Enter a street address.' ),
                    'city'     => __( 'Enter a city.' ),
                    default    => __( 'Choose a country.' ),
                } ];
            }
        }

        foreach ( self::TEXT_FIELDS as $field => $max ) {
            if ( null !== $touched && ! in_array( $field, $touched, true ) ) {
                continue;
            }

            $value = $data[ $field ] ?? null;

            if ( is_string( $value ) && mb_strlen( $value ) > $max ) {
                $errors[] = [ 'field' => $field, 'code' => 'too-long', 'message' => __( 'This value can be at most :max characters.', [ 'max' => $max ] ) ];
            }
        }

        $country = $data['country_code'] ?? null;

        if ( is_string( $country ) && 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
            $errors[] = [ 'field' => 'country_code', 'code' => 'invalid-country', 'message' => __( 'Use a two-letter ISO country code.' ) ];
        }

        if ( [] !== $errors ) {
            throw new CustomerWriteException( $errors );
        }
    }

    /**
     * Clears the default flags `$address` holds from the customer's other
     * addresses.
     *
     * @since 1.0.0
     *
     * @param  CustomerAddress  $address  The address that now holds the default.
     *
     * @return void
     */
    protected function clearOtherDefaults( CustomerAddress $address ): void
    {
        foreach ( self::FLAG_FIELDS as $flag ) {
            if ( ! $address->{$flag} ) {
                continue;
            }

            CustomerAddress::query()
                ->where( 'customer_id', $address->customer_id )
                ->whereKeyNot( $address->id )
                ->where( $flag, true )
                ->update( [ $flag => false ] );
        }
    }
}
