<?php

/**
 * ActivityLogService.
 *
 * Writes and reads the append-only activity log for products, customers,
 * and promotions (orders keep `order_timeline_entries`). Model observers
 * ({@see \ArtisanPackUI\Ecommerce\Listeners\RecordModelActivity}),
 * {@see InventoryService::adjust()}, and {@see CustomerNoteService} record
 * through {@see self::record()}; admin UIs read through
 * {@see self::forSubject()}. Parent plan §10.2 item 12.
 *
 * Every entry is filed against the owning top-level entity — a variant or
 * price change against its product, a coupon change against its promotion,
 * a note against its customer — so one subject's history shows everything
 * that touched it. {@see self::EVENT_TYPES} lists the documented types.
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

use ArtisanPackUI\Ecommerce\Models\ActivityLogEntry;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerNote;
use ArtisanPackUI\Ecommerce\Models\InventoryItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use ArtisanPackUI\Ecommerce\Support\MorphType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class ActivityLogService
{
    /**
     * Documented activity event types, keyed by type, with the subject each
     * is filed against.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const EVENT_TYPES = [
        'product.created'    => 'product',
        'product.updated'    => 'product',
        'product.deleted'    => 'product',
        'variant.created'    => 'product',
        'variant.updated'    => 'product',
        'variant.deleted'    => 'product',
        'price.created'      => 'product',
        'price.updated'      => 'product',
        'price.deleted'      => 'product',
        'inventory.adjusted' => 'product',
        'customer.created'   => 'customer',
        'customer.updated'   => 'customer',
        'customer.deleted'   => 'customer',
        'note.added'         => 'customer',
        'note.deleted'       => 'customer',
        'address.added'      => 'customer',
        'address.updated'    => 'customer',
        'address.deleted'    => 'customer',
        'promotion.created'  => 'promotion',
        'promotion.updated'  => 'promotion',
        'promotion.deleted'  => 'promotion',
        'coupon.created'     => 'promotion',
        'coupon.updated'     => 'promotion',
        'coupon.deleted'     => 'promotion',
    ];

    /**
     * Customer payload keys that hold personal data, replaced by
     * {@see self::scrubCustomer()}.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const CUSTOMER_PII_KEYS = [
        'email',
        'first_name',
        'last_name',
        'name',
        'phone',
        'meta',
        'excerpt',
    ];

    /**
     * The value scrubbed personal data is replaced with.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const REDACTED = '[redacted]';

    /**
     * Whether recording is paused by {@see self::withoutRecording()}.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected bool $paused = false;

    /**
     * Whether recording is switched on
     * (`artisanpack.ecommerce.activity_log.enabled`).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function enabled(): bool
    {
        return ! $this->paused && (bool) config( 'artisanpack.ecommerce.activity_log.enabled', true );
    }

    /**
     * Runs `$callback` with recording paused, so its writes leave no
     * entries. The customer delete-and-anonymize flow wraps its
     * anonymizing writes in this — otherwise the `customer.updated` and
     * `customer.deleted` entries they produce would carry the very values
     * being erased — and then calls {@see self::scrubCustomer()}.
     *
     * @since 1.0.0
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback  The writes to leave unrecorded.
     *
     * @return TReturn
     */
    public function withoutRecording( callable $callback ): mixed
    {
        $previous     = $this->paused;
        $this->paused = true;

        try {
            return $callback();
        } finally {
            $this->paused = $previous;
        }
    }

    /**
     * Records one entry against `$subject`.
     *
     * The attributes pass through the `ap.ecommerce.activity.recording`
     * filter (`array $attributes, Model $subject`); returning a falsy value
     * skips the entry. After the row is written the
     * `ap.ecommerce.activity.recorded` action fires with
     * `(ActivityLogEntry $entry, Model $subject)`.
     *
     * @since 1.0.0
     *
     * @param  Model                 $subject      Owning product, customer, or promotion.
     * @param  string                $eventType    Event type, e.g. `product.updated`.
     * @param  array<string, mixed>  $payload      Event payload.
     * @param  int|null              $actorUserId  Acting user id; defaults to the signed-in user.
     *
     * @return ActivityLogEntry|null The entry, or null when disabled or filtered out.
     */
    public function record( Model $subject, string $eventType, array $payload = [], ?int $actorUserId = null ): ?ActivityLogEntry
    {
        if ( ! $this->enabled() || null === $subject->getKey() ) {
            return null;
        }

        $attributes = applyFilters( 'ap.ecommerce.activity.recording', [
            'subject_type'  => $subject->getMorphClass(),
            'subject_id'    => (int) $subject->getKey(),
            'actor_user_id' => $actorUserId ?? $this->currentUserId(),
            'event_type'    => $eventType,
            'payload'       => $payload,
        ], $subject );

        if ( ! is_array( $attributes ) || [] === $attributes ) {
            return null;
        }

        $entry = ActivityLogEntry::query()->create( $attributes );

        doAction( 'ap.ecommerce.activity.recorded', $entry, $subject );

        return $entry;
    }

    /**
     * Entries about `$subject`, newest first.
     *
     * @since 1.0.0
     *
     * @param  Model  $subject  Product, customer, or promotion.
     *
     * @return Builder<ActivityLogEntry>
     */
    public function forSubject( Model $subject ): Builder
    {
        return ActivityLogEntry::query()
            ->forSubject( $subject )
            ->orderByDesc( 'created_at' )
            ->orderByDesc( 'id' );
    }

    /**
     * Records an `inventory.adjusted` entry against the product that owns
     * `$item` (directly, or through a variant). Called by
     * {@see InventoryService::adjust()}.
     *
     * @since 1.0.0
     *
     * @param  InventoryItem  $item            Adjusted row.
     * @param  int            $delta           Applied delta.
     * @param  int            $previousOnHand  On-hand before.
     * @param  int            $newOnHand       On-hand after.
     * @param  string         $reason          Audit reason.
     *
     * @return ActivityLogEntry|null
     */
    public function recordInventoryAdjustment( InventoryItem $item, int $delta, int $previousOnHand, int $newOnHand, string $reason ): ?ActivityLogEntry
    {
        if ( ! $this->enabled() ) {
            return null;
        }

        $variantId = null;
        $product   = null;

        if ( MorphType::is( $item->stockable_type, ProductVariant::class ) ) {
            $variant   = ProductVariant::query()->find( $item->stockable_id );
            $variantId = $variant?->id;
            $product   = $variant?->product;
        } elseif ( MorphType::is( $item->stockable_type, Product::class ) ) {
            $product = Product::query()->find( $item->stockable_id );
        }

        if ( null === $product ) {
            return null;
        }

        return $this->record( $product, 'inventory.adjusted', [
            'inventory_item_id' => (int) $item->id,
            'variant_id'        => $variantId,
            'delta'             => $delta,
            'quantity_on_hand'  => [ 'before' => $previousOnHand, 'after' => $newOnHand ],
            'reason'            => $reason,
        ] );
    }

    /**
     * Removes a customer's personal data from the log, for customer
     * delete-and-anonymize (engine issue #142).
     *
     * Deletes the customer's {@see CustomerNote}s and replaces every
     * {@see self::CUSTOMER_PII_KEYS} value inside the payloads of entries
     * filed against the customer with {@see self::REDACTED}. The
     * delete-and-anonymize service MUST run its anonymizing writes inside
     * {@see self::withoutRecording()} and call this last, in the same
     * transaction, so no entry written afterwards carries the erased values.
     *
     * This is the only sanctioned mutation of the append-only log: it goes
     * through the base query builder on purpose, bypassing
     * {@see \ArtisanPackUI\Ecommerce\Database\Eloquent\AppendOnlyBuilder}
     * and the model's `updating` guard.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer being anonymized.
     *
     * @return int Number of activity entries scrubbed.
     */
    public function scrubCustomer( Customer $customer ): int
    {
        return DB::transaction( function () use ( $customer ): int {
            CustomerNote::query()->where( 'customer_id', $customer->id )->delete();

            $table    = ( new ActivityLogEntry() )->getTable();
            $scrubbed = 0;

            $rows = DB::table( $table )
                ->where( 'subject_type', $customer->getMorphClass() )
                ->where( 'subject_id', $customer->id )
                ->get( [ 'id', 'payload' ] );

            foreach ( $rows as $row ) {
                $payload = is_string( $row->payload ) ? json_decode( $row->payload, true ) : null;

                if ( ! is_array( $payload ) ) {
                    continue;
                }

                $clean = $this->redact( $payload );

                if ( $clean === $payload ) {
                    continue;
                }

                DB::table( $table )->where( 'id', $row->id )->update( [ 'payload' => json_encode( $clean ) ] );
                ++$scrubbed;
            }

            return $scrubbed;
        } );
    }

    /**
     * Replaces personal-data values in a payload, recursively.
     *
     * @since 1.0.0
     *
     * @param  array<array-key, mixed>  $payload  Payload.
     *
     * @return array<array-key, mixed>
     */
    protected function redact( array $payload ): array
    {
        foreach ( $payload as $key => $value ) {
            if ( is_string( $key ) && in_array( $key, self::CUSTOMER_PII_KEYS, true ) ) {
                $payload[ $key ] = $this->redactValue( $value );

                continue;
            }

            if ( is_array( $value ) ) {
                $payload[ $key ] = $this->redact( $value );
            }
        }

        return $payload;
    }

    /**
     * Redacts one value, keeping `{before, after}` diff pairs in shape.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Value.
     *
     * @return mixed
     */
    protected function redactValue( mixed $value ): mixed
    {
        if ( null === $value ) {
            return null;
        }

        if ( is_array( $value ) && array_key_exists( 'before', $value ) && array_key_exists( 'after', $value ) ) {
            return [
                'before' => null === $value['before'] ? null : self::REDACTED,
                'after'  => null === $value['after'] ? null : self::REDACTED,
            ];
        }

        return self::REDACTED;
    }

    /**
     * The signed-in user's id, when numeric.
     *
     * @since 1.0.0
     *
     * @return int|null
     */
    protected function currentUserId(): ?int
    {
        $id = auth()->id();

        return is_numeric( $id ) ? (int) $id : null;
    }
}
