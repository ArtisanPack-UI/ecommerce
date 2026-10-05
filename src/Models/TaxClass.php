<?php

/**
 * TaxClass model.
 *
 * A named bucket of tax rates (`standard`, `reduced`, `zero`, `digital`,
 * …). Products and shipping methods reference a class by `key`; rates are
 * attached to a class via the soft `tax_rates.tax_class_key` reference.
 *
 * Engine spec §3.24.
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

use ArtisanPackUI\Ecommerce\Database\Factories\TaxClassFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * TaxClass Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int         $id
 * @property string      $key
 * @property string      $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TaxClass extends Model
{
    use HasFactory;

    /**
     * Class key products fall back to when `tax_class_key` is null.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DEFAULT_KEY = 'standard';

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_tax_classes';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'label',
    ];

    /**
     * Rates attached to this class.
     *
     * @since 1.0.0
     *
     * @return HasMany<TaxRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany( TaxRate::class, 'tax_class_key', 'key' );
    }

    /**
     * @since 1.0.0
     *
     * @return TaxClassFactory
     */
    protected static function newFactory(): TaxClassFactory
    {
        return TaxClassFactory::new();
    }
}
