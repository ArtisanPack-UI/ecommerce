<?php

/**
 * OrderSubstatusWriteException.
 *
 * Thrown by {@see \ArtisanPackUI\Ecommerce\Services\OrderSubstatusService}
 * when a sub-status write breaks a rule: an unknown system status, a taken
 * key, a malformed colour, a reorder list with foreign ids, or a delete
 * while orders, board cards, or kanban columns still use the sub-status.
 * It carries field-level errors in the engine's `{ field, code, message }`
 * shape so REST, GraphQL, and admin forms can point at the offending input.
 *
 * An in-use refusal also carries `counts` (`orders`, `assignments`,
 * `columns`) in its context so callers can explain what blocks the delete.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Exceptions;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.0.0
 */
class OrderSubstatusWriteException extends EcommerceException
{
    /**
     * @since 1.0.0
     *
     * @param  array<int, array{field: string|null, code: string, message: string, counts?: array<string, int>}>  $errors  Field-level errors; the in-use refusal also carries `counts`.
     * @param  array{orders: int, assignments: int, columns: int}|null                 $counts  What still references the sub-status, for in-use refusals.
     */
    public function __construct( public readonly array $errors, public readonly ?array $counts = null )
    {
        parent::__construct(
            (string) ( $errors[0]['message'] ?? __( 'The sub-status change was refused.' ) ),
            array_filter( [ 'errors' => $errors, 'counts' => $counts ], static fn ( $value ): bool => null !== $value ),
        );
    }

    /**
     * One error against one field.
     *
     * @since 1.0.0
     *
     * @param  string|null  $field    Offending input, or null for the whole write.
     * @param  string       $code     Machine-readable code.
     * @param  string       $message  Translated message.
     *
     * @return self
     */
    public static function field( ?string $field, string $code, string $message ): self
    {
        return new self( [ [ 'field' => $field, 'code' => $code, 'message' => $message ] ] );
    }

    /**
     * A delete refused because the sub-status is still referenced.
     *
     * @since 1.0.0
     *
     * @param  array{orders: int, assignments: int, columns: int}  $counts   Reference counts.
     * @param  string                                              $message  Translated message.
     *
     * @return self
     */
    public static function inUse( array $counts, string $message ): self
    {
        return new self( [ [ 'field' => null, 'code' => 'substatus-in-use', 'message' => $message, 'counts' => $counts ] ], $counts );
    }
}
