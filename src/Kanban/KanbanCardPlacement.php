<?php

/**
 * KanbanCardPlacement.
 *
 * Where a card lands in its column: at the top, at the bottom, or just
 * before / after another card (ArtisanPack-UI/ecommerce#198). Pass one to
 * {@see \ArtisanPackUI\Ecommerce\Services\KanbanBoardService::move()}; a
 * same-column move with a placement reorders the card.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.2.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\Ecommerce\Kanban;

/**
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @since      1.2.0
 */
final class KanbanCardPlacement
{
    /**
     * @since 1.2.0
     *
     * @var string
     */
    public const TOP = 'top';

    /**
     * @since 1.2.0
     *
     * @var string
     */
    public const BOTTOM = 'bottom';

    /**
     * @since 1.2.0
     *
     * @var string
     */
    public const BEFORE = 'before';

    /**
     * @since 1.2.0
     *
     * @var string
     */
    public const AFTER = 'after';

    /**
     * @since 1.2.0
     *
     * @param  string    $mode     One of the class constants.
     * @param  int|null  $orderId  The neighbouring card's order, for `before` / `after`.
     */
    private function __construct( public readonly string $mode, public readonly ?int $orderId = null )
    {
    }

    /**
     * The top of the column.
     *
     * @since 1.2.0
     *
     * @return self
     */
    public static function top(): self
    {
        return new self( self::TOP );
    }

    /**
     * The bottom of the column.
     *
     * @since 1.2.0
     *
     * @return self
     */
    public static function bottom(): self
    {
        return new self( self::BOTTOM );
    }

    /**
     * Just above `$orderId`'s card.
     *
     * @since 1.2.0
     *
     * @param  int  $orderId  The card below the placed one.
     *
     * @return self
     */
    public static function before( int $orderId ): self
    {
        return new self( self::BEFORE, $orderId );
    }

    /**
     * Just below `$orderId`'s card.
     *
     * @since 1.2.0
     *
     * @param  int  $orderId  The card above the placed one.
     *
     * @return self
     */
    public static function after( int $orderId ): self
    {
        return new self( self::AFTER, $orderId );
    }

    /**
     * The `top` / `bottom` end named by `$end`; anything else is the bottom.
     *
     * @since 1.2.0
     *
     * @param  mixed  $end  `top` or `bottom`.
     *
     * @return self
     */
    public static function end( mixed $end ): self
    {
        return self::TOP === $end ? self::top() : self::bottom();
    }

    /**
     * Reads a placement from its array form ({@see self::toArray()}), as
     * returned by an `ap.ecommerce.kanban.cardMoving` filter.
     *
     * @since 1.2.0
     *
     * @param  mixed  $value  `[ 'mode' => …, 'order_id' => … ]`, or anything else.
     *
     * @return self|null Null when `$value` isn't a valid placement.
     */
    public static function fromArray( mixed $value ): ?self
    {
        if ( ! is_array( $value ) ) {
            return null;
        }

        $mode    = $value['mode'] ?? null;
        $orderId = $value['order_id'] ?? null;

        return match ( true ) {
            self::TOP === $mode                                       => self::top(),
            self::BOTTOM === $mode                                    => self::bottom(),
            self::BEFORE === $mode && is_numeric( $orderId )          => self::before( (int) $orderId ),
            self::AFTER === $mode && is_numeric( $orderId )           => self::after( (int) $orderId ),
            default                                                   => null,
        };
    }

    /**
     * @since 1.2.0
     *
     * @return array{mode: string, order_id: int|null}
     */
    public function toArray(): array
    {
        return [ 'mode' => $this->mode, 'order_id' => $this->orderId ];
    }
}
