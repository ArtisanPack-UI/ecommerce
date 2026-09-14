<?php

/**
 * CustomerAddress model.
 *
 * Shipping/billing address saved against a {@see Customer}. Engine spec §3.22.
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

use ArtisanPackUI\Ecommerce\Database\Factories\CustomerAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CustomerAddress Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int         $id
 * @property int         $customer_id
 * @property string|null $label
 * @property bool        $is_default_shipping
 * @property bool        $is_default_billing
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $company
 * @property string|null $phone
 * @property string|null $address1
 * @property string|null $address2
 * @property string|null $city
 * @property string|null $region
 * @property string|null $region_code
 * @property string|null $postal_code
 * @property string|null $country_code
 * @property Customer    $customer
 */
class CustomerAddress extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'customer_addresses';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'label',
        'is_default_shipping',
        'is_default_billing',
        'first_name',
        'last_name',
        'company',
        'phone',
        'address1',
        'address2',
        'city',
        'region',
        'region_code',
        'postal_code',
        'country_code',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default_shipping' => false,
        'is_default_billing'  => false,
    ];

    /**
     * The owning customer.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo( Customer::class );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default_shipping' => 'boolean',
            'is_default_billing'  => 'boolean',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return CustomerAddressFactory
     */
    protected static function newFactory(): CustomerAddressFactory
    {
        return CustomerAddressFactory::new();
    }
}
