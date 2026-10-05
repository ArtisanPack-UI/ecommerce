<?php

/**
 * ActivityLogEntry model.
 *
 * Append-only activity log row for a product, customer, or promotion (orders
 * keep {@see OrderTimelineEntry}). Written by
 * {@see \ArtisanPackUI\Ecommerce\Services\ActivityLogService}; rows are
 * immutable after creation. The only sanctioned changes are
 * {@see \ArtisanPackUI\Ecommerce\Services\ActivityLogService::scrubCustomer()}
 * during customer delete-and-anonymize and retention pruning by
 * {@see \ArtisanPackUI\Ecommerce\Console\Commands\PruneLedgersCommand}.
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

use ArtisanPackUI\Ecommerce\Database\Eloquent\AppendOnlyBuilder;
use ArtisanPackUI\Ecommerce\Database\Factories\ActivityLogEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * ActivityLogEntry Eloquent model.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 *
 * @property int                  $id
 * @property string               $subject_type
 * @property int                  $subject_id
 * @property int|null             $actor_user_id
 * @property string               $event_type
 * @property array<string, mixed> $payload
 * @property Carbon|null          $created_at
 * @property Model|null           $subject
 */
class ActivityLogEntry extends Model
{
    use HasFactory;

    /**
     * Activity entries are append-only: no `updated_at`.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_activity_log';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'subject_type',
        'subject_id',
        'actor_user_id',
        'event_type',
        'payload',
    ];

    /**
     * The entity the entry belongs to (product, customer, or promotion).
     *
     * @since 1.0.0
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scopes the query to entries about `$subject`.
     *
     * @since 1.0.0
     *
     * @param  Builder<static>  $query    Query.
     * @param  Model            $subject  Subject.
     *
     * @return Builder<static>
     */
    public function scopeForSubject( Builder $query, Model $subject ): Builder
    {
        return $query
            ->where( 'subject_type', $subject->getMorphClass() )
            ->where( 'subject_id', $subject->getKey() );
    }

    /**
     * Returns the append-only builder so bulk update/delete calls are
     * rejected the same way as model-instance saves.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     *
     * @return AppendOnlyBuilder<static>
     */
    public function newEloquentBuilder( $query ): Builder
    {
        return new AppendOnlyBuilder( $query );
    }

    /**
     * Boot the model to stamp `created_at` on insert and enforce append-only
     * semantics on subsequent saves.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating( function ( self $entry ): void {
            if ( null === $entry->created_at ) {
                $entry->created_at = Carbon::now();
            }
        } );

        static::updating( function ( self $entry ): void {
            throw new LogicException(
                'Activity log entries are append-only and cannot be modified after creation.',
            );
        } );

        static::deleting( function ( self $entry ): void {
            throw new LogicException(
                'Activity log entries are append-only and cannot be deleted.',
            );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_id'    => 'integer',
            'actor_user_id' => 'integer',
            'payload'       => 'array',
            'created_at'    => 'datetime',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return ActivityLogEntryFactory
     */
    protected static function newFactory(): ActivityLogEntryFactory
    {
        return ActivityLogEntryFactory::new();
    }
}
