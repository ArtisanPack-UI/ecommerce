<?php

/**
 * CustomerNote model.
 *
 * An internal staff note on a {@see Customer}. Customer notes are never
 * shown to the shopper. Written through
 * {@see \ArtisanPackUI\Ecommerce\Services\CustomerNoteService}, which also
 * records the matching activity entry.
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

use ArtisanPackUI\Ecommerce\Database\Factories\CustomerNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CustomerNote Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int         $id
 * @property int         $customer_id
 * @property int|null    $author_user_id
 * @property string      $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Customer    $customer
 */
class CustomerNote extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'customer_notes';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'author_user_id',
        'body',
    ];

    /**
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
            'customer_id'    => 'integer',
            'author_user_id' => 'integer',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return CustomerNoteFactory
     */
    protected static function newFactory(): CustomerNoteFactory
    {
        return CustomerNoteFactory::new();
    }
}
