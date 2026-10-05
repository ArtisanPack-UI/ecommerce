<?php

/**
 * OrderNoteService.
 *
 * Adds, deletes, and lists the notes on an {@see Order}. Adding a note
 * writes a `note.added` timeline entry and deleting one writes
 * `note.deleted`, so the timeline keeps a record of notes that are gone.
 * Notes are internal unless `is_customer_visible` is set.
 *
 * Engine spec §3.18, §9.3; admin spec §8.3.
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

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderNote;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderNoteService
{
    /**
     * Longest note body accepted, in characters.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_BODY_LENGTH = 5000;

    /**
     * Characters of the body copied onto the timeline entry.
     *
     * @since 1.0.0
     *
     * @var int
     */
    protected const EXCERPT_LENGTH = 120;

    /**
     * Adds a note to `$order`.
     *
     * @since 1.0.0
     *
     * @param  Order     $order              The order.
     * @param  string    $body               Note text.
     * @param  int|null  $authorUserId       Auth user id of the author; `null` for system.
     * @param  bool      $isCustomerVisible  Whether the shopper sees it.
     *
     * @throws InvalidArgumentException When the body is blank or too long.
     *
     * @return OrderNote
     */
    public function add( Order $order, string $body, ?int $authorUserId = null, bool $isCustomerVisible = false ): OrderNote
    {
        $body = trim( $body );

        if ( '' === $body ) {
            throw new InvalidArgumentException( __( 'A note cannot be empty.' ) );
        }

        if ( mb_strlen( $body ) > self::MAX_BODY_LENGTH ) {
            throw new InvalidArgumentException( __( 'A note can be at most :max characters.', [ 'max' => self::MAX_BODY_LENGTH ] ) );
        }

        $note = DB::transaction( function () use ( $order, $body, $authorUserId, $isCustomerVisible ): OrderNote {
            $note = OrderNote::query()->create( [
                'order_id'            => $order->id,
                'author_user_id'      => $authorUserId,
                'body'                => $body,
                'is_customer_visible' => $isCustomerVisible,
            ] );

            OrderTimelineEntry::query()->create( [
                'order_id'      => $order->id,
                'actor_user_id' => $authorUserId,
                'event_type'    => 'note.added',
                'payload'       => [
                    'note_id'             => $note->id,
                    'excerpt'             => $this->excerpt( $body ),
                    'is_customer_visible' => $isCustomerVisible,
                ],
            ] );

            return $note;
        } );

        doAction( 'ap.ecommerce.order.noteAdded', $order, $note );

        return $note;
    }

    /**
     * Deletes `$note`.
     *
     * @since 1.0.0
     *
     * @param  OrderNote  $note         The note.
     * @param  int|null   $actorUserId  Auth user id deleting it; `null` for system.
     *
     * @return void
     */
    public function delete( OrderNote $note, ?int $actorUserId = null ): void
    {
        DB::transaction( function () use ( $note, $actorUserId ): void {
            OrderTimelineEntry::query()->create( [
                'order_id'      => $note->order_id,
                'actor_user_id' => $actorUserId,
                'event_type'    => 'note.deleted',
                'payload'       => [
                    'note_id' => $note->id,
                    'excerpt' => $this->excerpt( (string) $note->body ),
                ],
            ] );

            $note->delete();
        } );

        doAction( 'ap.ecommerce.order.noteDeleted', $note );
    }

    /**
     * The notes on `$order`, newest first.
     *
     * @since 1.0.0
     *
     * @param  Order  $order                The order.
     * @param  bool   $customerVisibleOnly  Only the notes the shopper sees.
     *
     * @return Collection<int, OrderNote>
     */
    public function list( Order $order, bool $customerVisibleOnly = false ): Collection
    {
        return OrderNote::query()
            ->where( 'order_id', $order->id )
            ->when( $customerVisibleOnly, static fn ( $query ) => $query->where( 'is_customer_visible', true ) )
            ->orderByDesc( 'created_at' )
            ->orderByDesc( 'id' )
            ->get();
    }

    /**
     * The first line of a note, shortened for the timeline.
     *
     * @since 1.0.0
     *
     * @param  string  $body  Note text.
     *
     * @return string
     */
    protected function excerpt( string $body ): string
    {
        return mb_strimwidth( (string) strtok( $body, "\n" ), 0, self::EXCERPT_LENGTH, '…' );
    }
}
