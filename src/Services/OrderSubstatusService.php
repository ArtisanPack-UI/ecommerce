<?php

/**
 * OrderSubstatusService.
 *
 * Writes for order sub-statuses (engine spec §3.17, engine issue #143):
 * create, update, reorder within a system status, and delete.
 *
 * Rules:
 *
 * - `system_status` must be one of the six core system statuses and is
 *   fixed once the sub-status exists. Moving a sub-status to another system
 *   status would strand every order, board card, and column on it, so the
 *   service refuses the change; create a new sub-status instead.
 * - `key` is a lowercase slug (letters, digits, `-`, `_`), unique within its
 *   system status. When it is left blank on create it is derived from the
 *   label.
 * - `color` is `null` or `#RRGGBB` (stored upper-case).
 * - New sub-statuses go to the end of their system status.
 * - Delete is refused while orders, active board cards, or kanban columns
 *   use the sub-status (the error carries the counts), and for the last
 *   sub-status of a system status, so every system status keeps at least
 *   one. Removed board cards that still point at it are deleted with it.
 *
 * Each write flushes {@see SubStatusRegistry} and fires
 * `ap.ecommerce.orderSubstatus.created`, `.updated`, `.reordered`, or
 * `.deleted` once the outermost transaction commits.
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

use ArtisanPackUI\Ecommerce\Exceptions\OrderSubstatusWriteException;
use ArtisanPackUI\Ecommerce\Models\KanbanColumn;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderBoardAssignment;
use ArtisanPackUI\Ecommerce\Models\OrderSubstatus;
use ArtisanPackUI\Ecommerce\Registries\SubStatusRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderSubstatusService
{
    /**
     * Pattern a sub-status key must match.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY_PATTERN = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/';

    /**
     * Pattern a colour must match (after upper-casing).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COLOR_PATTERN = '/^#[0-9A-F]{6}$/';

    /**
     * Longest key, label, and icon (the column widths).
     *
     * @since 1.0.0
     *
     * @var array<string, int>
     */
    public const MAX_LENGTHS = [
        'key'   => 80,
        'label' => 120,
        'icon'  => 80,
    ];

    /**
     * @since 1.0.0
     *
     * @param  SubStatusRegistry  $registry  Cached lookup to flush on write.
     */
    public function __construct( protected SubStatusRegistry $registry )
    {
    }

    /**
     * The six core system statuses, in lifecycle order.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public static function systemStatuses(): array
    {
        return array_keys( OrderStatusMachine::ALLOWED_TRANSITIONS );
    }

    /**
     * Creates a sub-status at the end of its system status.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $data  `system_status`, `label`, optional `key`, `color`, `icon`, `is_terminal`.
     *
     * @throws OrderSubstatusWriteException When a rule is broken.
     *
     * @return OrderSubstatus
     */
    public function create( array $data ): OrderSubstatus
    {
        $systemStatus = trim( (string) ( $data['system_status'] ?? '' ) );

        if ( ! in_array( $systemStatus, self::systemStatuses(), true ) ) {
            throw OrderSubstatusWriteException::field( 'system_status', 'unknown-system-status', __( 'Choose one of the order statuses: :statuses.', [
                'statuses' => implode( ', ', self::systemStatuses() ),
            ] ) );
        }

        $deriveKey = '' === trim( (string) ( $data['key'] ?? '' ) );

        if ( $deriveKey ) {
            $data['key'] = self::keyFromLabel( (string) ( $data['label'] ?? '' ) );
        }

        $substatus                = new OrderSubstatus();
        $substatus->system_status = $systemStatus;
        $values                   = $this->normalize( $substatus, $data );

        return DB::transaction( function () use ( $substatus, $values, $systemStatus, $deriveKey ): OrderSubstatus {
            // Locking the siblings serialises creates and reorders within one system status.
            $siblings = OrderSubstatus::query()->where( 'system_status', $systemStatus )->orderBy( 'id' )->lockForUpdate()->get( [ 'id', 'key', 'position' ] );

            if ( $deriveKey ) {
                $values['key'] = self::uniqueKey( (string) $values['key'], $siblings->pluck( 'key' )->map( 'strval' )->all() );
            }

            $this->assertKeyAvailable( $systemStatus, (string) $values['key'], null );

            $substatus->fill( $values );
            $substatus->position = (int) $siblings->max( 'position' ) + 1;
            $this->save( $substatus );

            $this->flushRegistry();
            DB::afterCommit( static fn () => doAction( 'ap.ecommerce.orderSubstatus.created', $substatus ) );

            return $substatus;
        } );
    }

    /**
     * Updates a sub-status. Only the keys present in `$data` change.
     *
     * @since 1.0.0
     *
     * @param  OrderSubstatus        $substatus  Sub-status.
     * @param  array<string, mixed>  $data       Changed values: `key`, `label`, `color`, `icon`, `is_terminal`.
     *
     * @throws OrderSubstatusWriteException When a rule is broken or `system_status` would change.
     *
     * @return OrderSubstatus
     */
    public function update( OrderSubstatus $substatus, array $data ): OrderSubstatus
    {
        if ( array_key_exists( 'system_status', $data ) && (string) $data['system_status'] !== (string) $substatus->system_status ) {
            throw OrderSubstatusWriteException::field( 'system_status', 'system-status-fixed', __( 'A sub-status can\'t move to another order status. Create a new one instead.' ) );
        }

        $values = $this->normalize( $substatus, $data );

        return DB::transaction( function () use ( $substatus, $values ): OrderSubstatus {
            $locked = OrderSubstatus::query()->lockForUpdate()->findOrFail( $substatus->id );

            if ( array_key_exists( 'key', $values ) ) {
                $this->assertKeyAvailable( (string) $locked->system_status, (string) $values['key'], (int) $locked->id );
            }

            $locked->fill( $values );
            $changed = array_keys( $locked->getDirty() );
            $this->save( $locked );

            $substatus->setRawAttributes( $locked->getAttributes(), true );

            $this->flushRegistry();
            DB::afterCommit( static fn () => doAction( 'ap.ecommerce.orderSubstatus.updated', $locked, $changed ) );

            return $locked;
        } );
    }

    /**
     * Reorders the sub-statuses of one system status. Ids not listed keep
     * their relative order after the listed ones.
     *
     * @since 1.0.0
     *
     * @param  string           $systemStatus  System status.
     * @param  array<int, int>  $ids           Sub-status ids in their new order.
     *
     * @throws OrderSubstatusWriteException When the status is unknown or an id belongs elsewhere.
     *
     * @return Collection<int, OrderSubstatus> The system status's sub-statuses in their new order.
     */
    public function reorder( string $systemStatus, array $ids ): Collection
    {
        if ( ! in_array( $systemStatus, self::systemStatuses(), true ) ) {
            throw OrderSubstatusWriteException::field( 'system_status', 'unknown-system-status', __( 'Choose one of the order statuses: :statuses.', [
                'statuses' => implode( ', ', self::systemStatuses() ),
            ] ) );
        }

        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

        $ordered = DB::transaction( function () use ( $systemStatus, $ids ): Collection {
            $siblings = OrderSubstatus::query()
                ->where( 'system_status', $systemStatus )
                ->orderBy( 'position' )
                ->orderBy( 'id' )
                ->lockForUpdate()
                ->pluck( 'id' )
                ->map( static fn ( $id ): int => (int) $id )
                ->all();

            if ( [] !== array_diff( $ids, $siblings ) ) {
                throw OrderSubstatusWriteException::field( 'ids', 'unknown-id', __( 'The list includes a sub-status that doesn\'t belong to this order status.' ) );
            }

            foreach ( array_merge( $ids, array_values( array_diff( $siblings, $ids ) ) ) as $position => $id ) {
                OrderSubstatus::query()->whereKey( $id )->update( [ 'position' => $position ] );
            }

            return OrderSubstatus::query()->where( 'system_status', $systemStatus )->orderBy( 'position' )->orderBy( 'id' )->get();
        } );

        $this->flushRegistry();
        DB::afterCommit( static fn () => doAction( 'ap.ecommerce.orderSubstatus.reordered', $systemStatus, $ordered ) );

        return $ordered;
    }

    /**
     * How many orders, active board cards, and kanban columns use `$substatus`.
     *
     * @since 1.0.0
     *
     * @param  OrderSubstatus  $substatus  Sub-status.
     *
     * @return array{orders: int, assignments: int, columns: int}
     */
    public function usage( OrderSubstatus $substatus ): array
    {
        return [
            'orders'      => Order::query()->where( 'substatus_id', $substatus->id )->count(),
            'assignments' => OrderBoardAssignment::query()->where( 'substatus_id', $substatus->id )->whereNull( 'removed_at' )->count(),
            'columns'     => KanbanColumn::query()->where( 'substatus_id', $substatus->id )->count(),
        ];
    }

    /**
     * Deletes a sub-status.
     *
     * @since 1.0.0
     *
     * @param  OrderSubstatus  $substatus  Sub-status.
     *
     * @throws OrderSubstatusWriteException When it is in use or the last of its system status.
     *
     * @return void
     */
    public function delete( OrderSubstatus $substatus ): void
    {
        DB::transaction( function () use ( $substatus ): void {
            $current = OrderSubstatus::query()->find( $substatus->id );

            if ( null === $current ) {
                return;
            }

            // Lock every sub-status of the system status, in id order, so two
            // deletes can't each see the other as the remaining sibling.
            $siblings = OrderSubstatus::query()->where( 'system_status', $current->system_status )->orderBy( 'id' )->lockForUpdate()->get();
            $locked   = $siblings->firstWhere( 'id', $current->id );

            if ( null === $locked ) {
                return;
            }

            $counts = $this->usage( $locked );

            if ( array_sum( $counts ) > 0 ) {
                throw OrderSubstatusWriteException::inUse( $counts, $this->inUseMessage( $counts ) );
            }

            if ( $siblings->count() <= 1 ) {
                throw OrderSubstatusWriteException::field( null, 'last-substatus', __( 'Every order status needs at least one sub-status, so the last one can\'t be deleted.' ) );
            }

            $locked->delete();

            $this->flushRegistry();
            DB::afterCommit( static fn () => doAction( 'ap.ecommerce.orderSubstatus.deleted', $locked ) );
        } );
    }

    /**
     * A key made from a label: tags stripped, slugged, trimmed to length
     * without a dangling separator, and `substatus` when nothing is left.
     *
     * @since 1.0.0
     *
     * @param  string  $label  Label.
     *
     * @return string
     */
    public static function keyFromLabel( string $label ): string
    {
        $key = rtrim( Str::limit( Str::slug( strip_tags( $label ) ), self::MAX_LENGTHS['key'] - 4, '' ), '-_' );

        return '' === $key ? 'substatus' : $key;
    }

    /**
     * Normalizes and validates the writable values in `$data`.
     *
     * @since 1.0.0
     *
     * @param  OrderSubstatus        $substatus  Sub-status being written.
     * @param  array<string, mixed>  $data       Input.
     *
     * @throws OrderSubstatusWriteException When a value is invalid.
     *
     * @return array<string, mixed>
     */
    protected function normalize( OrderSubstatus $substatus, array $data ): array
    {
        $values = [];
        $isNew  = ! $substatus->exists;

        if ( array_key_exists( 'key', $data ) || $isNew ) {
            $key = Str::lower( trim( (string) ( $data['key'] ?? '' ) ) );

            if ( '' === $key ) {
                throw OrderSubstatusWriteException::field( 'key', 'required', __( 'A sub-status needs a key.' ) );
            }

            if ( mb_strlen( $key ) > self::MAX_LENGTHS['key'] || 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
                throw OrderSubstatusWriteException::field( 'key', 'invalid-key', __( 'Use up to :max lowercase letters, numbers, hyphens, or underscores for the key.', [ 'max' => self::MAX_LENGTHS['key'] ] ) );
            }

            $values['key'] = $key;
        }

        if ( array_key_exists( 'label', $data ) || $isNew ) {
            $label = trim( strip_tags( (string) ( $data['label'] ?? '' ) ) );

            if ( '' === $label ) {
                throw OrderSubstatusWriteException::field( 'label', 'required', __( 'A sub-status needs a label.' ) );
            }

            if ( mb_strlen( $label ) > self::MAX_LENGTHS['label'] ) {
                throw OrderSubstatusWriteException::field( 'label', 'too-long', __( 'Keep the label to :max characters or fewer.', [ 'max' => self::MAX_LENGTHS['label'] ] ) );
            }

            $values['label'] = $label;
        }

        if ( array_key_exists( 'color', $data ) ) {
            $color = Str::upper( trim( (string) ( $data['color'] ?? '' ) ) );

            if ( '' !== $color && 1 !== preg_match( self::COLOR_PATTERN, $color ) ) {
                throw OrderSubstatusWriteException::field( 'color', 'invalid-color', __( 'Enter the colour as a hex code like #3B82F6.' ) );
            }

            $values['color'] = '' === $color ? null : $color;
        }

        if ( array_key_exists( 'icon', $data ) ) {
            $icon = trim( strip_tags( (string) ( $data['icon'] ?? '' ) ) );

            if ( mb_strlen( $icon ) > self::MAX_LENGTHS['icon'] ) {
                throw OrderSubstatusWriteException::field( 'icon', 'too-long', __( 'Keep the icon name to :max characters or fewer.', [ 'max' => self::MAX_LENGTHS['icon'] ] ) );
            }

            $values['icon'] = '' === $icon ? null : $icon;
        }

        if ( array_key_exists( 'is_terminal', $data ) ) {
            $values['is_terminal'] = filter_var( $data['is_terminal'], FILTER_VALIDATE_BOOLEAN );
        }

        return $values;
    }

    /**
     * Flushes the registry ({@see SubStatusRegistry::flush()} flushes again
     * once the outermost transaction commits).
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function flushRegistry(): void
    {
        $this->registry->flush();
    }

    /**
     * Saves a sub-status, turning a unique-index race on the key into the
     * same `key-taken` error the up-front check gives.
     *
     * @since 1.0.0
     *
     * @param  OrderSubstatus  $substatus  Sub-status.
     *
     * @throws OrderSubstatusWriteException When another write took the key first.
     *
     * @return void
     */
    protected function save( OrderSubstatus $substatus ): void
    {
        try {
            $substatus->save();
        } catch ( UniqueConstraintViolationException $exception ) {
            throw OrderSubstatusWriteException::field( 'key', 'key-taken', __( 'Another sub-status of this order status already uses this key.' ) );
        }
    }

    /**
     * `$key`, or `$key-2`, `$key-3`, … when a sibling already uses it.
     *
     * @since 1.0.0
     *
     * @param  string              $key    Derived key.
     * @param  array<int, string>  $taken  Keys in use.
     *
     * @return string
     */
    protected static function uniqueKey( string $key, array $taken ): string
    {
        $candidate = $key;
        $n         = 2;

        while ( in_array( $candidate, $taken, true ) ) {
            $candidate = $key . '-' . $n++;
        }

        return $candidate;
    }

    /**
     * Refuses a key another sub-status of the same system status uses.
     *
     * @since 1.0.0
     *
     * @param  string    $systemStatus  System status.
     * @param  string    $key           Key.
     * @param  int|null  $ignoreId      Sub-status being updated.
     *
     * @throws OrderSubstatusWriteException When the key is taken.
     *
     * @return void
     */
    protected function assertKeyAvailable( string $systemStatus, string $key, ?int $ignoreId ): void
    {
        $taken = OrderSubstatus::query()
            ->where( 'system_status', $systemStatus )
            ->where( 'key', $key )
            ->when( null !== $ignoreId, static fn ( $query ) => $query->whereKeyNot( $ignoreId ) )
            ->exists();

        if ( $taken ) {
            throw OrderSubstatusWriteException::field( 'key', 'key-taken', __( 'Another sub-status of this order status already uses this key.' ) );
        }
    }

    /**
     * The translated "still in use" message for `$counts`.
     *
     * @since 1.0.0
     *
     * @param  array{orders: int, assignments: int, columns: int}  $counts  Reference counts.
     *
     * @return string
     */
    protected function inUseMessage( array $counts ): string
    {
        $parts = array_filter( [
            $counts['orders'] > 0 ? trans_choice( ':count order|:count orders', $counts['orders'], [ 'count' => $counts['orders'] ] ) : null,
            $counts['assignments'] > 0 ? trans_choice( ':count board card|:count board cards', $counts['assignments'], [ 'count' => $counts['assignments'] ] ) : null,
            $counts['columns'] > 0 ? trans_choice( ':count kanban column|:count kanban columns', $counts['columns'], [ 'count' => $counts['columns'] ] ) : null,
        ] );

        return __( 'This sub-status can\'t be deleted while it is in use (:usage). Move them to another sub-status first.', [
            'usage' => implode( ', ', $parts ),
        ] );
    }
}
