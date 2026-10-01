<?php

/**
 * CustomerNoteService.
 *
 * Adds, deletes, and lists internal staff notes on a {@see Customer}.
 * Adding or deleting a note records a `note.added` / `note.deleted`
 * activity entry against the customer through {@see ActivityLogService}.
 * Parent plan §10.2 item 13.
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

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\CustomerNote;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class CustomerNoteService
{
    /**
     * Characters of the note body kept in the activity entry's `excerpt`.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const EXCERPT_LENGTH = 120;

    /**
     * Longest note body accepted, in characters.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_BODY_LENGTH = 5000;

    /**
     * @since 1.0.0
     *
     * @param  ActivityLogService  $activity  Activity log.
     */
    public function __construct( protected ActivityLogService $activity )
    {
    }

    /**
     * Adds a note to `$customer`.
     *
     * Fires `ap.ecommerce.customer.noteAdded` with `(Customer $customer, CustomerNote $note)`.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer      Customer.
     * @param  string    $body          Note text.
     * @param  int|null  $authorUserId  Author's user id; `null` for system.
     *
     * @throws InvalidArgumentException When the body is empty or too long.
     *
     * @return CustomerNote
     */
    public function add( Customer $customer, string $body, ?int $authorUserId = null ): CustomerNote
    {
        $body = trim( $body );

        if ( '' === $body ) {
            throw new InvalidArgumentException( __( 'A note cannot be empty.' ) );
        }

        if ( mb_strlen( $body ) > self::MAX_BODY_LENGTH ) {
            throw new InvalidArgumentException( __( 'A note can be at most :max characters.', [ 'max' => self::MAX_BODY_LENGTH ] ) );
        }

        $note = DB::transaction( function () use ( $customer, $body, $authorUserId ): CustomerNote {
            $note = CustomerNote::query()->create( [
                'customer_id'    => $customer->id,
                'author_user_id' => $authorUserId,
                'body'           => $body,
            ] );

            $this->activity->record( $customer, 'note.added', [
                'note_id' => (int) $note->id,
                'excerpt' => $this->excerpt( $body ),
            ], $authorUserId );

            return $note;
        } );

        doAction( 'ap.ecommerce.customer.noteAdded', $customer, $note );

        return $note;
    }

    /**
     * Deletes `$note`.
     *
     * Fires `ap.ecommerce.customer.noteDeleted` with `(CustomerNote $note)` (already deleted).
     *
     * @since 1.0.0
     *
     * @param  CustomerNote  $note         Note.
     * @param  int|null      $actorUserId  Acting user id; `null` for system.
     *
     * @return void
     */
    public function delete( CustomerNote $note, ?int $actorUserId = null ): void
    {
        $customer = $note->customer;

        DB::transaction( function () use ( $note, $customer, $actorUserId ): void {
            $note->delete();

            if ( null !== $customer ) {
                $this->activity->record( $customer, 'note.deleted', [
                    'note_id' => (int) $note->id,
                    'excerpt' => $this->excerpt( (string) $note->body ),
                ], $actorUserId );
            }
        } );

        doAction( 'ap.ecommerce.customer.noteDeleted', $note );
    }

    /**
     * The customer's notes, newest first.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer.
     *
     * @return Collection<int, CustomerNote>
     */
    public function list( Customer $customer ): Collection
    {
        return CustomerNote::query()
            ->where( 'customer_id', $customer->id )
            ->orderByDesc( 'created_at' )
            ->orderByDesc( 'id' )
            ->get();
    }

    /**
     * A one-line excerpt of a note body.
     *
     * @since 1.0.0
     *
     * @param  string  $body  Body.
     *
     * @return string
     */
    protected function excerpt( string $body ): string
    {
        return Str::limit( (string) preg_replace( '/\s+/', ' ', $body ), self::EXCERPT_LENGTH );
    }
}
