<?php

/**
 * OrderNote model.
 *
 * A free-form annotation attached to an {@see Order}. Notes with
 * `is_customer_visible = true` are surfaced to the shopper in the storefront
 * order view; others stay internal. Engine spec §3.18.
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

use ArtisanPackUI\Ecommerce\Database\Factories\OrderNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * OrderNote Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int         $id
 * @property int         $order_id
 * @property int|null    $author_user_id
 * @property string      $body
 * @property bool        $is_customer_visible
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Order       $order
 */
class OrderNote extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'order_notes';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'author_user_id',
        'body',
        'is_customer_visible',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_customer_visible' => false,
    ];

    /**
     * @since 1.0.0
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo( Order::class );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id'            => 'integer',
            'author_user_id'      => 'integer',
            'is_customer_visible' => 'boolean',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return OrderNoteFactory
     */
    protected static function newFactory(): OrderNoteFactory
    {
        return OrderNoteFactory::new();
    }
}
