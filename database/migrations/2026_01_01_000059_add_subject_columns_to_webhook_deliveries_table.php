<?php

/**
 * Adds `order_id` and `customer_id` to `webhook_deliveries` (engine issue
 * #142).
 *
 * The order and customer a delivery is about, taken from its payload when
 * the delivery is queued ({@see ArtisanPackUI\Ecommerce\Services\WebhookDispatcher::subjectIds()}).
 * Customer delete-and-anonymize uses them to find the deliveries to redact
 * through an index instead of decoding every payload. No foreign keys:
 * delivery history outlives the rows it describes.
 *
 * Existing rows are backfilled from their payloads.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table( 'webhook_deliveries', function ( Blueprint $table ): void {
            $table->unsignedBigInteger( 'order_id' )->nullable()->after( 'event' );
            $table->unsignedBigInteger( 'customer_id' )->nullable()->after( 'order_id' );

            $table->index( 'order_id', 'webhook_deliveries_order_idx' );
            $table->index( 'customer_id', 'webhook_deliveries_customer_idx' );
        } );

        DB::table( 'webhook_deliveries' )->select( [ 'id', 'payload' ] )->orderBy( 'id' )->chunkById( 500, function ( $rows ): void {
            foreach ( $rows as $row ) {
                $payload = is_string( $row->payload ) ? json_decode( $row->payload, true ) : null;

                if ( ! is_array( $payload ) ) {
                    continue;
                }

                $subjects = $this->subjectIds( $payload );

                if ( null !== $subjects['order_id'] || null !== $subjects['customer_id'] ) {
                    DB::table( 'webhook_deliveries' )->where( 'id', $row->id )->update( $subjects );
                }
            }
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table( 'webhook_deliveries', function ( Blueprint $table ): void {
            $table->dropIndex( 'webhook_deliveries_order_idx' );
            $table->dropIndex( 'webhook_deliveries_customer_idx' );
            $table->dropColumn( [ 'order_id', 'customer_id' ] );
        } );
    }

    /**
     * The order and customer a payload is about. A frozen copy of
     * `WebhookDispatcher::subjectIds()` as of this migration, so later
     * changes to the dispatcher don't change what the backfill did.
     *
     * @since 1.0.0
     *
     * @param  array<array-key, mixed>  $payload  Delivery payload.
     *
     * @return array{order_id: int|null, customer_id: int|null}
     */
    private function subjectIds( array $payload ): array
    {
        $found = [ 'order_id' => null, 'customer_id' => null ];
        $queue = [ is_array( $payload['data'] ?? null ) ? $payload['data'] : $payload ];

        while ( [] !== $queue && ( null === $found['order_id'] || null === $found['customer_id'] ) ) {
            $node = array_shift( $queue );
            $type = $node['type'] ?? null;
            $id   = is_numeric( $node['id'] ?? null ) ? (int) $node['id'] : null;

            foreach ( [ 'order' => 'order_id', 'customer' => 'customer_id' ] as $kind => $column ) {
                if ( null !== $found[ $column ] ) {
                    continue;
                }

                if ( $kind === $type && null !== $id ) {
                    $found[ $column ] = $id;
                } elseif ( is_numeric( $node[ $column ] ?? null ) ) {
                    $found[ $column ] = (int) $node[ $column ];
                }
            }

            foreach ( $node as $value ) {
                if ( is_array( $value ) ) {
                    $queue[] = $value;
                }
            }
        }

        return $found;
    }
};
